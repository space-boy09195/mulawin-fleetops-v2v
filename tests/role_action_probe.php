<?php
/*
 * =====================================================================
 *  tests/role_action_probe.php  —  role x action permission probe (UAT evidence, not a pass/fail suite)
 * =====================================================================
 *  Do NOT run this file directly: run tests/run_role_action_probe.php (temporary copy, own .env, *_test database).
 *  Builds the end-to-end scenario (tests/integration_workflow_tests.php), then calls EVERY backend action as EVERY role
 *  with the same real record ids. Each call runs inside an outer transaction that is rolled back, so no probe call
 *  changes the data. Result per call: 403 (refused by permission), 4xx (permission passed; stopped by validation /
 *  state), OK (executed, then rolled back), ERR (unexpected exception = possible defect).
 *  Writes the matrix as JSON to the path in PROBE_OUT (or the temp folder).
 * =====================================================================
 */
declare(strict_types=1);
define('E2E_KEEP_RUNNING', true);
require __DIR__ . '/integration_workflow_tests.php';

section('Role x action probe');
foreach (['car_carrier_dispatcher' => 'Day', 'car_carrier_dispatcher#night' => 'Night', 'container_wingvan_dispatcher#night' => 'Night'] as $key => $shift) {
    $role = explode('#', $key)[0];
    $id = insert($p, 'users', ['username' => 'probe_' . str_replace('#', '_', $key), 'email' => 'probe_' . str_replace('#', '_', $key) . '@example.test', 'password_hash' => 'x',
        'full_name' => 'PROBE ' . $key, 'role' => $role, 'shift' => $shift]);
    $users[$key] = ['id' => $id, 'role' => $role, 'shift' => $shift, 'full_name' => 'PROBE ' . $key, 'username' => 'probe_' . $key, 'email' => "probe_$key@example.test"];
}
$users['container_wingvan_dispatcher#day'] = $users['container_wingvan_dispatcher']; unset($users['container_wingvan_dispatcher']);
$users['car_carrier_dispatcher#day'] = $users['car_carrier_dispatcher']; unset($users['car_carrier_dispatcher']);

$actions = [
    'Admin' => ['backup_create', 'fund_category_save', 'notifications_read', 'pricing_save', 'setting_save', 'signature_upload', 'user_password', 'user_save', 'user_toggle', 'change_password', 'recovery_code_create'],
    'Analytics' => ['operations_export'],
    'Attendance' => ['dispatcher_history', 'dispatcher_list', 'list', 'readiness', 'save', 'time_in', 'time_out'],
    'Billing' => ['charge_add', 'charge_change', 'charge_decide', 'charge_remove', 'charge_withdraw', 'collect', 'details', 'finalize', 'pricing_recalculate', 'pricing_report', 'soa', 'verify'],
    'Clients' => ['create', 'details', 'documents', 'set_active', 'update', 'rate_save'],
    'Finance' => ['budget_set', 'expense_add', 'payable_add', 'payable_pay', 'po_pay'],
    'Fleet' => ['crew_active', 'crew_details', 'crew_save', 'crew_status', 'truck_active', 'truck_details', 'truck_save', 'truck_status'],
    'Funds' => ['decide', 'details', 'liquidate', 'release', 'submit'],
    'Maintenance' => ['add_cost', 'assess', 'assign_mechanic', 'cancel', 'clearance', 'complete', 'details', 'helper_remove', 'helpers_add', 'inspect', 'report', 'verify'],
    'Mechanics' => ['availability', 'details', 'save', 'set_active', 'time_in', 'time_out'],
    'Purchasing' => ['adjust', 'count_details', 'count_review', 'count_submit', 'my_requests', 'part_history', 'part_save', 'po_close', 'po_create', 'po_decide', 'po_details', 'po_receive',
                     'request_cancel', 'request_catalog', 'request_create', 'request_details', 'request_process'],
    'Routes' => ['deactivate', 'decide', 'details', 'for_client', 'pricing_estimate', 'rate_enter', 'request', 'selectable', 'withdraw'],
    'Trips' => ['arrival', 'assign_resources', 'auto_assign', 'cancel', 'checklist', 'checklist_start', 'complete', 'create', 'create_batch', 'crew_options', 'details', 'diesel_po', 'dispatch',
                'dispatcher_resources', 'edit', 'incident', 'incident_reschedule', 'monitor_details', 'progress', 'reassign', 'reschedule', 'resume', 'review',
                'turnover_list', 'turnover_details', 'turnover_preview', 'turnover_endorse', 'turnover_accept', 'turnover_assign_initial'],
];
// One shared payload of REAL record ids from the scenario (missing business fields are part of the probe).
$P = ['trip_id' => $T2, 'job_id' => (int)$job['id'], 'po_id' => (int)$po['id'], 'billing_id' => (int)$b1['id'], 'fund_id' => $F1, 'request_id' => (int)$req['id'],
      'part_id' => $part, 'mechanic_id' => $mid, 'client_id' => $cid, 'route_id' => $route, 'rate_id' => 1, 'id' => 1, 'vehicle_id' => (int)$t2['vehicle_id'],
      'personnel_id' => (int)$t2['driver_id'], 'payable_id' => 1, 'charge_id' => 1, 'count_id' => 1, 'session_id' => 1, 'user_id' => $adm['id'],
      'key' => 'inspection_min_minutes', 'value' => '0', 'dispatcher_id' => $users['container_wingvan_dispatcher#day']['id'], 'kind' => 'Driver'];

