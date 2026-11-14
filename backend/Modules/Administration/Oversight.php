<?php
/*
 * =====================================================================
 *  backend/Modules/Administration/Oversight.php  —  HEAD MANAGEMENT (Admin) oversight pages   HEAD_MANAGEMENT_V1
 * =====================================================================
 *  Pages (Admin only, Access::pages): admin_overview, admin_approvals, admin_operations, admin_maintenance,
 *  admin_purchasing, admin_finance, admin_reports (Executive Reports: items requiring attention), admin_analytics
 *  (historical trends).  READ ONLY: this module never writes; departmental processing stays in each department.
 *
 *  Approvals: the queue lists ONLY the decisions the existing backend already assigns to the Admin role:
 *    - purchase orders above the approval limit (purchase_orders.approver_role='admin')  -> Purchasing.po_decide
 *    - physical stock counts (stock_counts, migration 13; the counter cannot review)       -> Purchasing.count_review
 *    - billing charge / rate-adjustment requests (billing_charges, migration 09; the requester cannot decide) -> Billing.charge_decide
 *  The decisions themselves are made by those existing actions (role, state and separation-of-duties checks stay there).
 *  Trip-fund requests are decided by the Operations Head and PO approvals at or below the limit by Finance: shown as
 *  monitoring only, never as Admin actions.
 *
 *  Figures reuse the definitions of the owning modules (same SQL rules): receivables = open invoices (Invoiced /
 *  Partially Paid) total - paid; PO payables = received value not yet paid; budget available = amount - reserved - spent.
 * =====================================================================
 */
declare(strict_types=1);
core('Settings');

final class Oversight {
    private const ACTIVE_TRIPS = ['Dispatched', 'In Transit'];
    private const SCHEDULED_TRIPS = ['Pending Review', 'Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch'];
    private const TRIP_EXCEPTIONS = ['On Hold - Incident Reported', 'For Reschedule'];

    public static function read(array $u, string $page, array $q): array {
        requireRole($u, 'admin');
        $p = db();
        $data = match ($page) {
            'admin_overview' => self::overview($u, $p),
            'admin_approvals' => readSections(['queue' => fn() => self::queue($u, $p, true)]),
            'admin_operations' => self::operations($p),
            'admin_maintenance' => self::maintenance($u, $p),
            'admin_purchasing' => self::purchasing($u, $p),
            'admin_finance' => self::finance($p, $q),
            'admin_reports' => self::executive($u, $p),
            'admin_analytics' => self::analytics($u, $p, $q),
            default => throw new BusinessRuleException('Unknown page.', 404),
        };
        $data['me'] = (int)$u['id'];
        return $data;
    }
    public static function action(array $u, string $action, array $d): array {
        throw new BusinessRuleException('Unknown action.', 404);   // read only: decisions use the owning modules' actions
    }

