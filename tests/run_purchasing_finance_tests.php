<?php
/*
 * =====================================================================
 *  tests/run_purchasing_finance_tests.php  —  Purchasing -> Finance money integrity (G5 / G7 / G8)
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_purchasing_finance_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_purchasing_finance_tests.php)
 *  Drops and re-creates ONLY: mulawin_pf_fresh_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('purchasing_finance_tests.php', 'mulawin_pf', ['fresh'], '2026-11-12_12_job_helper_mechanics'));
