<?php
/**
 * Isolated MariaDB scenarios. Run only through run_trip_approval_tests.php.
 *
 * The Dispatcher trip-request -> Operations Head review queue was RETIRED by kenneth (Trips::review answers 409;
 * Dispatchers cannot create trips). This suite replaces the old queue tests with the current guarantees:
 *   A. no new request can be created (Dispatchers of both groups) and review is retired for every role;
 *   B. legacy "Pending Review" records stay read-only history (only the Operations Head legacy view lists them;
 *      they cannot be edited, assigned or dispatched);
 *   C. the Operations Head plans directly: Awaiting Resources, no automatic resources, actor recorded.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
require_once __DIR__ . '/_launcher.php';
$fresh = envValue('DB_NAME');
test_guard([$fresh]);
Actions::load('Trips');

$passed = 0; $failed = 0;
function approval_check(bool $condition, string $label): void {
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function approval_refusal(callable $fn, int $status, string $label): void {
    try { $fn(); approval_check(false, $label); }
    catch (BusinessRuleException $e) { approval_check($e->httpStatus === $status, $label . " ({$e->httpStatus})"); }
}

test_recreate($fresh);
$p = db();
test_import($p, test_sql('database/schema.sql'));
test_import($p, test_sql('database/extension.sql'));
$users = [];
foreach (['operations_head' => null, 'container_wingvan_dispatcher' => 'Day', 'car_carrier_dispatcher' => 'Day', 'motorpool' => null, 'admin' => null] as $role => $shift) {
    $id = insert($p, 'users', ['username' => 'appr_' . $role, 'email' => "appr_$role@example.test", 'password_hash' => 'x', 'full_name' => 'APPR ' . $role, 'role' => $role, 'shift' => $shift]);
    $users[$role] = ['id' => $id, 'role' => $role, 'shift' => $shift, 'full_name' => 'APPR ' . $role];
}
['operations_head' => $ops, 'container_wingvan_dispatcher' => $cw, 'car_carrier_dispatcher' => $cc, 'motorpool' => $mp] = $users;
$client = insert($p, 'clients', ['company_name' => 'APPROVAL CLIENT', 'status' => 'Active', 'created_by' => $ops['id']]);
$base = ['client_id' => $client, 'truck_type' => 'Wing Van', 'pickup_point' => 'APPROVAL ORIGIN', 'delivery_point' => 'APPROVAL DESTINATION',
         'scheduled_at' => date('Y-m-d\TH:i', time() + 86400), 'distance_km' => '25'];

// A. no new requests; review retired
foreach (['container_wingvan_dispatcher' => $cw, 'car_carrier_dispatcher' => $cc] as $label => $u)
    approval_refusal(fn() => Trips::action($u, 'create', $base + ['truck_type' => $label === 'car_carrier_dispatcher' ? 'Car Carrier' : 'Wing Van']), 403, "$label cannot originate a trip request");
approval_check((int)one($p, 'SELECT COUNT(*) AS n FROM trips')['n'] === 0, 'refused requests created nothing');

// B. a legacy Pending Review record (historical data from before the retirement)
$legacy = insert($p, 'trips', ['trip_ref' => 'TRP-LEGACY-1', 'intake_source' => 'Dispatcher', 'created_by' => $cw['id'], 'client_id' => $client, 'truck_type' => 'Wing Van',
    'dispatcher_role' => $cw['role'], 'pickup_point' => 'LEGACY ORIGIN', 'delivery_point' => 'LEGACY DESTINATION', 'scheduled_at' => date('Y-m-d H:i:s', time() + 86400), 'status' => 'Pending Review']);
approval_refusal(fn() => Trips::action($ops, 'review', ['trip_id' => $legacy, 'decision' => 'approve']), 409, 'Operations Head review is retired (historical requests stay read-only)');
approval_refusal(fn() => Trips::action($cw, 'review', ['trip_id' => $legacy, 'decision' => 'reject', 'reason' => 'x']), 403, 'dispatcher cannot review by direct API');
approval_refusal(fn() => Trips::action($mp, 'review', ['trip_id' => $legacy, 'decision' => 'approve']), 403, 'maintenance role cannot review');
approval_check(one($p, 'SELECT status FROM trips WHERE id=?', [$legacy])['status'] === 'Pending Review', 'refused reviews leave the legacy record unchanged');
$active = array_map('intval', array_column(Trips::read($ops, 'trips', [])['active'], 'id'));
approval_check(!in_array($legacy, $active, true), 'legacy request is not active work');
$legacyView = array_map('intval', array_column(Trips::read($ops, 'trips', ['legacy' => '1'])['history'], 'id'));
approval_check($legacyView === [$legacy], 'Operations Head legacy history lists the historical request');
$cwHistory = array_map('intval', array_column(Trips::read($cw, 'trips', ['legacy' => '1'])['history'] ?? [], 'id'));
approval_check(!in_array($legacy, $cwHistory, true), 'the legacy view is Operations Head only (parameter ignored for others)');
approval_refusal(fn() => Trips::action($ops, 'edit', ['trip_id' => $legacy, 'pickup_point' => 'X', 'delivery_point' => 'Y', 'scheduled_at' => date('Y-m-d\TH:i', time() + 86400), 'distance_km' => '25']), 409, 'legacy request cannot be edited');
approval_refusal(fn() => Trips::action($cw, 'dispatch', ['trip_id' => $legacy]), 403, 'legacy request cannot be dispatched (no responsible account)');

// C. direct Operations Head planning (the replacement workflow)
$created = test_create_planned_trip($ops, $base, false);
$t = one($p, 'SELECT * FROM trips WHERE id=?', [$created['trip_id']]);
approval_check($t['status'] === 'Awaiting Resources' && !$t['vehicle_id'] && !$t['driver_id'] && !$t['helper_id'], 'Operations Head plans directly: Awaiting Resources, no automatic resources');
approval_check((int)$t['created_by'] === $ops['id'] && $t['client_route_id'] !== null, 'planned trip records its creator and approved route');
$event = one($p, "SELECT actor_id, to_status FROM trip_events WHERE trip_id=? ORDER BY id LIMIT 1", [$t['id']]);
approval_check($event && (int)$event['actor_id'] === $ops['id'] && $event['to_status'] === 'Awaiting Resources', 'creation event recorded with the Operations Head as actor');
approval_check((int)one($p, "SELECT COUNT(*) AS n FROM audit_events WHERE area='Trips' AND actor_id=?", [$ops['id']])['n'] >= 1, 'planning decision audit trail exists');

echo "RESULT: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
