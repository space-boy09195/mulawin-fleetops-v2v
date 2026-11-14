<?php
/*
 * =====================================================================
 *  tests/run_motorpool_workspace_tests.php  —  Motorpool workspace Phase 1 (Parts Requests + Mechanic Attendance pages)
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_motorpool_workspace_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_motorpool_workspace_tests.php)
 *  Drops and re-creates ONLY: mulawin_mpws_fresh_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('motorpool_workspace_tests.php', 'mulawin_mpws', ['fresh'], '2026-11-08_08_attendance'));
