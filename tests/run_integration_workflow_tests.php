<?php
/*
 * =====================================================================
 *  tests/run_integration_workflow_tests.php  —  cross-department workflow after the luis + kenneth integration
 * =====================================================================
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_integration_workflow_tests.php
 *        (XAMPP: set TEST_DB_USER=root / set TEST_DB_PASS= / C:\xampp\php\php.exe tests\run_integration_workflow_tests.php)
 *  Drops and re-creates ONLY: mulawin_e2e_fresh_test
 *  Exit code 0 = every test passed.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('integration_workflow_tests.php', 'mulawin_e2e', ['fresh'], '2026-11-13_13_fixed_route_fuel'));
