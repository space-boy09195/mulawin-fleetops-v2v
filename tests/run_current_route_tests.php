<?php
declare(strict_types=1);
require __DIR__.'/_launcher.php';
// New uniquely owned database only; refuse reuse, disable database-drop cleanup.
exit(run_isolated_tests('current_route_tests.php','mulawin_route_current',['fresh'],'2026-11-13_13_fixed_route_fuel',[],true));
