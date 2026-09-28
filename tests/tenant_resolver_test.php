<?php
// This file is part of Moodle - http://moodle.org/

namespace local_proctorcore;

defined('MOODLE_INTERNAL') || die();

/** Tests IOMAD direct, open-shared, and closed-shared course access. */
final class tenant_resolver_test extends \advanced_testcase {
    /** Verifies the legacy IOMAD 4.x schema used by current deployments. */
    public function test_legacy_iomad_course_sharing_is_respected(): void {
        $this->run_sharing_scenarios(false);
    }

    /** Verifies the local_iomad-prefixed schema introduced by IOMAD 5.1. */
    public function test_current_iomad_course_sharing_is_respected(): void {
        $this->run_sharing_scenarios(true);
    }

    /**
     * Creates a minimal IOMAD schema and runs the common access scenarios.
     *
     * @param bool $current Whether to use IOMAD 5.1 table names.
     * @return void
     */
    private function run_sharing_scenarios(bool $current): void {
        global $DB;

        $tables = $current ? [
            'users' => 'local_iomad_company_users',
            'courses' => 'local_iomad_company_courses',
            'settings' => 'local_iomad_courses',
            'shared' => 'local_iomad_company_shared_courses',
        ] : [
            'users' => 'company_users',
            'courses' => 'company_course',
            'settings' => 'iomad_courses',
            'shared' => 'company_shared_courses',
        ];
        $dbman = $DB->get_manager();
        foreach ($tables as $tablename) {
            if ($dbman->table_exists(new \xmldb_table($tablename))) {
                $this->markTestSkipped('The test cannot replace an installed IOMAD schema.');
            }
        }

        try {
            $this->create_mapping_table($tables['users'], 'userid');
            $this->create_mapping_table($tables['courses'], 'courseid');
            $this->create_course_settings_table($tables['settings']);
            $this->create_mapping_table($tables['shared'], 'courseid');

            $DB->insert_record($tables['users'], (object) ['companyid' => 20, 'userid' => 123]);
            $DB->insert_record($tables['courses'], (object) ['companyid' => 10, 'courseid' => 77]);
            $settingsid = $DB->insert_record($tables['settings'], (object) ['courseid' => 77, 'shared' => 0]);
            $resolver = new \local_proctorcore\local\tenant_resolver();

            $this->assertFalse($resolver->is_course_available_to_company(77, 20));
            $this->assertTrue($resolver->is_course_available_to_company(77, 10));

            $DB->set_field($tables['settings'], 'shared', 1, ['id' => $settingsid]);
            $this->assertTrue($resolver->is_course_available_to_company(77, 20));
            $this->assertSame(20, $resolver->resolve_company_id(123, 77));

            $DB->set_field($tables['settings'], 'shared', 2, ['id' => $settingsid]);
            $this->assertFalse($resolver->is_course_available_to_company(77, 20));
            $DB->insert_record($tables['shared'], (object) ['companyid' => 20, 'courseid' => 77]);
            $this->assertTrue($resolver->is_course_available_to_company(77, 20));
            $this->assertSame(20, $resolver->resolve_company_id(123, 77));

            $this->assertTrue($resolver->is_course_available_to_company(88, 20));
        } finally {
            foreach (array_reverse($tables) as $tablename) {
                $table = new \xmldb_table($tablename);
                if ($dbman->table_exists($table)) {
                    $dbman->drop_table($table);
                }
            }
        }
    }

    /**
     * Creates a minimal company mapping table.
     *
     * @param string $tablename Table name without Moodle prefix.
     * @param string $itemfield User or course id field name.
     * @return void
     */
    private function create_mapping_table(string $tablename, string $itemfield): void {
        global $DB;
        $table = new \xmldb_table($tablename);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('companyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field($itemfield, XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);
    }

    /**
     * Creates a minimal IOMAD course-settings table.
     *
     * @param string $tablename Table name without Moodle prefix.
     * @return void
     */
    private function create_course_settings_table(string $tablename): void {
        global $DB;
        $table = new \xmldb_table($tablename);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $table->add_field('shared', XMLDB_TYPE_INTEGER, '1', null, null, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $DB->get_manager()->create_table($table);
    }
}
