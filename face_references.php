<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/proctorcore:resetfaceenrolment', $context);

$search = trim(optional_param('q', '', PARAM_TEXT));
$status = optional_param('status', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 25;
$allowedstatuses = ['', 'active', 'reset', 'deleted'];
if (!in_array($status, $allowedstatuses, true)) {
    $status = '';
}

$pageurl = new moodle_url('/local/proctorcore/face_references.php', array_filter([
    'q' => $search,
    'status' => $status,
]));
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('identity:managereferences', 'local_proctorcore'));
$PAGE->set_heading(get_string('identity:managereferences', 'local_proctorcore'));

$conditions = ['1 = 1'];
$params = [];
if ($status !== '') {
    $conditions[] = 'f.status = :referencestatus';
    $params['referencestatus'] = $status;
}
if ($search !== '') {
    $needle = '%' . $DB->sql_like_escape($search) . '%';
    $searchconditions = [
        $DB->sql_like('u.firstname', ':referencefirstname', false),
        $DB->sql_like('u.lastname', ':referencelastname', false),
        $DB->sql_like('u.username', ':referenceusername', false),
        $DB->sql_like('u.email', ':referenceemail', false),
    ];
    foreach (['referencefirstname', 'referencelastname', 'referenceusername', 'referenceemail'] as $name) {
        $params[$name] = $needle;
    }
    if (ctype_digit($search)) {
        $searchconditions[] = 'u.id = :referenceuserid';
        $params['referenceuserid'] = (int) $search;
    }
    $conditions[] = '(' . implode(' OR ', $searchconditions) . ')';
}
$where = implode(' AND ', $conditions);
$from = ' FROM {local_proctorcore_faceenrol} f JOIN {user} u ON u.id = f.userid';
$total = $DB->count_records_sql('SELECT COUNT(1)' . $from . ' WHERE ' . $where, $params);
$records = $DB->get_records_sql(
    'SELECT f.*, u.firstname, u.lastname, u.firstnamephonetic, u.lastnamephonetic,'
        . ' u.middlename, u.alternatename, u.username, u.email, u.deleted AS userdeleted'
        . $from . ' WHERE ' . $where . ' ORDER BY f.timemodified DESC, f.id DESC',
    $params,
    $page * $perpage,
    $perpage
);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('identity:managereferences', 'local_proctorcore'));
echo html_writer::tag('p', get_string('identity:managereferencesdesc', 'local_proctorcore'));
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'mb-4 form-inline']);
echo html_writer::empty_tag('input', [
    'type' => 'search',
    'name' => 'q',
    'value' => $search,
    'placeholder' => get_string('identity:referencesearch', 'local_proctorcore'),
    'class' => 'form-control mr-2 mb-2',
    'size' => 42,
]);
echo html_writer::select([
    '' => get_string('identity:allstatuses', 'local_proctorcore'),
    'active' => get_string('identity:statusactive', 'local_proctorcore'),
    'reset' => get_string('identity:statusreset', 'local_proctorcore'),
    'deleted' => get_string('identity:statusdeleted', 'local_proctorcore'),
], 'status', $status, false, ['class' => 'custom-select mr-2 mb-2']);
echo html_writer::tag('button', get_string('search'), [
    'type' => 'submit',
    'class' => 'btn btn-secondary mb-2',
]);
echo html_writer::end_tag('form');

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->head = [
    get_string('identity:referenceuser', 'local_proctorcore'),
    get_string('email'),
    get_string('identity:referencecompany', 'local_proctorcore'),
    get_string('identity:referencestatus', 'local_proctorcore'),
    get_string('identity:referenceenrolled', 'local_proctorcore'),
    get_string('identity:referencedeletion', 'local_proctorcore'),
    get_string('actions'),
];
$statuslabels = [
    'active' => get_string('identity:statusactive', 'local_proctorcore'),
    'reset' => get_string('identity:statusreset', 'local_proctorcore'),
    'deleted' => get_string('identity:statusdeleted', 'local_proctorcore'),
];
$deletionlabels = [
    'none' => get_string('identity:deletionnone', 'local_proctorcore'),
    'pending' => get_string('identity:deletionpending', 'local_proctorcore'),
    'retry' => get_string('identity:deletionretry', 'local_proctorcore'),
    'completed' => get_string('identity:deletioncompleted', 'local_proctorcore'),
];
foreach ($records as $record) {
    $userurl = new moodle_url('/user/profile.php', ['id' => (int) $record->userid]);
    $username = fullname($record) . ' (' . (string) $record->username . ', ID ' . (int) $record->userid . ')';
    $actions = [];
    $deletionstatus = (string) ($record->deletionstatus ?? 'none');
    if ((string) $record->status === 'active' && $deletionstatus === 'none') {
        $baseaction = [
            'userid' => (int) $record->userid,
            'returntomanager' => 1,
        ];
        $actions[] = html_writer::link(
            new moodle_url('/local/proctorcore/reset_face.php', $baseaction + ['action' => 'reset']),
            get_string('identity:resetaction', 'local_proctorcore')
        );
        $actions[] = html_writer::link(
            new moodle_url('/local/proctorcore/reset_face.php', $baseaction + ['action' => 'delete']),
            get_string('identity:deleteaction', 'local_proctorcore'),
            ['class' => 'text-danger']
        );
    }
    $table->data[] = [
        html_writer::link($userurl, s($username)),
        s((string) $record->email),
        (int) $record->companyid,
        s($statuslabels[(string) $record->status] ?? (string) $record->status),
        !empty($record->enrolledat) ? userdate((int) $record->enrolledat) : '—',
        s($deletionlabels[$deletionstatus] ?? $deletionstatus),
        $actions ? implode(' | ', $actions) : '—',
    ];
}

if ($records) {
    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('identity:noreferences', 'local_proctorcore'), 'info', false);
}
echo $OUTPUT->paging_bar($total, $page, $perpage, $pageurl);
echo $OUTPUT->footer();
