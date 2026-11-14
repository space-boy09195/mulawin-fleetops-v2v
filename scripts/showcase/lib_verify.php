<?php
/*
 * scripts/showcase/lib_verify.php — snapshot, counts, verification queries and the targeted cleanup of the showcase dataset.
 */
declare(strict_types=1);

final class ShowcaseVerify {
    /** Pre-existing rows the scenarios may legitimately change through workflows (restored by cleanup). */
    public const SNAP = ['clients', 'client_rates', 'parts', 'budgets', 'vehicles', 'personnel', 'mechanics', 'mechanic_attendance', 'purchase_orders',
        'purchase_order_lines', 'part_requests', 'part_request_lines', 'maintenance_jobs', 'notifications', 'users', 'fund_categories'];

    public static function snapshot(PDO $p): array {
        $out = [];
        foreach (self::SNAP as $t) $out[$t] = many($p, "SELECT * FROM `$t` ORDER BY id");
        return $out;
    }

    public static function counts(PDO $p, array $base): array {
        $g = fn(string $sql, array $a = []) => array_map(fn($r) => array_values($r), many($p, $sql, $a));
        $b = fn(string $t) => (int)($base[$t] ?? 0);
        return [
            'trips_by_status' => $g('SELECT status, COUNT(*) FROM trips WHERE id>? GROUP BY status ORDER BY 2 DESC', [$b('trips')]),
            'trips_by_type' => $g('SELECT truck_type, COUNT(*) FROM trips WHERE id>? GROUP BY truck_type', [$b('trips')]),
            'trips_by_dispatcher_shift' => $g("SELECT CONCAT(t.dispatcher_role, ' / ', COALESCE(u.shift,'-')), COUNT(*) FROM trips t LEFT JOIN users u ON u.id=t.dispatcher_id WHERE t.id>? AND t.dispatcher_id IS NOT NULL GROUP BY 1", [$b('trips')]),
            'trips_by_month' => $g("SELECT DATE_FORMAT(COALESCE(dispatched_at, created_at),'%Y-%m'), COUNT(*) FROM trips WHERE id>? GROUP BY 1 ORDER BY 1", [$b('trips')]),
            'fund_requests' => $g('SELECT CONCAT(status, " / ", liquidation_status), COUNT(*), SUM(amount) FROM fund_requests WHERE id>? GROUP BY 1', [$b('fund_requests')]),
            'incidents' => $g('SELECT CONCAT(incident_type, " / ", COALESCE(severity,"not assessed"), " / ", status), COUNT(*) FROM incidents WHERE id>? GROUP BY 1', [$b('incidents')]),
            'billings' => $g('SELECT status, COUNT(*), SUM(total_amount), SUM(amount_paid) FROM billings WHERE id>? GROUP BY status', [$b('billings')]),
            'billing_charges' => $g('SELECT status, COUNT(*), SUM(amount) FROM billing_charges WHERE id>? GROUP BY status', [$b('billing_charges')]),
            'collections' => $g("SELECT DATE_FORMAT(payment_date,'%Y-%m'), COUNT(*), SUM(amount) FROM collections WHERE id>? GROUP BY 1 ORDER BY 1", [$b('collections')]),
            'maintenance_jobs' => $g('SELECT CONCAT(source, " / ", status, IF(status="Completed" AND verified_at IS NULL, " (awaiting verification)", "")), COUNT(*) FROM maintenance_jobs WHERE id>? GROUP BY 1', [$b('maintenance_jobs')]),
            'maintenance_costs' => $g('SELECT cost_type, COUNT(*), SUM(amount) FROM maintenance_costs WHERE id>? GROUP BY cost_type', [$b('maintenance_costs')]),
            'repeat_repairs' => $g('SELECT v.plate_number, COUNT(*) FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id WHERE m.id>? AND m.status<>"Cancelled" GROUP BY v.id HAVING COUNT(*)>1', [$b('maintenance_jobs')]),
            'assisting_mechanics' => $g('SELECT COUNT(*) FROM maintenance_job_helpers WHERE id>?', [$b('maintenance_job_helpers')]),
            'mechanic_sessions' => $g('SELECT COUNT(*) FROM mechanic_attendance WHERE id>?', [$b('mechanic_attendance')]),
            'part_requests' => $g('SELECT status, COUNT(*) FROM part_requests WHERE id>? GROUP BY status', [$b('part_requests')]),
            'purchase_orders' => $g('SELECT CONCAT(status, " / ", COALESCE(payment_status,"-")), COUNT(*), SUM(total_amount) FROM purchase_orders WHERE id>? GROUP BY 1', [$b('purchase_orders')]),
            'stock_movements' => $g('SELECT movement_type, COUNT(*), SUM(quantity_delta) FROM stock_movements WHERE id>? GROUP BY movement_type', [$b('stock_movements')]),
            'stock_counts' => $g('SELECT status, COUNT(*) FROM stock_counts WHERE id>? GROUP BY status', [$b('stock_counts')]),
            'payables' => $g('SELECT status, COUNT(*), SUM(amount), SUM(paid_amount) FROM payables WHERE id>? GROUP BY status', [$b('payables')]),
            'disbursements' => $g('SELECT source_type, COUNT(*), SUM(amount) FROM disbursements WHERE id>? GROUP BY source_type', [$b('disbursements')]),
            'expenses' => $g('SELECT CONCAT(category, " / ", source), COUNT(*), SUM(amount) FROM expenses WHERE id>? GROUP BY 1', [$b('expenses')]),
            'documents' => $g('SELECT category, COUNT(*) FROM documents WHERE id>? GROUP BY category', [$b('documents')]),
            'audit_events' => $g('SELECT COALESCE(u.username, "(system)"), COUNT(*) FROM audit_events a LEFT JOIN users u ON u.id=a.actor_id WHERE a.id>? GROUP BY 1', [$b('audit_events')]),
            'notifications' => $g('SELECT state, COUNT(*) FROM notifications WHERE id>? GROUP BY state', [$b('notifications')]),
            'clients' => $g('SELECT status, COUNT(*) FROM clients GROUP BY status'),
        ];
    }

