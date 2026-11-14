<?php
/*
 *  tests/run_uat_browser_scenario.php  —  builds the browser-UAT data set (see uat_browser_scenario.php) and KEEPS the database
 *  RUN:  TEST_DB_PORT=3307 TEST_DB_USER=... TEST_DB_PASS=... php tests/run_uat_browser_scenario.php
 *  Creates ONLY: mulawin_uatui_<random>_fresh_test. Users uat_<role> get password_hash 'x' (cannot sign in):
 *  set a test-only password on that *_test database before browser use, and drop the database afterwards.
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('uat_browser_scenario.php', 'mulawin_uatui', ['fresh'], '2026-11-14_14_incident_clearance', [], true));
