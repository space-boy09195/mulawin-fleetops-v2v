<?php
/*
 *  tests/run_role_action_probe.php  —  role x action permission probe (writes JSON evidence; see role_action_probe.php)
 *  RUN:  TEST_DB_USER=... TEST_DB_PASS=... PROBE_OUT=C:\path\probe.json php tests/run_role_action_probe.php
 *  Drops and re-creates ONLY: mulawin_probe_fresh_test
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('role_action_probe.php', 'mulawin_probe', ['fresh'], '2026-11-14_14_incident_clearance', ['PROBE_OUT' => (string)getenv('PROBE_OUT')]));