    /** Read-only verification and reconciliation (exit code 1 when a check fails). */
    public static function run(PDO $p, ?array $m): int {
        if (!$m) { fwrite(STDERR, "No manifest: nothing to verify.\n"); return 2; }
        $p->exec('SET SESSION TRANSACTION READ ONLY'); $p->beginTransaction();
        $base = $m['baseline_max_ids']; $b = fn(string $t) => (int)($base[$t] ?? 0);
        $fails = 0;
        $check = function (string $name, bool $ok, string $detail = '') use (&$fails) { if (!$ok) $fails++; echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . ($detail !== '' ? "  ($detail)" : '') . "\n"; };
        $n = fn(string $sql, array $a = []) => (float)(one($p, $sql, $a)['v'] ?? 0);
        echo "== Reconciliation\n";
        $check('invoice amount_paid = sum of its collections (all billings)', $n('SELECT COUNT(*) AS v FROM billings b WHERE ABS(b.amount_paid - (SELECT COALESCE(SUM(amount),0) FROM collections c WHERE c.billing_id=b.id)) > 0.004') == 0);
        $check('invoice status matches its balance', $n("SELECT COUNT(*) AS v FROM billings WHERE (status='Paid' AND amount_paid < total_amount) OR (status='Partially Paid' AND (amount_paid <= 0 OR amount_paid >= total_amount)) OR (status='Invoiced' AND amount_paid > 0)") == 0);
        $check('no collection before its invoice date', $n('SELECT COUNT(*) AS v FROM collections c JOIN billings b ON b.id=c.billing_id WHERE c.payment_date < b.invoice_date') == 0);
        $check('invoice total = base + approved charges', $n("SELECT COUNT(*) AS v FROM billings b WHERE ABS(b.total_amount - b.base_amount - (SELECT COALESCE(SUM(amount),0) FROM billing_charges c WHERE c.billing_id=b.id AND c.status='Approved' AND c.request_type<>'Removal')) > 0.004") == 0);
        $check('no negative stock', $n('SELECT COUNT(*) AS v FROM parts WHERE quantity_on_hand < 0') == 0);
        $snapParts = array_column($m['preexisting_snapshot']['parts'] ?? [], 'quantity_on_hand', 'id');
        $bad = [];
        foreach (many($p, 'SELECT id, part_number, quantity_on_hand FROM parts') as $pt) {
            $delta = (int)$n('SELECT COALESCE(SUM(quantity_delta),0) AS v FROM stock_movements WHERE part_id=? AND id>?', [$pt['id'], $b('stock_movements')]);
            if ((int)$pt['quantity_on_hand'] !== (int)($snapParts[$pt['id']] ?? 0) + $delta) $bad[] = $pt['part_number'];
        }
        $check('stock on hand = starting stock + generated movements, every part', !$bad, implode(', ', $bad));
        $check('received quantity never above ordered', $n('SELECT COUNT(*) AS v FROM purchase_order_lines WHERE received_qty > quantity') == 0);
        $check('released quantity never above requested', $n('SELECT COUNT(*) AS v FROM part_request_lines WHERE released_qty > quantity') == 0);
        foreach (many($p, 'SELECT id, reserved_amount, spent_amount FROM budgets') as $bg) {
            $res = $n('SELECT COALESCE(SUM(reserved_amount),0) AS v FROM purchase_orders WHERE budget_id=?', [$bg['id']]);
            $paid = $n('SELECT COALESCE(SUM(paid_amount),0) AS v FROM purchase_orders WHERE budget_id=?', [$bg['id']]);
            $check("budget {$bg['id']}: reserved = POs' reserved, spent = POs' paid", abs($res - (float)$bg['reserved_amount']) < 0.01 && abs($paid - (float)$bg['spent_amount']) < 0.01, "reserved $res vs {$bg['reserved_amount']}, paid $paid vs {$bg['spent_amount']}");
        }
        $check('PO payments: disbursements = PO paid amounts', abs($n("SELECT COALESCE(SUM(amount),0) AS v FROM disbursements WHERE source_type='purchase_order'") - $n('SELECT COALESCE(SUM(paid_amount),0) AS v FROM purchase_orders')) < 0.01);
        $check('PO payments: Parts expenses = disbursements (each payment once)', abs($n("SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE source='PO Payment'") - $n("SELECT COALESCE(SUM(amount),0) AS v FROM disbursements WHERE source_type='purchase_order'")) < 0.01);
        $check('payables: disbursements = paid amounts = payable expenses', abs($n("SELECT COALESCE(SUM(amount),0) AS v FROM disbursements WHERE source_type='payable'") - $n('SELECT COALESCE(SUM(paid_amount),0) AS v FROM payables')) < 0.01
            && abs($n("SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE source='Payable'") - $n('SELECT COALESCE(SUM(paid_amount),0) AS v FROM payables')) < 0.01);
        $check('trip funds: released disbursements = released fund amounts', abs($n("SELECT COALESCE(SUM(amount),0) AS v FROM disbursements WHERE source_type='fund_request'") - $n("SELECT COALESCE(SUM(amount),0) AS v FROM fund_requests WHERE status='Released'")) < 0.01);
        $check('trip allowance expense = released - returned cash', abs($n("SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE category='Trip Allowance'") - ($n("SELECT COALESCE(SUM(amount),0) AS v FROM fund_requests WHERE status='Released'") - $n('SELECT COALESCE(SUM(returned_amount),0) AS v FROM fund_requests'))) < 0.01);
        $check('repair costs = Maintenance expenses (each cost once)', abs($n('SELECT COALESCE(SUM(amount),0) AS v FROM maintenance_costs') - $n("SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE source='Maintenance'")) < 0.01);
        $check('no source recorded twice as an expense', $n("SELECT COUNT(*) AS v FROM (SELECT source, source_id FROM expenses WHERE source_id IS NOT NULL AND source IN ('PO Payment','Maintenance','Fund Release','Liquidation','Payable') GROUP BY source, source_id HAVING COUNT(*)>1) x") == 0);
        echo "== Lifecycle order (generated trips and repairs)\n";
        $check('created <= approved <= dispatched <= completed', $n('SELECT COUNT(*) AS v FROM trips WHERE id>? AND ((approved_at IS NOT NULL AND approved_at < created_at) OR (dispatched_at IS NOT NULL AND approved_at IS NOT NULL AND dispatched_at < approved_at) OR (completed_at IS NOT NULL AND completed_at < dispatched_at))', [$b('trips')]) == 0);
        $check('scheduled time within 20 min before the recorded dispatch', $n('SELECT COUNT(*) AS v FROM trips WHERE id>? AND dispatched_at IS NOT NULL AND attempt_number=1 AND (scheduled_at > dispatched_at OR scheduled_at < dispatched_at - INTERVAL 21 MINUTE)', [$b('trips')]) == 0);
        $check('trip events in time order per trip', $n('SELECT COUNT(*) AS v FROM trip_events e JOIN trip_events f ON f.trip_id=e.trip_id AND f.id>e.id AND f.created_at < e.created_at WHERE e.trip_id IN (SELECT id FROM trips WHERE id>?)', [$b('trips')]) == 0);
        $check('fund approved/released before dispatch', $n('SELECT COUNT(*) AS v FROM fund_requests f JOIN trips t ON t.id=f.trip_id WHERE f.id>? AND f.attempt_number=1 AND t.dispatched_at IS NOT NULL AND (f.released_at > t.dispatched_at OR f.approved_at > f.released_at)', [$b('fund_requests')]) == 0);
        $check('invoice after completion; due = invoice + terms', $n('SELECT COUNT(*) AS v FROM billings b JOIN trips t ON t.id=b.trip_id JOIN clients c ON c.id=b.client_id WHERE b.id>? AND b.invoice_date IS NOT NULL AND (b.invoice_date < DATE(t.completed_at) OR b.due_date <> b.invoice_date + INTERVAL COALESCE(c.payment_terms_days,30) DAY)', [$b('billings')]) == 0);
        $check('repairs: reported <= completed <= verified', $n('SELECT COUNT(*) AS v FROM maintenance_jobs WHERE id>? AND ((completed_on IS NOT NULL AND completed_on < reported_on) OR (verified_at IS NOT NULL AND verified_at < completed_at))', [$b('maintenance_jobs')]) == 0);
        $check('mechanic attendance sessions do not overlap', $n('SELECT COUNT(*) AS v FROM mechanic_attendance a JOIN mechanic_attendance c ON c.mechanic_id=a.mechanic_id AND c.id<>a.id AND c.time_in < COALESCE(a.time_out, NOW()) AND COALESCE(c.time_out, NOW()) > a.time_in WHERE a.id>?', [$b('mechanic_attendance')]) == 0);
        $check('every generated date is in the past (no future activity)', $n('SELECT COUNT(*) AS v FROM trip_events WHERE id>? AND created_at > NOW()', [$b('trip_events')]) + $n('SELECT COUNT(*) AS v FROM audit_events WHERE id>? AND created_at > NOW()', [$b('audit_events')]) == 0);
        echo "== Attribution\n";
        $loaders = array_map('intval', array_column(many($p, "SELECT id FROM users WHERE username LIKE 'sysloader\\_%'"), 'id'));
        $check('generated audit entries are by system-loader accounts or the system (never staff)', $n('SELECT COUNT(*) AS v FROM audit_events WHERE id>? AND actor_id IS NOT NULL AND actor_id NOT IN (' . implode(',', $loaders ?: [0]) . ')', [$b('audit_events')]) == 0);
        $proj = fn(array $rows) => md5(json_encode(array_map(fn($u) => [(string)$u['id'], (string)$u['username'], (string)$u['role'], (string)$u['active'], (string)$u['password_hash'], (string)$u['shift'], (string)$u['must_change_password']], $rows)));
        $orig = $m['preexisting_snapshot']['users'];
        $check('the ' . count($orig) . ' original accounts are unchanged', $proj($orig) === $proj(many($p, 'SELECT * FROM users WHERE id<=? ORDER BY id', [(int)end($orig)['id']])));
        echo "== Head Management reads (as the real Admin account, read only)\n";
        Actions::load('Oversight'); Actions::load('Analytics');
        $adm = one($p, "SELECT id, username, full_name, role, shift FROM users WHERE username='admin'"); $adm['id'] = (int)$adm['id'];
        foreach (['admin_overview', 'admin_approvals', 'admin_operations', 'admin_maintenance', 'admin_purchasing', 'admin_finance', 'admin_reports'] as $pg) {
            try { $d = Oversight::read($adm, $pg, []); $check("$pg loads", empty($d['_errors']), json_encode($d['_errors'] ?? [])); } catch (Throwable $e) { $check("$pg loads", false, $e->getMessage()); }
        }
        $from = '2026-08-03'; $to = date('Y-m-d');
        foreach (['operations', 'maintenance', 'purchasing', 'finance'] as $t) {
            try { $d = Oversight::read($adm, 'admin_analytics', ['topic' => $t, 'from' => $from, 'to' => $to]); $check("admin_analytics $t loads", empty($d['_errors'])); } catch (Throwable $e) { $check("admin_analytics $t loads", false, $e->getMessage()); }
        }
        $mo = Oversight::read($adm, 'admin_analytics', ['topic' => 'maintenance', 'from' => $from, 'to' => $to]);
        $mu = one($p, "SELECT id, username, full_name, role, shift FROM users WHERE username='maintenance'"); $mu['id'] = (int)$mu['id'];
        $mr = Analytics::read($mu, 'maintenance_reports', ['from' => $from, 'to' => $to]);
        $check('Admin maintenance analytics = Motorpool report (cost total, repairs reported)', abs((float)($mo['report']['cost']['total'] ?? -1) - (float)($mr['cost']['total'] ?? -2)) < 0.01
            && array_sum($mo['report']['timeline']['reported'] ?? []) === array_sum($mr['timeline']['reported'] ?? [-1]));
        echo "== Rule-based suggestions and priorities now triggered (source records)\n";
        $fi = Oversight::read($adm, 'admin_finance', []);
        foreach ($fi['suggestions'] as $k => $list) foreach ($list as $s) echo "  finance.$k: {$s['text']} [{$s['go']}]\n";
        echo '  weekly: ' . json_encode($fi['weekly']) . "\n";
        $ex = Oversight::read($adm, 'admin_reports', []);
        foreach ($ex['priorities'] as $x) echo "  priority [{$x['sev']}] {$x['dept']} {$x['ref']}: {$x['issue']} — {$x['reason']} -> {$x['next']}\n";
        foreach ($ex['risks'] as $x) echo "  risk: {$x['text']}\n";
        $pu = Oversight::read($adm, 'admin_purchasing', []);
        foreach ($pu['issues'] as $x) echo "  purchasing issue: {$x['ref']} {$x['reason']} ({$x['status']}) -> {$x['next']}\n";
        foreach ($pu['partTips'] ?? [] as $x) echo "  part tip: " . ($x['text'] ?? json_encode($x)) . "\n";
        $op = Oversight::read($adm, 'admin_operations', []);
        foreach ($op['attention'] as $x) echo "  trip attention: {$x['trip_ref']} {$x['status']}" . ($x['open_incident'] ? " (incident {$x['open_incident']})" : '') . "\n";
        $mt = Oversight::read($adm, 'admin_maintenance', []);
        foreach ($mt['attention'] as $x) echo "  repair attention: {$x['job_ref']} {$x['reason']} {$x['requests']}\n";
        foreach (['operations', 'maintenance', 'purchasing', 'finance'] as $t) {
            $d = Oversight::read($adm, 'admin_analytics', ['topic' => $t, 'from' => $from, 'to' => $to]);
            foreach ($d['suggestions'] ?? [] as $k => $list) foreach ($list as $s) echo "  analytics.$t.$k: " . ($s['text'] ?? json_encode($s)) . "\n";
        }
        echo "== Weekly collections (Mon-Sun)\n";
        foreach (many($p, "SELECT DATE_SUB(payment_date, INTERVAL WEEKDAY(payment_date) DAY) AS wk, COUNT(*) AS n, SUM(amount) AS amt FROM collections GROUP BY wk ORDER BY wk DESC LIMIT 8") as $r) echo "  week of {$r['wk']}: {$r['n']} payment(s), " . number_format((float)$r['amt'], 2) . "\n";
        $p->rollBack();
        echo "\n" . ($fails ? "$fails check(s) FAILED" : 'All checks passed') . "\n";
        return $fails ? 1 : 0;
    }

