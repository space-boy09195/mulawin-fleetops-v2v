<?php
/*
 * =====================================================================
 *  tests/run_incident_clearance_tests.php  —  Motorpool vehicle clearance for trip incidents (migration 14)
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_incident_clearance_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_incident_clearance_tests.php)
 *  Drops and re-creates ONLY: mulawin_clear_fresh_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('incident_clearance_tests.php', 'mulawin_clear', ['fresh'], '2026-11-14_14_incident_clearance'));
