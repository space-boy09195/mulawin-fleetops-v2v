<?php
/*
 * =====================================================================
 *  tests/purchasing_report_rules_tests.php  —  Purchasing Reports: replenishment and period suggestion rules
 * =====================================================================
 *  NO DATABASE NEEDED. Run:  php tests/purchasing_report_rules_tests.php
 *  Covers Purchasing::partRules (A frequent + not covered, B covered by an approved PO, C insufficient history)
 *  and Purchasing::flowRules (per chart period). The SQL figures are checked read-only against real data.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
Actions::load('Analytics'); Actions::load('Purchasing');

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
$part = fn(array $x) => $x + ['id' => 1, 'part_number' => 'P1', 'quantity_on_hand' => 0, 'reorder_level' => 5, 'outstanding' => 0, 'on_order_approved' => 0,
    'on_order_pending' => 0, 'open_po_id' => null, 'pending_po_id' => null, 'releases' => 0];
$text = fn(array $t) => implode(' | ', array_column($t, 'text'));

section('A. Replenishment rules (Purchasing::partRules)');
$t = Purchasing::partRules([$part(['releases' => 4, 'quantity_on_hand' => 1])]);
ok(count($t) === 1 && str_contains($t[0]['text'], 'on hand 1, reorder level 5, released 4 time(s) in the period; approved incoming quantity (0) does not cover the shortage. Review replenishment'), 'A: recorded use + low stock + nothing incoming -> review replenishment, stated with the recorded figures');
ok(!preg_match('/frequent|critical|urgent|emergency|priority/i', $t[0]['text']), 'A: no frequency, urgency or priority label');
$t = Purchasing::partRules([$part(['releases' => 4, 'quantity_on_hand' => 1, 'on_order_approved' => 10, 'open_po_id' => 7])]);
ok(count($t) === 1 && str_contains($t[0]['text'], 'an approved PO covers the shortage. Follow up the delivery'), 'B: an approved PO covering the need -> follow up delivery, no new purchase');
ok($t[0]['go'] === 'purchase_orders?open=7', 'B links to the covering PO');
$t = Purchasing::partRules([$part(['releases' => 4, 'quantity_on_hand' => 1, 'on_order_approved' => 2])]);
ok(str_contains($t[0]['text'], 'Review replenishment'), 'an approved PO that covers only part of the need does not silence the suggestion');
$t = Purchasing::partRules([$part(['releases' => 1, 'quantity_on_hand' => 0])]);
ok(str_contains($t[0]['text'], 'Insufficient usage history for a demand-based restocking recommendation'), 'C: low stock with too few releases -> insufficient usage history');
$t = Purchasing::partRules([$part(['releases' => 0, 'quantity_on_hand' => 3, 'reorder_level' => 6, 'on_order_pending' => 10, 'pending_po_id' => 5])]);
ok(str_contains($t[0]['text'], 'awaiting approval') && $t[0]['go'] === 'purchase_orders?open=5', 'a PO still awaiting approval is mentioned but does not count as authorised cover');
$t = Purchasing::partRules([$part(['releases' => 9, 'quantity_on_hand' => 20, 'reorder_level' => 5])]);
ok($t === [], 'no match: stock above the reorder level -> no suggestion, however frequent the use');
$t = Purchasing::partRules([$part(['releases' => 3, 'quantity_on_hand' => 8, 'reorder_level' => 5, 'outstanding' => 10])]);
ok(count($t) === 1 && str_contains($t[0]['text'], 'Review replenishment'), 'open requests above the stock on hand count as low stock');
$t = Purchasing::partRules([$part(['id' => 1, 'part_number' => 'C1', 'releases' => 0]), $part(['id' => 2, 'part_number' => 'A1', 'releases' => 5]), $part(['id' => 3, 'part_number' => 'B1', 'on_order_approved' => 9, 'open_po_id' => 3])]);
ok(array_map(fn($x) => explode(':', $x['text'])[0], $t) === ['A1', 'B1', 'C1'], 'order: review replenishment, then follow-ups, then insufficient history; one line per part');
ok(!preg_match('/order about|\d+ (pc|pcs|units?) /i', $text($t)), 'no order quantity or coverage forecast is stated');

section('B. Period suggestions (Purchasing::flowRules)');
$b = Analytics::buckets('2026-11-01', '2026-11-03');
$mv = fn($type, $po, $status, $part) => ['movement_type' => $type, 'reference_id' => $po, 'ref' => 'PO-' . $po, 'po_status' => $status, 'part_number' => $part];
$flow = ['moves' => [[$mv('Stock In', 4, 'Partially Received', 'X'), $mv('Stock In', 4, 'Partially Received', 'Y')], [$mv('Stock Out', null, null, 'LOW1')], []]];
$tips = Purchasing::flowRules($flow, [0, 2, 0], [['id' => 9, 'part_number' => 'LOW1', 'quantity_on_hand' => 1, 'reorder_level' => 3]], $b);
ok(count($tips[0]) === 1 && str_contains($tips[0][0]['text'], 'PO-4 was only partly delivered'), 'a partly delivered PO received in the period is suggested once (no duplicates)');
ok(str_contains($text($tips[1]), '2 parts request(s) made in this period are still short'), 'short requests made in the period are suggested');
ok(str_contains($text($tips[1]), 'now at or below their reorder level: LOW1'), 'parts issued in the period that are now low are named');
ok($tips[2] === [], 'a period without records has no suggestion');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