    /** Removes ONLY the manifest's generated rows and restores the changed pre-existing rows. */
    public static function cleanup(PDO $p, string $file): int {
        if (!is_file($file)) { fwrite(STDERR, "No manifest at $file: nothing to clean.\n"); return 2; }
        $m = json_decode((string)file_get_contents($file), true);
        if (($m['database'] ?? '') !== envValue('DB_NAME')) { fwrite(STDERR, "Refused: the manifest belongs to another database.\n"); return 2; }
        $trips = array_map('intval', $m['trip_ids'] ?? []); $audits = array_map('intval', $m['created_ids']['audit_events'] ?? []);
        $p->exec('SET FOREIGN_KEY_CHECKS=0'); $p->beginTransaction();
        try {
            $del = 0;
            $in = fn(array $ids) => implode(',', array_map('intval', $ids));
            foreach (array_chunk($audits, 500) as $c) $del += $p->exec('DELETE FROM audit_event_changes WHERE audit_event_id IN (' . $in($c) . ')');
            foreach (['checklist_starts', 'trip_assignments', 'trip_routes'] as $t) foreach (array_chunk($trips, 500) as $c) $del += $p->exec("DELETE FROM `$t` WHERE trip_id IN (" . $in($c) . ')');
            foreach ($m['created_ids'] as $t => $ids) foreach (array_chunk($ids, 500) as $c) $del += $p->exec("DELETE FROM `$t` WHERE id IN (" . $in($c) . ')');
            $restored = 0;
            foreach ($m['preexisting_snapshot'] as $t => $rows) {
                $gen = array_column(many($p, "SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND EXTRA LIKE '%GENERATED%'", [$t]), 'c');
                foreach ($rows as $r) {
                    foreach ($gen as $g) unset($r[$g]);
                    $id = $r['id']; unset($r['id']);
                    $cols = implode(',', array_map(fn($c) => "`$c`=?", array_keys($r)));
                    $st = $p->prepare("UPDATE `$t` SET $cols WHERE id=?"); $st->execute(array_merge(array_values($r), [$id]));
                    $restored += $st->rowCount();
                }
            }
            $p->commit();
            $p->exec('SET FOREIGN_KEY_CHECKS=1');
            echo "Cleanup: $del generated row(s) deleted, $restored pre-existing row(s) restored to their snapshot.\n";
        } catch (Throwable $e) { $p->rollBack(); $p->exec('SET FOREIGN_KEY_CHECKS=1'); fwrite(STDERR, 'Cleanup failed and was rolled back: ' . $e->getMessage() . "\n"); return 1; }
        rename($file, $file . '.cleaned');
        return 0;
    }
}
