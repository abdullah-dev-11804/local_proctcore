<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Verifies that every supported language remains complete and placeholder-compatible. */
final class language_strings_test extends \advanced_testcase {
    public function test_supported_language_files_match_english(): void {
        $english = $this->load_strings('en');
        $englishkeys = array_keys($english);
        sort($englishkeys);
        foreach (['ru', 'kk'] as $lang) {
            $translated = $this->load_strings($lang);
            $translatedkeys = array_keys($translated);
            sort($translatedkeys);
            $this->assertSame($englishkeys, $translatedkeys, "Language-key mismatch for {$lang}");
            foreach ($english as $key => $value) {
                $this->assertSame(
                    $this->placeholders($value),
                    $this->placeholders($translated[$key]),
                    "Placeholder mismatch for {$lang}: {$key}"
                );
            }
        }
    }

    /** @return array<string, string> */
    private function load_strings(string $lang): array {
        $string = [];
        include dirname(__DIR__) . '/lang/' . $lang . '/local_proctorcore.php';
        return $string;
    }

    /** @return string[] */
    private function placeholders(string $value): array {
        preg_match_all('/\{\$a(?:->\w+)?\}/', $value, $matches);
        sort($matches[0]);
        return $matches[0];
    }
}
