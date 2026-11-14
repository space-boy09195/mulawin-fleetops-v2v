<?php
/*
 * =====================================================================
 *  tests/run_dispatch_attendance_tests.php  —  integrated attendance rules at Confirm Dispatch
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_dispatch_attendance_tests.php
 *  Drops and re-creates ONLY: mulawin_datt_fresh_test.  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('dispatch_attendance_tests.php', 'mulawin_datt', ['fresh'], '2026-11-14_14_incident_clearance'));