    private static function tableExists(PDO $p, string $t): bool {
        return (bool)one($p, 'SELECT 1 AS ok FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$t]);
    }
    private static function n(PDO $p, string $sql, array $a = []): float { return (float)(one($p, $sql, $a)['n'] ?? 0); }

    /**
     * Unified approval queue. 'actionable' = the Admin may decide it now (pending AND not their own request).
     * Decided rows of the last 90 days are included when $history is true (status tabs).
     */
    public static function queue(array $u, PDO $p, bool $history): array {
        $me = (int)$u['id']; $rows = [];
        $hist = $history ? " OR decided_at >= NOW() - INTERVAL 90 DAY" : '';
        foreach (many($p, "SELECT o.id, o.po_ref, o.status, o.total_amount, o.created_at, o.decided_at, o.decision_notes, o.purpose,
                    COALESCE(o.supplier_name, s.name) AS supplier, ru.full_name AS requester, du.full_name AS decider
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id LEFT JOIN users ru ON ru.id=o.requested_by LEFT JOIN users du ON du.id=o.decided_by
                WHERE o.approver_role='admin' AND (o.status='Pending Approval'$hist) ORDER BY o.id DESC LIMIT 300") as $o) {
            $pending = $o['status'] === 'Pending Approval';
            $rows[] = ['key' => 'po:' . $o['id'], 'kind' => 'po', 'id' => (int)$o['id'], 'ref' => $o['po_ref'], 'type' => 'Purchase order above the approval limit',
                'dept' => 'Purchasing', 'requester' => $o['requester'], 'submitted' => $o['created_at'], 'amount' => (float)$o['total_amount'],
                'status' => $pending ? 'Pending' : $o['status'], 'raw_status' => $o['status'], 'summary' => trim(($o['supplier'] ?? '') . ' · ' . ($o['purpose'] ?? ''), ' ·'),
                'next' => $pending ? 'Admin' : (in_array($o['status'], ['Approved', 'Partially Received'], true) ? 'Purchasing (receiving)' : '—'),
                'decided_at' => $o['decided_at'], 'decider' => $o['decider'], 'notes' => $o['decision_notes'], 'actionable' => $pending, 'go' => 'purchase_orders?open=' . $o['id']];
        }
        Actions::load('Purchasing');
        if (Purchasing::countsReady($p)) {
            $hist2 = $history ? " OR c.reviewed_at >= NOW() - INTERVAL 90 DAY" : '';
            foreach (many($p, "SELECT c.id, c.count_ref, c.status, c.counted_at, c.reviewed_at, c.review_notes, c.counted_by, c.system_qty, c.counted_qty, c.variance, c.reason,
                        pt.part_number, pt.unit, ROUND(ABS(c.variance)*pt.unit_cost,2) AS value, cu.full_name AS requester, ru.full_name AS decider
                    FROM stock_counts c JOIN parts pt ON pt.id=c.part_id JOIN users cu ON cu.id=c.counted_by LEFT JOIN users ru ON ru.id=c.reviewed_by
                    WHERE c.status='Submitted'$hist2 ORDER BY c.id DESC LIMIT 300") as $c) {
                $pending = $c['status'] === 'Submitted';
                $rows[] = ['key' => 'count:' . $c['id'], 'kind' => 'count', 'id' => (int)$c['id'], 'ref' => $c['count_ref'], 'type' => 'Stock count adjustment',
                    'dept' => 'Purchasing', 'requester' => $c['requester'], 'submitted' => $c['counted_at'], 'amount' => (float)$c['value'],
                    'status' => $pending ? 'Pending' : $c['status'], 'raw_status' => $c['status'],
                    'summary' => $c['part_number'] . ': counted ' . $c['counted_qty'] . ' vs system ' . $c['system_qty'] . ' ' . $c['unit'] . ' · ' . $c['reason'],
                    'next' => $pending ? 'Admin' : '—', 'decided_at' => $c['reviewed_at'], 'decider' => $c['decider'], 'notes' => $c['review_notes'],
                    'actionable' => $pending && (int)$c['counted_by'] !== $me, 'own' => (int)$c['counted_by'] === $me, 'go' => 'inventory?view=counts&open=' . $c['id']];
            }
        }
        if (self::tableExists($p, 'billing_charges')) {
            $hist3 = $history ? " OR bc.decided_at >= NOW() - INTERVAL 90 DAY" : '';
            foreach (many($p, "SELECT bc.id, bc.billing_id, bc.kind, bc.request_type, bc.description, bc.amount, bc.status, bc.reason, bc.created_at, bc.decided_at, bc.decision_notes, bc.added_by,
                        t.trip_ref, au.full_name AS requester, du.full_name AS decider
                    FROM billing_charges bc JOIN billings b ON b.id=bc.billing_id JOIN trips t ON t.id=b.trip_id JOIN users au ON au.id=bc.added_by LEFT JOIN users du ON du.id=bc.decided_by
                    WHERE bc.status='Pending Approval'$hist3 ORDER BY bc.id DESC LIMIT 300") as $c) {
                $pending = $c['status'] === 'Pending Approval';
                $rows[] = ['key' => 'charge:' . $c['id'], 'kind' => 'charge', 'id' => (int)$c['id'], 'billing_id' => (int)$c['billing_id'], 'ref' => $c['trip_ref'],
                    'type' => 'Billing ' . strtolower($c['kind']) . ' (' . strtolower($c['request_type']) . ')', 'dept' => 'Billing', 'requester' => $c['requester'],
                    'submitted' => $c['created_at'], 'amount' => (float)$c['amount'], 'status' => $pending ? 'Pending' : $c['status'], 'raw_status' => $c['status'],
                    'summary' => $c['description'] . ($c['reason'] ? ' · ' . $c['reason'] : ''), 'next' => $pending ? 'Admin' : '—',
                    'decided_at' => $c['decided_at'], 'decider' => $c['decider'], 'notes' => $c['decision_notes'],
                    'actionable' => $pending && (int)$c['added_by'] !== $me, 'own' => (int)$c['added_by'] === $me, 'go' => 'billing?open=' . $c['billing_id']];
            }
        }
        usort($rows, fn($a, $b) => [$b['status'] === 'Pending', $b['submitted']] <=> [$a['status'] === 'Pending', $a['submitted']]);
        return $rows;
    }

    /** Current-state open items by recorded status (no invented deadlines; each row links to the filtered record list). */
    private static function exceptions(PDO $p): array {
        $out = [];
        $add = function (float $n, string $title, string $sub, string $go, string $tone = 'amber') use (&$out) { if ($n > 0) $out[] = ['n' => (int)$n, 'title' => $title, 'sub' => $sub, 'go' => $go, 'tone' => $tone]; };
        $add(self::n($p, "SELECT COUNT(*) AS n FROM maintenance_jobs WHERE open_key=1 AND status='Completed'"),
            'Repairs completed, awaiting verification', 'Trucks stay out of service until Motorpool verifies them', 'maintenance?view=verify');
        $add(self::n($p, "SELECT COUNT(*) AS n FROM maintenance_jobs WHERE open_key=1 AND status='Reported'"),
            'Repair reports without an assigned mechanic', 'Status: Reported', 'maintenance?view=reported');
        $add(self::n($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status='Rejected - Insufficient Budget' AND decided_at >= NOW() - INTERVAL 30 DAY"),
            'Purchase orders rejected for insufficient budget (last 30 days)', 'Status: Rejected - Insufficient Budget', 'purchase_orders?tab=rejected', 'red');
        $add(self::n($p, "SELECT COUNT(*) AS n FROM part_requests WHERE status IN ('Not Available','Partially Released')"),
            'Parts requests short of stock', 'Status: Not Available or Partially Released', 'parts_requests?tab=followup');
        $add(self::n($p, "SELECT COUNT(*) AS n FROM trips WHERE status IN ('On Hold - Incident Reported','For Reschedule')"),
            'Trips on hold or for reschedule', 'Status: On Hold - Incident Reported or For Reschedule', 'admin_operations', 'red');
        $add(self::n($p, "SELECT COUNT(*) AS n FROM billings WHERE status IN ('Invoiced','Partially Paid') AND due_date < CURRENT_DATE()"),
            'Overdue invoices', 'Receivables past their due date', 'billing_receivables', 'red');
        $b = one($p, "SELECT amount, reserved_amount, spent_amount, (CURRENT_DATE() BETWEEN period_start AND period_end) AS in_period FROM budgets WHERE status='Active' AND category='Purchasing' ORDER BY id DESC LIMIT 1");
        if (!$b) $out[] = ['n' => 1, 'title' => 'No active purchasing budget', 'sub' => 'Purchase orders cannot be approved until Finance sets one', 'go' => 'finance_budgets', 'tone' => 'red'];
        elseif (!(int)$b['in_period']) $out[] = ['n' => 1, 'title' => 'Purchasing budget period has ended', 'sub' => 'Finance must set a new budget', 'go' => 'finance_budgets', 'tone' => 'red'];
        elseif ((float)$b['amount'] > 0 && ((float)$b['spent_amount'] + (float)$b['reserved_amount']) / (float)$b['amount'] >= 0.8)
            $out[] = ['n' => (int)round(((float)$b['spent_amount'] + (float)$b['reserved_amount']) / (float)$b['amount'] * 100), 'title' => 'Purchasing budget at least 80% used', 'sub' => 'Paid plus reserved by approved POs (percent); Finance alert level 80%', 'go' => 'admin_finance'];
        return $out;
    }

    private static function overview(array $u, PDO $p): array {
        $data = readSections([
            'kpis' => fn() => [
                'approvals' => count(array_filter(self::queue($u, $p, false), fn($r) => $r['actionable'])),
                'active_trips' => (int)self::n($p, "SELECT COUNT(*) AS n FROM trips WHERE status IN ('Dispatched','In Transit')"),
                'trucks_repair' => (int)self::n($p, "SELECT COUNT(*) AS n FROM vehicles WHERE active=1 AND status='Under Maintenance'"),
                'open_pos' => (int)self::n($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status IN ('Pending Approval','Approved','Partially Received')"),
                'receivables' => self::n($p, "SELECT COALESCE(SUM(total_amount-amount_paid),0) AS n FROM billings WHERE status IN ('Invoiced','Partially Paid')"),
                'low_stock' => (int)self::n($p, "SELECT COUNT(*) AS n FROM parts WHERE active=1 AND reorder_level>0 AND quantity_on_hand<=reorder_level"),
            ],
            'attention' => fn() => self::exceptions($p),
            'decisions' => fn() => array_values(array_filter(self::queue($u, $p, false), fn($r) => $r['status'] === 'Pending')),
            'activity' => fn() => many($p, "SELECT a.id, a.area, a.action, a.record_ref, a.created_at, COALESCE(us.full_name,'System') AS actor, us.role FROM audit_events a
                LEFT JOIN users us ON us.id=a.actor_id WHERE a.area NOT IN ('Access') ORDER BY a.id DESC LIMIT 15"),
            'departments' => fn() => self::departments($p),
        ]);
        return $data;
    }

    /** Short per-department status: three current figures each (same definitions as the department pages). */
    private static function departments(PDO $p): array {
        $in = fn(array $s) => "('" . implode("','", $s) . "')";
        return [
            ['key' => 'operations', 'name' => 'Operations', 'go' => 'admin_operations', 'items' => [
                ['Active trips', (int)self::n($p, 'SELECT COUNT(*) AS n FROM trips WHERE status IN ' . $in(self::ACTIVE_TRIPS))],
                ['Scheduled / being prepared', (int)self::n($p, 'SELECT COUNT(*) AS n FROM trips WHERE status IN ' . $in(self::SCHEDULED_TRIPS))],
                ['On hold or for reschedule', (int)self::n($p, 'SELECT COUNT(*) AS n FROM trips WHERE status IN ' . $in(self::TRIP_EXCEPTIONS))]]],
            ['key' => 'maintenance', 'name' => 'Motorpool', 'go' => 'admin_maintenance', 'items' => [
                ['Open repairs', (int)self::n($p, 'SELECT COUNT(*) AS n FROM maintenance_jobs WHERE open_key=1')],
                ['Awaiting verification', (int)self::n($p, "SELECT COUNT(*) AS n FROM maintenance_jobs WHERE open_key=1 AND status='Completed'")],
                ['Parts requests open', (int)self::n($p, "SELECT COUNT(*) AS n FROM part_requests WHERE status IN ('Requested','Partially Released','Not Available')")]]],
            ['key' => 'purchasing', 'name' => 'Purchasing', 'go' => 'admin_purchasing', 'items' => [
                ['Requests to process', (int)self::n($p, "SELECT COUNT(*) AS n FROM part_requests WHERE status='Requested'")],
                ['POs awaiting approval', (int)self::n($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status='Pending Approval'")],
                ['Low-stock parts', (int)self::n($p, 'SELECT COUNT(*) AS n FROM parts WHERE active=1 AND reorder_level>0 AND quantity_on_hand<=reorder_level')]]],
            ['key' => 'finance', 'name' => 'Accounting', 'go' => 'admin_finance', 'items' => [
                ['PO payables (received, unpaid)', (int)self::n($p, "SELECT COUNT(*) AS n FROM purchase_orders o WHERE o.payment_status IN ('Pending Payment','Partially Paid')
                    AND (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) > o.paid_amount")],
                ['Open invoices', (int)self::n($p, "SELECT COUNT(*) AS n FROM billings WHERE status IN ('Invoiced','Partially Paid')")],
                ['Overdue invoices', (int)self::n($p, "SELECT COUNT(*) AS n FROM billings WHERE status IN ('Invoiced','Partially Paid') AND due_date < CURRENT_DATE()")]]],
        ];
    }

    /**
     * Operations monitoring (read only). Trips Needing Attention = trips whose CURRENT status is one of the Operations
     * statuses that mean the trip cannot proceed (On Hold - Incident Reported, For Reschedule, Awaiting Resources), plus
     * trips with an unresolved incident (incidents.resolved_at IS NULL). Division = the dispatcher role of the trip.
     */
    private const ATTENTION_TRIPS = ['On Hold - Incident Reported', 'For Reschedule', 'Awaiting Resources'];
    private static function operations(PDO $p): array {
        $att = "'" . implode("','", self::ATTENTION_TRIPS) . "'";
        return readSections([
            'statusCounts' => fn() => many($p, 'SELECT status, COUNT(*) AS n FROM trips GROUP BY status'),
            'trips' => fn() => many($p, "SELECT t.id, t.trip_ref, t.status, t.truck_type, t.scheduled_at, t.dispatched_at, t.completed_at, t.pickup_point, t.delivery_point,
                    t.dispatcher_role, du.full_name AS dispatcher, du.shift AS dispatcher_shift, c.company_name AS client, v.plate_number
                FROM trips t LEFT JOIN clients c ON c.id=t.client_id LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN users du ON du.id=t.dispatcher_id
                WHERE t.status NOT IN ('Completed','Cancelled','Rejected') OR (t.status='Completed' AND t.completed_at >= NOW() - INTERVAL 30 DAY)
                ORDER BY FIELD(t.status,'On Hold - Incident Reported','For Reschedule','In Transit','Dispatched'), t.scheduled_at LIMIT 500"),
            'attention' => fn() => many($p, "SELECT t.id, t.trip_ref, t.status, t.truck_type, t.dispatcher_role, du.full_name AS dispatcher, du.shift AS dispatcher_shift, t.updated_at,
                    (SELECT i.incident_ref FROM incidents i WHERE i.trip_id=t.id AND i.resolved_at IS NULL ORDER BY i.id DESC LIMIT 1) AS open_incident,
                    (SELECT i.incident_type FROM incidents i WHERE i.trip_id=t.id AND i.resolved_at IS NULL ORDER BY i.id DESC LIMIT 1) AS incident_type
                FROM trips t LEFT JOIN users du ON du.id=t.dispatcher_id
                WHERE t.status IN ($att) OR EXISTS (SELECT 1 FROM incidents i WHERE i.trip_id=t.id AND i.resolved_at IS NULL AND t.status NOT IN ('Completed','Cancelled','Rejected'))
                ORDER BY FIELD(t.status,'On Hold - Incident Reported','For Reschedule','Awaiting Resources'), t.updated_at LIMIT 100"),
            'completed30' => fn() => (int)self::n($p, "SELECT COUNT(*) AS n FROM trips WHERE status='Completed' AND completed_at >= NOW() - INTERVAL 30 DAY"),
            'fleet' => fn() => many($p, 'SELECT status, COUNT(*) AS n FROM vehicles WHERE active=1 GROUP BY status ORDER BY n DESC'),
            'funds' => fn() => one($p, "SELECT COALESCE(SUM(status='Pending Approval'),0) AS pending, COALESCE(SUM(status='Ready for Release'),0) AS to_release FROM fund_requests") ?? ['pending' => 0, 'to_release' => 0],
        ]) + ['groups' => ['active' => self::ACTIVE_TRIPS, 'scheduled' => self::SCHEDULED_TRIPS, 'exceptions' => self::TRIP_EXCEPTIONS]];
    }

    /**
     * Maintenance monitoring (read only). Repairs Needing Attention = open repairs that are Completed but not verified
     * (truck still out of service; days since completed_on), or Under Inspection / Under Repair with an open linked parts
     * request (Requested / Partially Released / Not Available). Truck status = trucks with an open repair.
     */
    private static function maintenance(array $u, PDO $p): array {
        Actions::load('Maintenance');
        $o = Maintenance::read($u, 'maintenance_overview', []);
        return ['summary' => $o['summary'] ?? [], 'fleet' => $o['fleet'] ?? []] + readSections([
            'attention' => fn() => many($p, "SELECT m.id, m.job_ref, m.status, m.completed_on, m.reported_on, v.plate_number, v.truck_type,
                    CASE WHEN m.status='Completed' THEN 'verify' ELSE 'parts' END AS reason,
                    (SELECT GROUP_CONCAT(r.request_ref, ' (', r.status, ')' SEPARATOR ', ') FROM part_requests r WHERE r.maintenance_job_id=m.id AND r.status IN ('Requested','Partially Released','Not Available')) AS requests,
                    DATEDIFF(CURRENT_DATE(), COALESCE(m.completed_on, m.reported_on)) AS days
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id
                WHERE m.open_key=1 AND (m.status='Completed' OR (m.status IN ('Under Inspection','Under Repair')
                    AND EXISTS (SELECT 1 FROM part_requests r WHERE r.maintenance_job_id=m.id AND r.status IN ('Requested','Partially Released','Not Available'))))
                ORDER BY m.status='Completed' DESC, days DESC"),
            'jobs' => fn() => many($p, "SELECT m.id, m.job_ref, m.status, m.source, m.problem, m.reported_on, m.completed_on, m.verified_at, v.plate_number, v.truck_type, mc.full_name AS mechanic,
                    (SELECT COUNT(*) FROM part_requests r WHERE r.maintenance_job_id=m.id AND r.status IN ('Requested','Partially Released','Not Available')) AS open_parts
                FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id LEFT JOIN mechanics mc ON mc.id=m.mechanic_id
                WHERE m.open_key=1 OR m.reported_on >= CURRENT_DATE() - INTERVAL 90 DAY ORDER BY m.open_key IS NULL, m.reported_on DESC LIMIT 500"),
        ]);
    }

    /**
     * Purchasing monitoring (read only). Purchasing Issues = POs rejected for insufficient budget in the last 30 days
     * (next: Finance budget or a new PO by Purchasing), parts requests short of stock (next: Purchasing), and parts whose
     * on-hand quantity differs from their movement ledger (next: Purchasing stock count). Delivery delays are NOT listed:
     * purchase orders record no expected delivery date. Stock counts waiting for the Admin are on the Approvals page.
     */
    private static function purchasing(array $u, PDO $p): array {
        Actions::load('Purchasing');
        $a = Purchasing::analytics($u, []);
        $issues = [];
        foreach (many($p, "SELECT id, po_ref, total_amount, decided_at, decision_notes FROM purchase_orders WHERE status='Rejected - Insufficient Budget' AND decided_at >= NOW() - INTERVAL 30 DAY ORDER BY decided_at DESC") as $o)
            $issues[] = ['ref' => $o['po_ref'], 'reason' => 'Rejected: the purchasing budget did not cover ' . number_format((float)$o['total_amount'], 2), 'status' => 'Rejected - Insufficient Budget',
                'next' => 'Finance (budget) / Purchasing (new PO if still needed)', 'since' => $o['decided_at'], 'go' => 'purchase_orders?open=' . $o['id'], 'tone' => 'red'];
        foreach (many($p, "SELECT r.id, r.request_ref, r.status, r.created_at, v.plate_number FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id
                WHERE r.status IN ('Partially Released','Not Available') ORDER BY r.created_at") as $r)
            $issues[] = ['ref' => $r['request_ref'], 'reason' => 'Parts request for ' . $r['plate_number'] . ' is short of stock', 'status' => $r['status'],
                'next' => 'Purchasing (release or order the shortage)', 'since' => $r['created_at'], 'go' => 'parts_requests?open=' . $r['id'], 'tone' => 'amber'];
        foreach ($a['ledger'] ?? [] as $l)
            $issues[] = ['ref' => $l['part_number'], 'reason' => 'On hand ' . $l['quantity_on_hand'] . ' does not match the stock movement ledger (' . $l['ledger'] . ')', 'status' => 'Ledger mismatch',
                'next' => 'Purchasing (stock count)', 'since' => null, 'go' => 'inventory?open=' . $l['id'], 'tone' => 'red'];
        return readSections([
            'requests' => fn() => many($p, "SELECT r.id, r.request_ref, r.status, r.created_at, v.plate_number FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id
                WHERE r.status IN ('Requested','Partially Released','Not Available') ORDER BY r.created_at"),
            'lowStock' => fn() => many($p, 'SELECT id, part_number, description, unit, quantity_on_hand, reorder_level FROM parts WHERE active=1 AND reorder_level>0 AND quantity_on_hand<=reorder_level ORDER BY quantity_on_hand-reorder_level, part_number'),
            'orders' => fn() => many($p, "SELECT o.id, o.po_ref, o.status, o.payment_status, o.approver_role, o.total_amount, o.created_at, o.decided_at, COALESCE(o.supplier_name, s.name) AS supplier,
                    (SELECT COALESCE(SUM(l.received_qty),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) AS received_units,
                    (SELECT COALESCE(SUM(l.quantity),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) AS ordered_units,
                    (SELECT COUNT(*) FROM documents dc WHERE dc.entity_type='purchase_order' AND dc.entity_id=o.id) AS documents
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id ORDER BY o.id DESC LIMIT 500"),
        ]) + ['issues' => $issues, 'followUp' => $a['followUp'] ?? [], 'partTips' => $a['partTips'] ?? []];
    }

    /** Period from ?from=&to= (default: this month), at most 731 days. */
    private static function period(array $q, string $defaultFrom): array {
        $date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $from = $date($q['from'] ?? '') ?? $defaultFrom; $to = $date($q['to'] ?? '') ?? date('Y-m-d');
        if ($to < $from) [$from, $to] = [$to, $from];
        if ((strtotime($to) - strtotime($from)) / 86400 > 731) $from = date('Y-m-d', strtotime($to . ' -731 days'));
        return [$from, $to];
    }

    /**
     * WEEKLY_BUDGET_V1 — week = Monday 00:00 .. Sunday 23:59:59 in the app timezone (APP_TIMEZONE = Asia/Manila). The
     * application has no other week definition (none in Finance, Billing or Core).
     */
    public static function weekOf(string $date): array {
        $d = new DateTimeImmutable($date);
        $mon = $d->modify('-' . ((int)$d->format('N') - 1) . ' days');
        return ['from' => $mon->format('Y-m-d'), 'to' => $mon->modify('+6 days')->format('Y-m-d')];
    }

    /**
     * Financial oversight around the WEEKLY budget policy:
     *   current week starting budget = previous week's client collections + previous week's unused budget carried forward.
     * What the records support today (read only):
     *   previous-week collections   collections.payment_date in the previous week (actual client payments)
     *   payments this week          disbursements.released_on in the selected week (cash paid; PO, trip fund, payable)
     *   outstanding commitments     approved POs with reserved_amount > 0 (approved, not yet paid; current)
     *   a paid PO moves from commitment to payment (Finance::po_pay reduces reserved_amount), so nothing is counted twice.
     * NOT recorded anywhere yet: a weekly allocation and the carried-forward unused balance. Starting budget and available
     * budget are therefore reported as "not established" (no invented balance). The monthly Purchasing budget that Finance
     * sets (budgets table) is shown separately and is NOT treated as the weekly budget.
     */
    private static function finance(PDO $p, array $q): array {
        $date = is_string($q['week'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $q['week']) ? $q['week'] : date('Y-m-d');
        $wk = self::weekOf($date); $prev = self::weekOf(date('Y-m-d', strtotime($wk['from'] . ' -7 days')));
        $d = readSections([
            'prevCollections' => fn() => many($p, "SELECT co.id, co.payment_date, co.amount, co.payment_method, co.reference AS reference_number, co.ar_number, b.invoice_number, c.company_name AS client
                FROM collections co JOIN billings b ON b.id=co.billing_id JOIN clients c ON c.id=b.client_id WHERE co.payment_date BETWEEN ? AND ? ORDER BY co.payment_date, co.id", [$prev['from'], $prev['to']]),
            'weekCollections' => fn() => many($p, "SELECT co.id, co.payment_date, co.amount, co.payment_method, co.reference AS reference_number, co.ar_number, b.invoice_number, c.company_name AS client
                FROM collections co JOIN billings b ON b.id=co.billing_id JOIN clients c ON c.id=b.client_id WHERE co.payment_date BETWEEN ? AND ? ORDER BY co.payment_date, co.id", [$wk['from'], $wk['to']]),
            'payments' => fn() => many($p, "SELECT d.id, d.voucher_number, d.source_type, d.source_id, d.payee, d.payment_method, d.amount, d.released_on,
                    CASE d.source_type WHEN 'purchase_order' THEN (SELECT po_ref FROM purchase_orders WHERE id=d.source_id) WHEN 'fund_request' THEN (SELECT fund_ref FROM fund_requests WHERE id=d.source_id)
                        WHEN 'payable' THEN (SELECT payable_ref FROM payables WHERE id=d.source_id) END AS source_ref
                FROM disbursements d WHERE d.released_on BETWEEN ? AND ? ORDER BY d.released_on, d.id", [$wk['from'], $wk['to']]),
            'commitments' => fn() => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.status, o.decided_at, o.reserved_amount, o.paid_amount,
                    (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) AS received_value
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.reserved_amount > 0 AND o.status IN ('Approved','Partially Received','Received') ORDER BY o.decided_at"),
            'payables' => fn() => many($p, "SELECT id, payable_ref, payee, category, due_on, amount, paid_amount, amount-paid_amount AS balance, status,
                    DATEDIFF(CURRENT_DATE(), due_on) AS days_overdue FROM payables WHERE status IN ('Unpaid','Partially Paid') ORDER BY due_on, id"),
            'poPayables' => fn() => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.payment_status, o.total_amount, o.paid_amount,
                    (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) AS received_value,
                    (SELECT MAX(sm.created_at) FROM stock_movements sm WHERE sm.reference_type='purchase_order' AND sm.reference_id=o.id AND sm.movement_type='Stock In') AS last_received
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.payment_status IN ('Pending Payment','Partially Paid')
                HAVING received_value > paid_amount ORDER BY o.id"),
            'receivables' => fn() => many($p, "SELECT b.id, b.invoice_number, b.status, b.invoice_date, b.due_date, b.total_amount, b.amount_paid, b.total_amount-b.amount_paid AS balance,
                    DATEDIFF(CURRENT_DATE(), b.due_date) AS days_overdue, c.company_name AS client, t.trip_ref
                FROM billings b JOIN clients c ON c.id=b.client_id LEFT JOIN trips t ON t.id=b.trip_id WHERE b.status IN ('Invoiced','Partially Paid') ORDER BY b.due_date"),
            'decisions' => fn() => array_merge(
                many($p, "SELECT 'Purchase order (Finance approval)' AS kind, o.id, o.po_ref AS ref, o.total_amount AS amount, o.created_at AS since, 'purchase_orders?open=' AS go_base
                    FROM purchase_orders o WHERE o.status='Pending Approval' AND o.approver_role='finance' ORDER BY o.created_at"),
                many($p, "SELECT 'Trip fund (release by Finance)' AS kind, f.id, f.fund_ref AS ref, f.amount, f.approved_at AS since, 'funds?open=' AS go_base
                    FROM fund_requests f WHERE f.status='Ready for Release' ORDER BY f.approved_at")),
            'monthlyBudget' => fn() => one($p, "SELECT *, amount-reserved_amount-spent_amount AS available, (CURRENT_DATE() BETWEEN period_start AND period_end) AS in_period
                FROM budgets WHERE status='Active' AND category='Purchasing' ORDER BY id DESC LIMIT 1") ?? [],
            'dueWindow' => fn() => one($p, "SELECT COALESCE(SUM(total_amount),0) AS due, COALESCE(SUM(amount_paid),0) AS paid, COUNT(*) AS n FROM billings
                WHERE status IN ('Invoiced','Partially Paid','Paid') AND due_date BETWEEN CURRENT_DATE() - INTERVAL 30 DAY AND CURRENT_DATE() - INTERVAL 1 DAY"),
        ]);
        $sum = fn(array $rows, string $k) => round(array_sum(array_map(fn($r) => (float)$r[$k], $rows)), 2);
        $d['week'] = $wk; $d['prevWeek'] = $prev;
        $d['weekly'] = [
            'prev_collections' => $sum($d['prevCollections'] ?? [], 'amount'),
            'carry_forward' => null, 'carry_forward_reason' => 'No weekly allocation or closing balance is recorded yet (no table or Finance step stores it).',
            'starting_budget' => null,
            'payments' => $sum($d['payments'] ?? [], 'amount'),
            'commitments' => $sum($d['commitments'] ?? [], 'reserved_amount'),
            'available' => null,
        ];
        $d['weekly']['paid_committed'] = round($d['weekly']['payments'] + $d['weekly']['commitments'], 2);
        $d['suggestions'] = self::financeRules($d + ['budget' => $d['monthlyBudget'] ?? []], date('Y-m-d'));
        return $d;
    }

    /** Existing Finance budget alert rule (finance.js: "alert at 80%"): paid + reserved >= 80% of the budget. */
    public const BUDGET_ALERT = 0.8;
    /**
     * Rule-based financial suggestions (no forecast, nothing executed). Each needs the records it talks about.
     *  weekly      weekly starting budget known AND (available < 0 or commitments > available)   (not possible until recorded)
     *  budget      Finance's MONTHLY Purchasing budget: none / period ended / paid + committed >= BUDGET_ALERT
     *  payables    other payables past their recorded due date, or due within the next 7 days
     *  receivables invoices past their recorded due date
     *  UNSUPPORTED_CLASSIFICATION_CLEANUP: no waiting-time, "unpaid for 30 days" or collection-rate thresholds (no company policy;
     *  POs record no payment due date). Pending Finance decisions are listed with their dates in Financial Oversight.
     */
    public static function financeRules(array $d, string $today): array {
        $out = ['weekly' => [], 'budget' => [], 'payables' => [], 'receivables' => []];
        $w = $d['weekly'] ?? [];
        if (($w['starting_budget'] ?? null) !== null) {
            $avail = (float)$w['starting_budget'] - (float)$w['payments'] - (float)$w['commitments'];
            if ($avail < 0) $out['weekly'][] = ['text' => 'This week\'s paid spending and commitments exceed the weekly starting budget by ' . number_format(-$avail, 2) . '.', 'go' => 'finance_budgets', 'label' => 'Budgets'];
        }
        $b = $d['budget'] ?? [];
        if (!$b) $out['budget'][] = ['text' => 'No active monthly Purchasing budget: purchase orders cannot be approved until Finance sets one.', 'go' => 'finance_budgets', 'label' => 'Budgets'];
        elseif (!(int)$b['in_period']) $out['budget'][] = ['text' => 'The monthly Purchasing budget period ended on ' . $b['period_end'] . ': Finance must set a new one.', 'go' => 'finance_budgets', 'label' => 'Budgets'];
        elseif ((float)$b['amount'] > 0) {
            $used = ((float)$b['spent_amount'] + (float)$b['reserved_amount']) / (float)$b['amount'];
            if ($used >= self::BUDGET_ALERT) $out['budget'][] = ['text' => 'Monthly Purchasing budget is ' . round($used * 100) . '% paid or committed (Finance alert at ' . (int)(self::BUDGET_ALERT * 100) . '%).', 'go' => 'finance_budgets', 'label' => 'Budget'];
        }
        foreach ($d['payables'] ?? [] as $x) {
            $days = (int)$x['days_overdue'];
            if ($days > 0) $out['payables'][] = ['text' => $x['payable_ref'] . ' (' . $x['payee'] . ') is ' . $days . ' day(s) past due: balance ' . number_format((float)$x['balance'], 2) . '.', 'go' => 'finance', 'label' => 'Payables'];
            elseif ($days >= -7) $out['payables'][] = ['text' => $x['payable_ref'] . ' (' . $x['payee'] . ') is due on ' . $x['due_on'] . '.', 'go' => 'finance', 'label' => 'Payables'];
        }
        $over = array_filter($d['receivables'] ?? [], fn($x) => (int)$x['days_overdue'] > 0);
        if ($over) $out['receivables'][] = ['text' => count($over) . ' invoice(s) have an outstanding balance past the due date (' . number_format(array_sum(array_map(fn($x) => (float)$x['balance'], $over)), 2) . '); the oldest due date was ' . max(array_map(fn($x) => (int)$x['days_overdue'], $over)) . ' day(s) ago. Follow up with Billing.', 'go' => 'billing_receivables', 'label' => 'Receivables'];
        return $out;
    }

    /**
     * Executive Reports: items requiring attention, from recorded statuses and dates only (UNSUPPORTED_CLASSIFICATION_CLEANUP:
     * no High / Medium priority, no risk score, no invented waiting-time deadline). Groups:
     *   waiting  approvals assigned to Head Management (pending queue rows)
     *   issues   open department issues by recorded status: trips that cannot proceed (On Hold - Incident Reported, For
     *            Reschedule, Awaiting Resources); repairs completed and awaiting verification; POs rejected for insufficient
     *            budget (last 30 days); invoices and payables past their recorded due date; the Finance budget alert (80%) and
     *            the weekly budget rule when a weekly allocation is recorded
     *   recent   the latest recorded management decisions (approval queue, decided rows)
     * Each record appears once; rows are ordered by group, then by their recorded date (oldest first).
     */
    private static function executive(array $u, PDO $p): array {
        $fin = self::finance($p, []);
        $items = [];
        $add = function (string $group, string $dept, string $ref, string $issue, string $status, string $reason, string $next, ?string $since, string $go, bool $decide = false) use (&$items) {
            $items[] = compact('group', 'dept', 'ref', 'issue', 'status', 'reason', 'next', 'since', 'go', 'decide'); };
        $queue = self::queue($u, $p, true);
        foreach ($queue as $r) if ($r['status'] === 'Pending')
            $add('waiting', $r['dept'], $r['ref'], $r['type'], 'Pending', $r['summary'] ?: 'Waiting for a decision', $r['actionable'] ? 'You (Admin): approve or reject' : 'Another Admin (you requested it)', $r['submitted'], 'admin_approvals?open=' . $r['key'], $r['actionable']);
        foreach (many($p, "SELECT t.id, t.trip_ref, t.status, t.updated_at FROM trips t WHERE t.status IN ('" . implode("','", self::ATTENTION_TRIPS) . "') ORDER BY t.updated_at") as $t)
            $add('issues', 'Operations', $t['trip_ref'], 'Trip cannot proceed', $t['status'], 'Recorded trip status', 'Operations Head / dispatcher', $t['updated_at'], 'trips?open=' . $t['id']);
        foreach (many($p, "SELECT m.id, m.job_ref, m.completed_on, v.plate_number FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id
                WHERE m.open_key=1 AND m.status='Completed' ORDER BY m.completed_on") as $m)
            $add('issues', 'Maintenance', $m['job_ref'], 'Repair awaiting verification', 'Completed, not verified', $m['plate_number'] . ' stays out of service until verified', 'Motorpool: verify and return to service', $m['completed_on'], 'maintenance?open=' . $m['id']);
        foreach (many($p, "SELECT id, po_ref, total_amount, decided_at FROM purchase_orders WHERE status='Rejected - Insufficient Budget' AND decided_at >= NOW() - INTERVAL 30 DAY ORDER BY decided_at") as $o)
            $add('issues', 'Purchasing', $o['po_ref'], 'Purchase order rejected by the budget check', 'Rejected - Insufficient Budget', 'Total ' . number_format((float)$o['total_amount'], 2) . ' exceeded the available budget', 'Finance (budget) / Purchasing (new PO)', $o['decided_at'], 'purchase_orders?open=' . $o['id']);
        foreach ($fin['receivables'] ?? [] as $x) if ((int)$x['days_overdue'] > 0)
            $add('issues', 'Billing', (string)($x['invoice_number'] ?: $x['trip_ref']), 'Invoice past its due date', $x['status'], $x['client'] . ': ' . number_format((float)$x['balance'], 2) . ' outstanding, due ' . $x['due_date'], 'Billing: collect', $x['due_date'], 'billing_receivables');
        foreach ($fin['payables'] ?? [] as $x) if ((int)$x['days_overdue'] > 0)
            $add('issues', 'Finance', $x['payable_ref'], 'Payable past its due date', $x['status'], $x['payee'] . ': ' . number_format((float)$x['balance'], 2) . ' outstanding, due ' . $x['due_on'], 'Finance: pay', $x['due_on'], 'finance');
        foreach (['budget', 'weekly'] as $k) foreach ($fin['suggestions'][$k] ?? [] as $s)
            $add('issues', 'Finance', $k === 'budget' ? 'Purchasing budget' : 'Weekly budget', $k === 'budget' ? 'Finance budget rule' : 'Weekly budget rule', 'Rule met', $s['text'], 'Finance', null, $s['go']);
        $order = ['waiting' => 0, 'issues' => 1];
        usort($items, fn($a, $b) => [$order[$a['group']], (string)$a['since']] <=> [$order[$b['group']], (string)$b['since']]);
        $recent = array_slice(array_values(array_filter($queue, fn($r) => $r['status'] !== 'Pending' && $r['decided_at'])), 0, 50);
        usort($recent, fn($a, $b) => strcmp((string)$b['decided_at'], (string)$a['decided_at']));
        $recent = array_map(fn($r) => ['ref' => $r['ref'], 'type' => $r['type'], 'dept' => $r['dept'], 'status' => $r['status'], 'decider' => $r['decider'], 'decided_at' => $r['decided_at'], 'go' => 'admin_approvals?open=' . $r['key']], array_slice($recent, 0, 8));

        // department performance: each department in its own unit, last 30 days vs the previous 30 days
        $win = fn(string $sql) => array_map('floatval', one($p, $sql));
        $perf = [
            ['dept' => 'Operations', 'measure' => 'Trips completed', 'unit' => 'count', 'go' => 'admin_operations?group=completed'] + $win("SELECT
                COALESCE(SUM(completed_at >= NOW() - INTERVAL 30 DAY),0) AS now, COALESCE(SUM(completed_at < NOW() - INTERVAL 30 DAY AND completed_at >= NOW() - INTERVAL 60 DAY),0) AS prev FROM trips WHERE status='Completed'"),
            ['dept' => 'Motorpool', 'measure' => 'Repairs completed', 'unit' => 'count', 'go' => 'admin_maintenance?state=returned'] + $win("SELECT
                COALESCE(SUM(completed_on >= CURRENT_DATE() - INTERVAL 30 DAY),0) AS now, COALESCE(SUM(completed_on < CURRENT_DATE() - INTERVAL 30 DAY AND completed_on >= CURRENT_DATE() - INTERVAL 60 DAY),0) AS prev FROM maintenance_jobs WHERE status<>'Cancelled' AND completed_on IS NOT NULL"),
            ['dept' => 'Purchasing', 'measure' => 'Parts requests fully released', 'unit' => 'count', 'go' => 'parts_requests?tab=released'] + $win("SELECT
                COALESCE(SUM(processed_at >= NOW() - INTERVAL 30 DAY),0) AS now, COALESCE(SUM(processed_at < NOW() - INTERVAL 30 DAY AND processed_at >= NOW() - INTERVAL 60 DAY),0) AS prev FROM part_requests WHERE status='Released'"),
            ['dept' => 'Billing', 'measure' => 'Client collections', 'unit' => 'money', 'go' => 'billing_collections'] + $win("SELECT
                COALESCE(SUM(CASE WHEN payment_date >= CURRENT_DATE() - INTERVAL 30 DAY THEN amount END),0) AS now, COALESCE(SUM(CASE WHEN payment_date < CURRENT_DATE() - INTERVAL 30 DAY AND payment_date >= CURRENT_DATE() - INTERVAL 60 DAY THEN amount END),0) AS prev FROM collections"),
        ];
        $over = array_sum(array_map(fn($x) => (int)$x['days_overdue'] > 0 ? (float)$x['balance'] : 0, $fin['receivables'] ?? [])) + array_sum(array_map(fn($x) => (int)$x['days_overdue'] > 0 ? (float)$x['balance'] : 0, $fin['payables'] ?? []));
        return ['items' => $items, 'recent' => $recent, 'performance' => $perf, 'weekly' => $fin['weekly'] ?? [], 'week' => $fin['week'] ?? [],
                'overdue' => round($over, 2), 'decisions' => count(array_filter($items, fn($x) => $x['decide'])),
                'waiting' => count(array_filter($items, fn($x) => $x['group'] === 'waiting')), 'open' => count(array_filter($items, fn($x) => $x['group'] === 'issues'))];
    }

    /**
     * Topic Analytics (?topic=operations|maintenance|purchasing|finance, ?from=&to=). Descriptive (what happened),
     * diagnostic (what contributed) and prescriptive (rule-based suggestions). Each chart keeps one unit; every dataset
     * comes from recorded rows; Maintenance and Purchasing reuse their validated report logic.
     */
    private static function analytics(array $u, PDO $p, array $q): array {
        Actions::load('Analytics');
        $topic = in_array($q['topic'] ?? '', ['operations', 'maintenance', 'purchasing', 'finance'], true) ? $q['topic'] : 'operations';
        [$from, $to] = self::period($q, date('Y-m-01', strtotime('first day of -2 months'))); $per = [$from, $to];
        $buckets = Analytics::buckets($from, $to); $nB = count($buckets);
        $idx = function (?string $d) use ($buckets): ?int { if (!$d) return null; $d = substr($d, 0, 10); foreach ($buckets as $i => $b) if ($d >= $b['from'] && $d <= $b['to']) return $i; return null; };
        $series = function (array $rows) use ($idx, $nB) { $s = array_fill(0, $nB, 0); foreach ($rows as $r) if (($i = $idx($r['d'])) !== null) $s[$i]++; return $s; };
        $data = ['topic' => $topic, 'period' => ['from' => $from, 'to' => $to, 'granularity' => Analytics::bucketUnit($from, $to)], 'buckets' => $buckets];
        if ($topic === 'operations') {
            $created = many($p, 'SELECT id, trip_ref AS ref, created_at AS d FROM trips WHERE DATE(created_at) BETWEEN ? AND ?', $per);
            $done = many($p, "SELECT id, trip_ref AS ref, completed_at AS d FROM trips WHERE status='Completed' AND DATE(completed_at) BETWEEN ? AND ?", $per);
            $data += [
                'activity' => ['created' => $series($created), 'completed' => $series($done)], 'totals' => ['created' => count($created), 'completed' => count($done)],
                'status' => many($p, 'SELECT status AS label, COUNT(*) AS n FROM trips WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY status ORDER BY n DESC', $per),
                'incidents' => many($p, 'SELECT COALESCE(incident_type, \'Not coded\') AS label, COUNT(*) AS n FROM incidents WHERE DATE(incident_at) BETWEEN ? AND ? GROUP BY label ORDER BY n DESC', $per),
                'dispatch' => many($p, "SELECT CONCAT(CASE t.dispatcher_role WHEN 'car_carrier_dispatcher' THEN 'Car Carrier' WHEN 'container_wingvan_dispatcher' THEN 'Container / Wing Van' ELSE 'Other' END,
                        ' · ', COALESCE(du.shift, 'No shift')) AS label, COUNT(*) AS n FROM trips t LEFT JOIN users du ON du.id=t.dispatcher_id
                    WHERE t.status='Completed' AND DATE(t.completed_at) BETWEEN ? AND ? GROUP BY label ORDER BY label", $per),
                'suggestions' => ['main' => array_map(fn($t) => ['text' => $t['trip_ref'] . ' is ' . $t['status'] . '.', 'go' => 'trips?open=' . $t['id'], 'label' => 'Trip'],
                    many($p, "SELECT id, trip_ref, status FROM trips WHERE status IN ('" . implode("','", self::ATTENTION_TRIPS) . "') ORDER BY updated_at LIMIT 10"))]];
        } elseif ($topic === 'maintenance') {
            $r = Analytics::read($u, 'maintenance_reports', ['from' => $from, 'to' => $to]);
            $data += ['report' => ['kpis' => $r['kpis'] ?? [], 'cost' => $r['cost'] ?? [], 'timeline' => $r['timeline'] ?? [], 'frequency' => array_slice($r['frequency'] ?? [], 0, 8), 'buckets' => $r['buckets'] ?? []],
                'suggestions' => ['main' => $r['suggestions']['timeline'] ?? [], 'trucks' => $r['suggestions']['trucks'] ?? []]];
        } elseif ($topic === 'purchasing') {
            Actions::load('Purchasing');
            $a = Purchasing::analytics($u, ['from' => $from, 'to' => $to]);
            $data += ['report' => ['flow' => ['in' => $a['flow']['in'] ?? [], 'out' => $a['flow']['out'] ?? []], 'buckets' => $a['buckets'] ?? [], 'topUsed' => $a['topUsed'] ?? [], 'poStatus' => $a['poStatus'] ?? []],
                'inventory' => many($p, "SELECT COALESCE(category, 'Uncategorised') AS label, COUNT(*) AS n, ROUND(SUM(quantity_on_hand*unit_cost),2) AS value FROM parts WHERE active=1 GROUP BY label HAVING value > 0 ORDER BY value DESC"),
                'suggestions' => ['main' => $a['partTips'] ?? []]];
        } else {
            $fin = self::finance($p, []);
            $weeks = []; for ($c = self::weekOf($from)['from']; $c <= $to; $c = date('Y-m-d', strtotime($c . ' +7 days'))) $weeks[] = ['from' => $c, 'to' => date('Y-m-d', strtotime($c . ' +6 days')), 'label' => 'Week of ' . date('M j', strtotime($c))];
            $cols = many($p, 'SELECT payment_date AS d, amount FROM collections WHERE payment_date BETWEEN ? AND ?', [$weeks[0]['from'] ?? $from, $to]);
            foreach ($weeks as &$w) { $w['amount'] = round(array_sum(array_map(fn($c) => $c['d'] >= $w['from'] && $c['d'] <= $w['to'] ? (float)$c['amount'] : 0, $cols)), 2); } unset($w);
            $aging = ['Not yet due' => 0.0, '1-30 days' => 0.0, '31-60 days' => 0.0, 'Over 60 days' => 0.0];
            foreach ($fin['receivables'] ?? [] as $x) { $dd = (int)$x['days_overdue']; $aging[$dd <= 0 ? 'Not yet due' : ($dd <= 30 ? '1-30 days' : ($dd <= 60 ? '31-60 days' : 'Over 60 days'))] += (float)$x['balance']; }
            $payDue = ['Overdue' => 0.0, 'Due within 7 days' => 0.0, 'Due later' => 0.0, 'PO payables (no due date recorded)' => 0.0];
            foreach ($fin['payables'] ?? [] as $x) { $dd = (int)$x['days_overdue']; $payDue[$dd > 0 ? 'Overdue' : ($dd >= -7 ? 'Due within 7 days' : 'Due later')] += (float)$x['balance']; }
            foreach ($fin['poPayables'] ?? [] as $o) $payDue['PO payables (no due date recorded)'] += max(0, (float)$o['received_value'] - (float)$o['paid_amount']);
            $mix = many($p, 'SELECT category AS label, COUNT(*) AS n, ROUND(SUM(amount),2) AS value FROM expenses WHERE incurred_on BETWEEN ? AND ? GROUP BY category ORDER BY value DESC', $per);
            $data += ['expenses' => $mix, 'weeks' => $weeks, 'monthlyBudget' => $fin['monthlyBudget'] ?? [], 'weekly' => $fin['weekly'],
                'aging' => array_map(fn($k, $v) => ['label' => $k, 'value' => round($v, 2)], array_keys($aging), $aging),
                'payDue' => array_map(fn($k, $v) => ['label' => $k, 'value' => round($v, 2)], array_keys($payDue), $payDue),
                'suggestions' => ['main' => array_merge($fin['suggestions']['receivables'] ?? [], $fin['suggestions']['payables'] ?? [])]];
        }
        return $data;
    }
}
