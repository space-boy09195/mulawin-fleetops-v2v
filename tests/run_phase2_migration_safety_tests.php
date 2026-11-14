<?php
/*
 *  tests/run_phase2_migration_safety_tests.php  —  Phase 2 migrations 2026-11-10_10 / _11: record-last, partial failure, recovery
 *  RUN:  TEST_DB_USER=fleet_test TEST_DB_PASS=secret php tests/run_phase2_migration_safety_tests.php
 *  Drops and re-creates ONLY: mulawin_p2mig_fresh_test and mulawin_p2mig_upgrade_test.
 */
declare(strict_types=1);
require __DIR__ . '/_launcher.php';
exit(run_isolated_tests('phase2_migration_safety_tests.php', 'mulawin_p2mig', ['fresh', 'upgrade'], '2026-11-08_08_attendance'));
