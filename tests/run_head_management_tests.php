<?php
/*
 * =====================================================================
 *  tests/run_head_management_tests.php  —  Head Management (Admin) workspace: access, approval queue, decisions, figures
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_head_management_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_head_management_tests.php)
 *  Drops and re-creates ONLY: mulawin_hm_fresh_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('head_management_tests.php', 'mulawin_hm', ['fresh'], '2026-11-12_13_stock_count_reconciliation'));
