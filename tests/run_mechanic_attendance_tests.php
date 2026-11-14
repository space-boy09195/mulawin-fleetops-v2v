<?php
/*
 * =====================================================================
 *  tests/run_mechanic_attendance_tests.php  —  Mechanic Attendance (Time In / Time Out) + dispatcher-only account shifts
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_mechanic_attendance_tests.php
 *  Drops and re-creates ONLY: mulawin_mechatt_fresh_test  (and ..._upgrade_test for the migration check)
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('mechanic_attendance_tests.php', 'mulawin_mechatt', ['fresh', 'upgrade'], '2026-11-08_08_attendance'));
