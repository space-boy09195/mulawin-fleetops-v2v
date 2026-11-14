<?php
/*
 * =====================================================================
 *  backend/Modules/Accounting/Finance.php  —  FINANCE (payables and cash out)
 * =====================================================================
 *  Objective 3.5  purchase order payments, accounts payable, disbursements and
 *                 other operating expenses (trip fund release is in Funds.php)
 *  Objective 3.6  purchasing budget (available, reserved, actual payments) and
 *                 financial transactions with supporting documents and statuses
 *
 *  PDF #12 Maintenance Module B steps 6, 8, 9:
 *   - an approved PO appears here with its amount, approver and "Pending Payment"
 *   - before payment Finance checks the supplier invoice against the approved PO
 *     and the received items; uploads the official receipt / payment confirmation
 *   - payment -> Paid; only now is cash reduced; the budget is NOT reduced again
 *     (it was reserved at approval: reserved -> spent)
 * =====================================================================
 */
declare(strict_types=1);
core('Notify');
core('Files');
core('Money');

final class Finance {
    public const EXPENSE_CATEGORIES = ['Fuel', 'RFID / Toll', 'Trip Allowance', 'Maintenance', 'Parts', 'Office', 'Utilities', 'Salaries', 'Other'];

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        $from = (string)($q['from'] ?? date('Y-m-01')); $to = (string)($q['to'] ?? date('Y-m-d'));
        if (!DateTimeImmutable::createFromFormat('!Y-m-d', $from)) $from = date('Y-m-01');
        if (!DateTimeImmutable::createFromFormat('!Y-m-d', $to)) $to = date('Y-m-d');
        if ($to < $from) [$from, $to] = [$to, $from];
        // FINANCE_WORKSPACE_V1: one page per Finance task. Each page returns only the sections it shows.
        if ($page === 'finance_overview') return self::overview($u, $p);
        $sections = self::sections($p, $from, $to);
        $keys = $page === 'finance_budgets'
            ? ['expenses', 'budgets', 'cashflow', 'trucks', 'expenseMix']
            : ['poPayables', 'payables', 'disbursements', 'suppliers', 'trucks'];   // 'finance' = Payables & Payments
        $data = readSections(array_intersect_key($sections, array_flip($keys)));
        $data['period'] = ['from' => $from, 'to' => $to];
        $data['categories'] = self::EXPENSE_CATEGORIES;
        $data['methods'] = Money::METHODS;
        $data['can'] = ['act' => hasRole($u, 'finance')];
        return $data;
    }

    /** All Finance read sections (lazy). Pages pick the ones they show. */
    private static function sections(PDO $p, string $from, string $to): array {
        return [
            'poPayables' => fn() => many($p, "SELECT o.id, o.po_ref, o.status, o.payment_status, o.total_amount, o.paid_amount, o.supplier_invoice_no, COALESCE(o.supplier_name, s.name) AS supplier, du.full_name AS approved_by_name,
                    (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) AS received_value
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id LEFT JOIN users du ON du.id=o.decided_by
                WHERE o.payment_status IN ('Pending Payment','Partially Paid') ORDER BY o.id"),
            'payables' => fn() => many($p, "SELECT pa.id, pa.payable_ref, pa.payee, pa.category, pa.description, pa.due_on, pa.amount, pa.paid_amount, pa.status, (pa.amount-pa.paid_amount) AS balance, v.plate_number, s.name AS supplier FROM payables pa
                LEFT JOIN vehicles v ON v.id=pa.vehicle_id LEFT JOIN suppliers s ON s.id=pa.supplier_id ORDER BY FIELD(pa.status,'Unpaid','Partially Paid','Paid'), pa.due_on, pa.id DESC LIMIT 500"),
            'expenses' => fn() => many($p, "SELECT e.id, e.category, e.amount, e.incurred_on, e.payee, e.reference_number, e.description, e.source, t.trip_ref, v.plate_number, u.full_name AS recorded_by_name FROM expenses e LEFT JOIN trips t ON t.id=e.trip_id
                LEFT JOIN vehicles v ON v.id=e.vehicle_id LEFT JOIN users u ON u.id=e.recorded_by WHERE e.incurred_on BETWEEN ? AND ? ORDER BY e.incurred_on DESC, e.id DESC LIMIT 2000", [$from, $to]),
            'disbursements' => fn() => many($p, "SELECT d.id, d.voucher_number, d.source_type, d.source_id, d.payee, d.payment_method, d.cheque_number, d.bank_name, d.reference_number, d.amount, d.released_on, u.full_name AS recorded_by_name,
                    CASE d.source_type WHEN 'purchase_order' THEN (SELECT po_ref FROM purchase_orders WHERE id=d.source_id) WHEN 'fund_request' THEN (SELECT fund_ref FROM fund_requests WHERE id=d.source_id)
                        WHEN 'payable' THEN (SELECT payable_ref FROM payables WHERE id=d.source_id) END AS source_ref
                FROM disbursements d JOIN users u ON u.id=d.recorded_by
                WHERE d.released_on BETWEEN ? AND ? ORDER BY d.released_on DESC, d.id DESC LIMIT 2000", [$from, $to]),
            'budgets' => fn() => many($p, "SELECT b.id, b.period_start, b.period_end, b.amount, b.reserved_amount, b.spent_amount, b.status, (b.amount-b.reserved_amount-b.spent_amount) AS available, u.full_name AS set_by_name FROM budgets b JOIN users u ON u.id=b.set_by ORDER BY b.status, b.id DESC"),
            'cashflow' => fn() => many($p, "SELECT m, SUM(cash_in) AS cash_in, SUM(cash_out) AS cash_out FROM (
                    SELECT DATE_FORMAT(payment_date,'%Y-%m') AS m, amount AS cash_in, 0 AS cash_out FROM collections WHERE payment_date>=DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)
                    UNION ALL SELECT DATE_FORMAT(released_on,'%Y-%m'), 0, amount FROM disbursements WHERE released_on>=DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)) x GROUP BY m ORDER BY m"),
            'suppliers' => fn() => many($p, 'SELECT id, name FROM suppliers WHERE active=1 ORDER BY name'),
            'trucks' => fn() => many($p, 'SELECT id, plate_number FROM vehicles ORDER BY plate_number'),
            // Expenses of the period per category and source (descriptive; same rows as the expense list).
            'expenseMix' => fn() => many($p, "SELECT category, source, COUNT(*) AS n, SUM(amount) AS total FROM expenses WHERE incurred_on BETWEEN ? AND ?
                GROUP BY category, source ORDER BY total DESC", [$from, $to]),
        ];
    }

    /**
     * FINANCE_WORKSPACE_V1 — Finance Overview (read only): what is waiting for Finance now.
     * Every figure is a direct sum/count over stored records; definitions are shown on the page.
     */
    private static function overview(array $u, PDO $p): array {
        $month = date('Y-m-01'); $today = date('Y-m-d');
        $poDue = "(SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) - o.paid_amount";
        $data = readSections([
            'counts' => fn() => [
                'po_approvals' => (int)one($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status='Pending Approval' AND approver_role='finance'")['n'],
                'funds_ready' => one($p, "SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM fund_requests WHERE status='Ready for Release'"),
                'funds_to_liquidate' => (int)one($p, "SELECT COUNT(*) AS n FROM fund_requests f JOIN trips t ON t.id=f.trip_id
                    WHERE f.status='Released' AND f.liquidation_status='Not Liquidated' AND t.status IN ('Completed','Cancelled')")['n'],
                'po_due' => one($p, "SELECT COUNT(*) AS n, COALESCE(SUM(GREATEST($poDue,0)),0) AS total FROM purchase_orders o
                    WHERE o.payment_status IN ('Pending Payment','Partially Paid') AND $poDue > 0"),
                'ap_due' => one($p, "SELECT COUNT(*) AS n, COALESCE(SUM(amount-paid_amount),0) AS total, COALESCE(SUM(due_on < ?),0) AS overdue
                    FROM payables WHERE status<>'Paid'", [$today]),
                'expenses_month' => (float)one($p, 'SELECT COALESCE(SUM(amount),0) AS v FROM expenses WHERE incurred_on >= ?', [$month])['v'],
                'cash_out_month' => (float)one($p, 'SELECT COALESCE(SUM(amount),0) AS v FROM disbursements WHERE released_on >= ?', [$month])['v'],
            ],
            'approvals' => fn() => many($p, "SELECT o.id, o.po_ref, o.total_amount, o.created_at, COALESCE(o.supplier_name, s.name) AS supplier FROM purchase_orders o
                LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.status='Pending Approval' AND o.approver_role='finance' ORDER BY o.id LIMIT 25"),
            'fundsReady' => fn() => many($p, "SELECT f.id, f.fund_ref, f.amount, t.trip_ref, t.scheduled_at, c.company_name AS client FROM fund_requests f
                JOIN trips t ON t.id=f.trip_id JOIN clients c ON c.id=t.client_id WHERE f.status='Ready for Release' ORDER BY t.scheduled_at LIMIT 25"),
            'paymentsDue' => fn() => many($p, "(SELECT 'po' AS kind, o.id, o.po_ref AS ref, COALESCE(o.supplier_name, s.name) AS payee, GREATEST($poDue,0) AS due, NULL AS due_on, o.payment_status AS status
                    FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.payment_status IN ('Pending Payment','Partially Paid') AND $poDue > 0)
                UNION ALL (SELECT 'payable', pa.id, pa.payable_ref, pa.payee, pa.amount-pa.paid_amount, pa.due_on, pa.status FROM payables pa WHERE pa.status<>'Paid')
                ORDER BY (due_on IS NULL), due_on, ref LIMIT 25"),
            'budget' => fn() => one($p, "SELECT id, amount, reserved_amount, spent_amount, period_start, period_end, amount-reserved_amount-spent_amount AS available,
                    (CURRENT_DATE() BETWEEN period_start AND period_end) AS in_period FROM budgets WHERE status='Active' AND category='Purchasing' ORDER BY id DESC LIMIT 1") ?? [],
        ]);
        $data['can'] = ['act' => hasRole($u, 'finance')];
        $data['today'] = $today;
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'po_pay' => self::poPay($u, $d),
            'payable_add' => self::payableAdd($u, $d),
            'payable_pay' => self::payablePay($u, $d),
            'expense_add' => self::expenseAdd($u, $d),
            'budget_set' => self::budgetSet($u, $d),
            default => throw new BusinessRuleException('Unknown action.', 404),
        };
    }

    /** Pays a purchase order (up to the value of the items actually received). */
    private static function poPay(array $u, array $d): array {
        requireRole($u, 'finance');
        $id = idField($d, 'po_id');
        $amount = moneyField($d, 'amount');
        $invoice = field($d, 'supplier_invoice_no', 80);
        $pay = Money::paymentFields($d);
        must(Files::has('or_doc'), 'Attach the official receipt or payment confirmation.', 422);
        return transaction(function (PDO $p) use ($u, $id, $amount, $invoice, $pay) {
            $o = one($p, 'SELECT o.*, COALESCE(o.supplier_name, s.name) AS supplier FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.id=? FOR UPDATE', [$id]);
            must((bool)$o, 'Purchase order not found.', 404);
            must(in_array($o['payment_status'], ['Pending Payment', 'Partially Paid'], true), 'This purchase order is not waiting for payment.', 409);
            $received = (float)one($p, 'SELECT COALESCE(SUM(received_qty*unit_price),0) AS v FROM purchase_order_lines WHERE purchase_order_id=?', [$id])['v'];
            $payable = round($received - (float)$o['paid_amount'], 2);
            must($payable > 0, 'Nothing to pay yet: no items have been received for this PO (check the delivery against the approved PO).', 409);
            must((float)$amount <= $payable, 'The amount is more than the value of the received items not yet paid (PHP ' . number_format($payable, 2) . ').');
            $disb = Money::disburse($p, $u, 'purchase_order', $id, $o['supplier'], $amount, $pay);
            Files::store($p, $u, 'or_doc', 'purchase', $id, 'Official Receipt / Payment Confirmation', true, 'official receipt or payment confirmation');
            $fromReserve = round(min((float)$amount, (float)$o['reserved_amount']), 2);
            if ($o['budget_id']) run($p, 'UPDATE budgets SET reserved_amount=GREATEST(reserved_amount-?,0), spent_amount=spent_amount+? WHERE id=?', [$fromReserve, $amount, $o['budget_id']]);
            run($p, 'UPDATE purchase_orders SET paid_amount=paid_amount+?, reserved_amount=GREATEST(reserved_amount-?,0), supplier_invoice_no=? WHERE id=?', [$amount, $fromReserve, $invoice, $id]);
            Actions::load('Purchasing');
            Purchasing::refreshPayment($p, $id);
            Money::expense($p, $u, 'Parts', $amount, $pay['released_on'], 'PO Payment', $id, null, null, $o['supplier'], $o['po_ref'], 'Payment of ' . $o['po_ref'] . ' (invoice ' . $invoice . ')');
            $st = one($p, 'SELECT payment_status FROM purchase_orders WHERE id=?', [$id])['payment_status'];
            auditWithChanges($p, $u['id'], 'Finance', 'po ' . $id, 'purchase order payment recorded', $o['po_ref'] . ' PHP ' . $amount . ' -> ' . $st,
                ['Paid amount' => $o['paid_amount'], 'Reserved amount' => $o['reserved_amount'], 'Payment status' => $o['payment_status']],
                ['Paid amount' => number_format((float)$o['paid_amount'] + (float)$amount, 2, '.', ''),
                 'Reserved amount' => number_format(max(0, (float)$o['reserved_amount'] - $fromReserve), 2, '.', ''), 'Payment status' => $st]);
            Notify::user($p, (int)$o['requested_by'], 'PO paid', $o['po_ref'] . ' payment recorded (' . $st . ').', 'purchase_orders');
            if ($st === 'Paid') Notify::resolve($p, 'po-pay-' . $o['id']);
            return ['message' => 'Payment recorded. ' . $o['po_ref'] . ' is ' . $st . '.'];
        });
    }

    private static function payableAdd(array $u, array $d): array {
        requireRole($u, 'finance');
        $row = ['supplier_id' => optId($d, 'supplier_id'), 'payee' => field($d, 'payee', 150), 'category' => choiceField($d, 'category', self::EXPENSE_CATEGORIES),
                'vehicle_id' => optId($d, 'vehicle_id'), 'description' => field($d, 'description', 255), 'amount' => moneyField($d, 'amount'), 'due_on' => dateField($d, 'due_on', false)];
        return transaction(function (PDO $p) use ($u, $row) {
            $id = insert($p, 'payables', ['payable_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'status' => 'Unpaid', 'recorded_by' => $u['id']] + $row);
            $ref = refNumber('AP', $id);
            run($p, 'UPDATE payables SET payable_ref=? WHERE id=?', [$ref, $id]);
            Files::store($p, $u, 'bill', 'payable', $id, 'Supplier Bill / Invoice');
            auditWithChanges($p, $u['id'], 'Finance', 'payable ' . $id, 'payable recorded', "$ref {$row['payee']} PHP {$row['amount']}", [],
                ['Payable reference' => $ref, 'Payee' => $row['payee'], 'Category' => $row['category'],
                 'Amount' => $row['amount'], 'Due date' => $row['due_on'], 'Status' => 'Unpaid']);
            return ['message' => "Payable $ref recorded."];
        });
    }

    private static function payablePay(array $u, array $d): array {
        requireRole($u, 'finance');
        $id = idField($d, 'payable_id');
        $amount = moneyField($d, 'amount');
        $pay = Money::paymentFields($d);
        return transaction(function (PDO $p) use ($u, $id, $amount, $pay) {
            $a = one($p, 'SELECT * FROM payables WHERE id=? FOR UPDATE', [$id]);
            must((bool)$a, 'Payable not found.', 404);
            $bal = round((float)$a['amount'] - (float)$a['paid_amount'], 2);
            must($bal > 0, 'This payable is already paid.', 409);
            must((float)$amount <= $bal, 'The amount is more than the balance (PHP ' . number_format($bal, 2) . ').');
            Money::disburse($p, $u, 'payable', $id, $a['payee'], $amount, $pay);
            Files::store($p, $u, 'or_doc', 'payable', $id, 'Official Receipt / Payment Confirmation');
            $paid = round((float)$a['paid_amount'] + (float)$amount, 2);
            run($p, 'UPDATE payables SET paid_amount=?, status=? WHERE id=?', [$paid, $paid >= (float)$a['amount'] ? 'Paid' : 'Partially Paid', $id]);
            Money::expense($p, $u, $a['category'], $amount, $pay['released_on'], 'Payable', $id, null, $a['vehicle_id'] ? (int)$a['vehicle_id'] : null, $a['payee'], $a['payable_ref'], $a['description']);
            auditWithChanges($p, $u['id'], 'Finance', 'payable ' . $id, 'payable paid', $a['payable_ref'] . ' PHP ' . $amount,
                ['Paid amount' => $a['paid_amount'], 'Status' => $a['status']],
                ['Paid amount' => number_format($paid, 2, '.', ''), 'Status' => $paid >= (float)$a['amount'] ? 'Paid' : 'Partially Paid']);
            return ['message' => 'Payment recorded.'];
        });
    }

    private static function expenseAdd(array $u, array $d): array {
        requireRole($u, 'finance');
        $cat = choiceField($d, 'category', self::EXPENSE_CATEGORIES);
        $amount = moneyField($d, 'amount');
        $date = dateField($d, 'incurred_on');
        must($date <= date('Y-m-d'), 'The date cannot be in the future.');
        $tripId = optId($d, 'trip_id'); $vid = optId($d, 'vehicle_id');
        $payee = optField($d, 'payee', 150); $ref = optField($d, 'reference_number', 100); $desc = field($d, 'description', 500);
        return transaction(function (PDO $p) use ($u, $cat, $amount, $date, $tripId, $vid, $payee, $ref, $desc) {
            if ($tripId) must((bool)one($p, 'SELECT id FROM trips WHERE id=?', [$tripId]), 'Trip not found.');
            $id = Money::expense($p, $u, $cat, $amount, $date, 'Manual', null, $tripId, $vid, $payee, $ref, $desc);
            Files::store($p, $u, 'receipt', 'expense', $id, 'Expense Receipt');
            auditWithChanges($p, $u['id'], 'Finance', 'expense ' . $id, 'expense recorded', "$cat PHP $amount: $desc", [],
                ['Category' => $cat, 'Amount' => $amount, 'Date' => $date, 'Payee' => $payee, 'Description' => $desc]);
            return ['message' => 'Expense recorded.'];
        });
    }

    /** Purchasing budget. Only one Active budget; a new one closes the previous. */
    private static function budgetSet(array $u, array $d): array {
        requireRole($u, 'finance');
        $amount = moneyField($d, 'amount');
        $start = dateField($d, 'period_start'); $end = dateField($d, 'period_end');
        must($end >= $start, 'The end date is before the start date.');
        return transaction(function (PDO $p) use ($u, $amount, $start, $end) {
            // ACCOUNTING_INTEGRITY_G8: open commitments move to the new budget, so later payments draw on an Active budget and
            // reserved + spent stay consistent. The old budget keeps its spent amount (history) and loses only what moved.
            $old = many($p, "SELECT * FROM budgets WHERE status='Active' AND category='Purchasing' FOR UPDATE");
            $carry = many($p, "SELECT id, po_ref, budget_id, reserved_amount FROM purchase_orders WHERE budget_id IN (SELECT id FROM budgets WHERE status='Active' AND category='Purchasing')
                AND reserved_amount > 0 AND status IN ('Approved','Partially Received','Received') FOR UPDATE");
            $moved = round(array_sum(array_map(fn($o) => (float)$o['reserved_amount'], $carry)), 2);
            must((float)$amount >= $moved, 'The new budget (PHP ' . number_format((float)$amount, 2) . ') is less than the PHP ' . number_format($moved, 2)
                . ' still reserved by approved purchase orders that will move to it. Enter at least that amount.');
            run($p, "UPDATE budgets SET status='Closed' WHERE status='Active' AND category='Purchasing'");
            $id = insert($p, 'budgets', ['category' => 'Purchasing', 'amount' => $amount, 'period_start' => $start, 'period_end' => $end, 'status' => 'Active', 'set_by' => $u['id']]);
            foreach ($carry as $o) {
                run($p, 'UPDATE budgets SET reserved_amount=GREATEST(reserved_amount-?,0) WHERE id=?', [$o['reserved_amount'], $o['budget_id']]);
                run($p, 'UPDATE purchase_orders SET budget_id=? WHERE id=?', [$id, $o['id']]);
            }
            if ($moved > 0) run($p, 'UPDATE budgets SET reserved_amount=? WHERE id=?', [$moved, $id]);
            auditWithChanges($p, $u['id'], 'Finance', 'budget ' . $id, 'purchasing budget set', "PHP $amount ($start to $end)"
                . ($carry ? '; ' . count($carry) . ' open PO(s) moved with PHP ' . number_format($moved, 2) . ' reserved: ' . implode(', ', array_column($carry, 'po_ref')) : ''),
                ['Previous active budget' => $old ? implode(', ', array_map(fn($b) => 'budget ' . $b['id'] . ' reserved ' . $b['reserved_amount'], $old)) : null],
                ['Category' => 'Purchasing', 'Amount' => $amount, 'Period start' => $start, 'Period end' => $end, 'Status' => 'Active', 'Reserved carried over' => number_format($moved, 2, '.', '')]);
            return ['message' => 'Purchasing budget set: PHP ' . number_format((float)$amount, 2) . '.' . ($carry ? ' ' . count($carry) . ' open purchase order(s) moved to it with PHP ' . number_format($moved, 2) . ' reserved.' : '')];
        });
    }
}
