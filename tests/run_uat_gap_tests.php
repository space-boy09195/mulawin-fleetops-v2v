<?php
/*
 *  tests/run_uat_gap_tests.php  —  actions without another automated check (see uat_gap_tests.php)
 *  RUN:  TEST_DB_USER=... TEST_DB_PASS=... php tests/run_uat_gap_tests.php     Drops and re-creates ONLY: mulawin_uatgap_fresh_test
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('uat_gap_tests.php', 'mulawin_uatgap', ['fresh'], '2026-11-14_14_incident_clearance'));
