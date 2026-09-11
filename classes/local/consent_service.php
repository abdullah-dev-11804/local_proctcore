<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore\local;

defined('MOODLE_INTERNAL') || die();

/** Versioned, append-only consent workflow required before normal site access. */
final class consent_service {
    /** @return \stdClass[] Active required documents joined to their current immutable version. */
    public function get_required_documents(): array {
        global $DB;
        $sql = "SELECT v.*, d.shortname, d.sortorder
                  FROM {local_proctorcore_consentdoc} d
                  JOIN {local_proctorcore_consentver} v
                    ON v.documentid = d.id AND v.version = d.currentversion
                 WHERE d.active = 1 AND d.required = 1 AND d.currentversion > 0
              ORDER BY d.sortorder, d.id";
        return array_values($DB->get_records_sql($sql));
    }

    /** @param \stdClass[] $documents @return string Stable hash of exact required versions. */
    public function documents_hash(array $documents): string {
        $manifest = [];
        foreach ($documents as $document) {
            $manifest[] = [
                'documentId' => (int) $document->documentid,
                'shortname' => (string) $document->shortname,
                'version' => (int) $document->version,
                'contentHash' => (string) $document->contenthash,
            ];
        }
        return hash('sha256', json_encode($manifest, JSON_UNESCAPED_SLASHES));
    }

    /** Returns whether the user accepted the exact currently-required document set. */
    public function has_current_consent(int $userid): bool {
        global $DB;
        $documents = $this->get_required_documents();
        if (!$documents) {
            return true;
        }
        $logs = $DB->get_records('local_proctorcore_consentlog', ['userid' => $userid], 'consentedat DESC, id DESC');
        foreach ($logs as $log) {
            $manifest = json_decode((string) $log->documentversions, true);
            if (!is_array($manifest)) {
                continue;
            }
            $accepted = [];
            foreach ($manifest as $item) {
                $accepted[(int) ($item['documentId'] ?? 0)] = (int) ($item['version'] ?? 0);
            }
            $valid = true;
            foreach ($documents as $document) {
                $requiredversion = (int) $DB->get_field_sql(
                    "SELECT COALESCE(MAX(version), 1)
                       FROM {local_proctorcore_consentver}
                      WHERE documentid = :documentid AND materialchange = 1",
                    ['documentid' => (int) $document->documentid]
                );
                if (($accepted[(int) $document->documentid] ?? 0) < $requiredversion) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                return true;
            }
        }
        return false;
    }

    /** Records one immutable acceptance event after revalidating the presented document set. */
    public function accept(int $userid, string $presentedhash, string $language): int {
        global $DB;
        if ($this->is_impersonating()) {
            throw new \moodle_exception('consent:errorimpersonating', 'local_proctorcore');
        }
        $documents = $this->get_required_documents();
        if (!$documents) {
            throw new \moodle_exception('consent:nodocuments', 'local_proctorcore');
        }
        $hash = $this->documents_hash($documents);
        if (!hash_equals($hash, $presentedhash)) {
            throw new \moodle_exception('consent:documentschanged', 'local_proctorcore');
        }
        $manifest = [];
        foreach ($documents as $document) {
            $manifest[] = [
                'documentId' => (int) $document->documentid,
                'shortname' => (string) $document->shortname,
                'version' => (int) $document->version,
                'contentHash' => (string) $document->contenthash,
            ];
        }
        if ($DB->record_exists('local_proctorcore_consentlog', ['userid' => $userid, 'documentshash' => $hash])) {
            return (int) $DB->get_field('local_proctorcore_consentlog', 'id', [
                'userid' => $userid, 'documentshash' => $hash,
            ], MUST_EXIST);
        }
        try {
            $companyid = (new tenant_resolver())->resolve_company_id($userid);
        } catch (\Throwable $exception) {
            $companyid = 0;
        }
        return (int) $DB->insert_record('local_proctorcore_consentlog', (object) [
            'userid' => $userid,
            'companyid' => $companyid,
            'language' => clean_param($language, PARAM_LANG),
            'documentversions' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'documentshash' => $hash,
            'ipaddress' => getremoteaddr() ?: null,
            'useragent' => clean_param($_SERVER['HTTP_USER_AGENT'] ?? '', PARAM_TEXT),
            'consentedat' => time(),
        ]);
    }

    /** Publishes a new immutable version and optionally activates the document. */
    public function publish(array $data, int $actorid): \stdClass {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        $documentid = (int) ($data['documentid'] ?? 0);
        if ($documentid) {
            $document = $DB->get_record('local_proctorcore_consentdoc', ['id' => $documentid], '*', MUST_EXIST);
        } else {
            $document = (object) [
                'shortname' => clean_param((string) $data['shortname'], PARAM_ALPHANUMEXT),
                'timecreated' => $now,
                'currentversion' => 0,
            ];
        }
        $document->shortname = clean_param((string) $data['shortname'], PARAM_ALPHANUMEXT);
        $document->active = empty($data['active']) ? 0 : 1;
        $document->required = empty($data['required']) ? 0 : 1;
        $document->sortorder = max(0, (int) ($data['sortorder'] ?? 0));
        $document->timemodified = $now;
        $document->usermodified = $actorid;
        if ($documentid) {
            $DB->update_record('local_proctorcore_consentdoc', $document);
        } else {
            $documentid = (int) $DB->insert_record('local_proctorcore_consentdoc', $document);
            $document->id = $documentid;
        }

        $content = [];
        foreach (['kk', 'ru', 'en'] as $lang) {
            $content['title' . $lang] = trim((string) $data['title' . $lang]);
            $content['content' . $lang] = clean_text((string) $data['content' . $lang], FORMAT_HTML);
        }
        $version = ((int) $document->currentversion) + 1;
        $versionrecord = (object) array_merge($content, [
            'documentid' => $documentid,
            'version' => $version,
            'materialchange' => empty($data['materialchange']) ? 0 : 1,
            'contenthash' => hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'publishedat' => $now,
            'publishedby' => $actorid,
        ]);
        $DB->insert_record('local_proctorcore_consentver', $versionrecord);
        $DB->set_field('local_proctorcore_consentdoc', 'currentversion', $version, ['id' => $documentid]);
        $transaction->allow_commit();
        return $DB->get_record('local_proctorcore_consentdoc', ['id' => $documentid], '*', MUST_EXIST);
    }

    /** Localises one document using the required kk, ru, en fallback order. */
    public function localise(\stdClass $document, string $language): array {
        $short = substr(strtolower($language), 0, 2);
        $order = in_array($short, ['kk', 'ru', 'en'], true) ? [$short, 'kk', 'ru', 'en'] : ['kk', 'ru', 'en'];
        foreach (array_unique($order) as $lang) {
            if (trim((string) ($document->{'title' . $lang} ?? '')) !== '') {
                return [
                    'title' => (string) $document->{'title' . $lang},
                    'content' => (string) $document->{'content' . $lang},
                ];
            }
        }
        return ['title' => (string) $document->shortname, 'content' => ''];
    }

    /** Moodle sets realuser while an administrator is using Log in as. */
    public function is_impersonating(): bool {
        global $SESSION, $USER;
        return !empty($USER->realuser) || !empty($SESSION->realuser);
    }
}
