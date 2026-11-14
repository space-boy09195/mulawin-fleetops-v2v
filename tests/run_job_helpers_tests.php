<?php
/*
 * =====================================================================
 *  tests/run_job_helpers_tests.php  —  more than one mechanic per repair (migration 12)
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_job_helpers_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_job_helpers_tests.php)
 *  Drops and re-creates ONLY: mulawin_jh_fresh_test and mulawin_jh_reference_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('job_helpers_tests.php', 'mulawin_jh', ['fresh', 'reference'], '2026-11-12_12_job_helper_mechanics'));
