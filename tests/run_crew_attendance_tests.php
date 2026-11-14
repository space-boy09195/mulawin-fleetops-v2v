<?php
/*
 * =====================================================================
 *  tests/run_crew_attendance_tests.php  —  Checklist #42 daily attendance (no permanent shifts)
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_crew_attendance_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_crew_attendance_tests.php)
 *  Drops and re-creates ONLY: mulawin_crewatt_fresh_test and mulawin_crewatt_upgrade_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('crew_attendance_tests.php', 'mulawin_crewatt', ['fresh', 'upgrade'], '2026-11-08_08_attendance'));