$matrix = []; $errors = [];
foreach ($actions as $module => $list) {
    Actions::load($module);
    foreach ($list as $action) foreach ($users as $key => $u) {
        $_FILES = [];
        $p->beginTransaction();
        try { $module::action($u, $action, $P); $res = 'OK'; }
        catch (BusinessRuleException $e) { $res = (string)$e->httpStatus; }
        catch (Throwable $e) { $res = 'ERR'; $errors[] = "$module.$action as $key: " . get_class($e) . ': ' . substr($e->getMessage(), 0, 160); }
        finally { if ($p->inTransaction()) $p->rollBack(); Settings::clearCache(); }
        $matrix["$module.$action"][$key] = $res;
    }
}
$pages = [];
foreach ($users as $key => $u) $pages[$key] = array_values(array_column(Access::navFor($u), 'id'));
$out = envValue('PROBE_OUT') ?: sys_get_temp_dir() . '/role_action_probe.json';
file_put_contents($out, json_encode(['matrix' => $matrix, 'pages' => $pages, 'errors' => $errors, 'roles' => array_keys($users)], JSON_PRETTY_PRINT));
ok(count($matrix) > 100, 'probe covered ' . count($matrix) . ' actions x ' . count($users) . ' roles; written to ' . $out);
ok($errors === [], 'no unexpected exception (ERR) in any role/action call', implode(' | ', array_slice($errors, 0, 6)));

section('Dispatcher-only trip steps with COMPLETE payloads: every other role must get 403 (not a validation error)');
$full = [
    'incident' => ['trip_id' => $T2, 'incident_type' => 'Breakdown', 'incident_at' => date('Y-m-d\TH:i'), 'location' => 'Probe', 'problem' => 'Probe', 'details' => 'Probe'],
    'complete' => ['trip_id' => $T2, 'completed_at' => date('Y-m-d\TH:i'), 'waybill_number' => 'WB-PROBE'],
    'checklist_start' => ['trip_id' => $T2, 'kind' => 'Pre-Dispatch'],
    'checklist' => ['trip_id' => $T2, 'items' => $items, 'meta' => [], 'odometer_km' => '1000', 'fuel_level' => 'Full'],
    'arrival' => ['trip_id' => $T2, 'items' => $items, 'fuel_level' => 'Full'],
    'reschedule' => ['trip_id' => $T2, 'scheduled_at' => date('Y-m-d\TH:i', time() + 2 * 86400)],
];
foreach ($full as $action => $payload) foreach (['admin', 'operations_head', 'motorpool', 'purchasing', 'finance', 'billing', 'audit'] as $key) {
    $p->beginTransaction();
    try { Trips::action($users[$key], $action, $payload); $res = 'OK'; }
    catch (BusinessRuleException $e) { $res = (string)$e->httpStatus; }
    catch (Throwable $e) { $res = 'ERR'; }
    finally { if ($p->inTransaction()) $p->rollBack(); }
    ok($res === '403', "Trips.$action as $key with a complete payload is refused by permission", $res);
}
echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL ? 1 : 0);
