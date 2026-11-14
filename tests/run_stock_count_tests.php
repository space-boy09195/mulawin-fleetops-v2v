<?php
/*
 * =====================================================================
 *  tests/run_stock_count_tests.php  —  Inventory control: no direct adjustments; stock count -> Admin review -> posting
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_stock_count_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_stock_count_tests.php)
 *  Drops and re-creates ONLY: mulawin_sc_fresh_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('stock_count_tests.php', 'mulawin_sc', ['fresh'], '2026-11-12_13_stock_count_reconciliation'));
