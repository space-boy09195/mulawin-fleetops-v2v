<?php
/*
 * =====================================================================
 *  tests/maintenance_report_rules_tests.php  —  Maintenance Reports: timeline periods and suggestion rules
 * =====================================================================
 *  NO DATABASE NEEDED. Run:  php tests/maintenance_report_rules_tests.php
 *  Covers Analytics::bucketUnit / buckets (day, week, month; periods cover the range exactly) and
 *  Analytics::suggestRules (MOTORPOOL_REPORTS_V2): valid findings, insufficient-data cases and no-match cases.
 *  The SQL behind the figures is checked read-only against the real data in the implementation report.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once dirname(__DIR__) . '/backend/Actions.php';
Actions::load('Analytics');

$pass = 0; $fail = 0;
function ok(bool $c, string $m): void { global $pass, $fail; if ($c) { $pass++; echo "  ok   $m\n"; } else { $fail++; echo "  FAIL $m\n"; } }
function section(string $t): void { echo "\n== $t ==\n"; }
$texts = fn(array $list) => implode(' | ', array_column($list, 'text'));

section('A. Timeline periods');
ok(Analytics::bucketUnit('2026-11-01', '2026-11-30') === 'day', '30 days -> daily');
ok(Analytics::bucketUnit('2026-09-01', '2026-11-12') === 'week', '73 days -> weekly');
ok(Analytics::bucketUnit('2026-01-01', '2026-11-12') === 'month', '316 days -> monthly');
foreach ([['2026-11-01', '2026-11-12'], ['2026-09-01', '2026-11-12'], ['2025-12-15', '2026-11-12']] as [$f, $t]) {
    $b = Analytics::buckets($f, $t); $okChain = $b[0]['from'] === $f && end($b)['to'] === $t;
    for ($i = 1; $i < count($b); $i++) $okChain = $okChain && $b[$i]['from'] === date('Y-m-d', strtotime($b[$i - 1]['to'] . ' +1 day')) && $b[$i]['from'] <= $b[$i]['to'];
    ok($okChain, "periods cover $f .. $t exactly, without gaps or overlaps (" . count($b) . ')');
}
$w = Analytics::buckets('2026-09-01', '2026-11-12');
ok($w[0]['to'] === '2026-09-06' && date('N', strtotime($w[1]['from'])) === '1', 'weeks run Monday-Sunday; the first week is clipped to the period start');
ok(count(Analytics::buckets('2026-11-12', '2026-11-12')) === 1, 'a one-day period has one point');

// helper: report data with given figures
$mk = function (array $rep, array $done = [], array $partsOpen = [], array $now = [], array $freq = [], ?string $first = '2026-01-01') {
    $n = count($rep); $z = array_fill(0, $n, 0);
    return ['timeline' => ['reported' => $rep, 'completed' => $done ?: $z, 'parts_open' => $partsOpen ?: $z],
            'now' => $now + ['awaiting_verification' => 0, 'waiting_parts' => 0, 'first_record' => $first], 'frequency' => $freq];
};
$days = Analytics::buckets('2026-11-01', '2026-11-10');   // 10 daily buckets

section('B. Current-state rules (pending verification, waiting for parts)');
$s = Analytics::suggestRules($mk(array_fill(0, 10, 0), [], [], ['awaiting_verification' => 2, 'waiting_parts' => 1]), $days);
ok(str_contains($texts($s['timeline']), '2 completed repair(s) are waiting for verification'), 'R1: awaiting verification is suggested with its count');
ok(($s['timeline'][0]['go'] ?? '') === 'maintenance?view=verify', 'R1 links to the repairs awaiting verification');
ok(str_contains($texts($s['timeline']), '1 active repair(s) have an open parts request'), 'R2: repairs waiting for parts are suggested');
$s = Analytics::suggestRules($mk(array_fill(0, 10, 0)), $days);
ok($s['timeline'] === [] && $s['trucks'] === [], 'no match: nothing pending -> no suggestion (neutral empty state)');

section('C. No statistical "unusually high" classification (removed: no validated comparison method)');
$rep = [1, 0, 1, 1, 0, 1, 0, 1, 7, 1];   // a concentrated period
$s = Analytics::suggestRules($mk($rep), $days);
$all = $texts($s['timeline']) . ' ' . implode(' ', array_map($texts, $s['points']));
ok(!preg_match('/unusual|high|spike|critical|risk/i', $all), 'a concentrated period is not labelled unusually high, critical or risky');
ok(str_contains($texts($s['points'][8]), 'reported (7) than completed (0)'), 'the period still shows its factual counts (reported vs completed)');

section('D. Per-period suggestions');
$s = Analytics::suggestRules($mk([0, 3, 1, 0, 0, 0, 0, 0, 0, 0], [0, 1, 1, 0, 0, 0, 0, 0, 0, 0], [0, 0, 2, 0, 0, 0, 0, 0, 0, 0]), $days);
ok(str_contains($texts($s['points'][1]), 'reported (3) than completed (1)'), 'more reported than completed is shown for that period');
ok(($s['points'][1][0]['go'] ?? '') === 'maintenance?view=all&cancelled=0&from=2026-11-02&to=2026-11-02', 'the drill-down keeps the period dates');
ok(!str_contains($texts($s['points'][2]), 'than completed'), 'one report that was also completed -> no backlog suggestion');
ok(str_contains($texts($s['points'][2]), '2 parts request(s) made in this period are still open'), 'open parts requests created in the period are suggested');
ok($s['points'][0] === [], 'a quiet period has no suggestion');

section('E. Repair frequency stays a factual count (no frequent-truck threshold)');
$fleet = fn(array $counts) => array_map(fn($k, $i) => ['id' => $i + 1, 'plate_number' => 'TRK' . ($i + 1), 'repairs' => $k], $counts, array_keys($counts));
$s = Analytics::suggestRules($mk(array_fill(0, 10, 0), [], [], [], $fleet([4, 1, 1, 0, 0, 0])), $days);
ok($s['trucks'] === [], 'a truck with 4 repairs is not flagged as high risk or for inspection (no company policy)');
$s = Analytics::suggestRules($mk(array_fill(0, 10, 0), [], [], ['awaiting_verification' => 1], $fleet([4, 1, 1, 0, 0, 0])), $days);
ok(count(array_unique(array_column($s['timeline'], 'text'))) === count($s['timeline']) && count($s['timeline']) === 1, 'only the factual verification suggestion remains; no duplicates');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
