<?php
/*
 * =====================================================================
 *  backend/Modules/Accounting/Billing.php  —  BILLING AND COLLECTION
 * =====================================================================
 *  Objective 3.2  billing drafts from client rate x billable distance (+ charges);
 *                 completed trips processed for billing
 *  Objective 3.3  verify billing information and required documents before
 *                 finalizing; generate invoices (Service Invoice format, PDF #13)
 *  Objective 3.4  client payments, collections, outstanding balances, receivables
 *
 *  PDF #12:
 *   - Confirm Dispatch -> billing draft in "Ongoing Trips" (view only)
 *   - Trip Completed -> draft moves to "For Billing" with client profile, trip
 *     details, billing breakdown and the dispatcher's supporting documents
 *   - Billing verifies the documents; if verified -> finalize -> printable invoice
 *   - Payment needs the acknowledgement receipt + payment details; the system
 *     refuses the confirmation until they are complete -> PAID
 * =====================================================================
 */
declare(strict_types=1);
core('Notify');
core('Files');
core('Money');
core('Settings');

core('TripPricing');
final class Billing {
    public const DOC_PAYMENT = 'Payment Acknowledgement Receipt';

    public static function read(array $u, string $page, array $q): array {
        $p = db();
        // BILLING_WORKSPACE_V1: Overview / Collections / Receivables pages read only what they show.
        if ($page === 'billing_overview') return self::overview($u, $p);
        if ($page === 'billing_collections' || $page === 'billing_receivables') {
            $data = readSections([
                'collections' => fn() => $page === 'billing_collections' ? many($p, "SELECT co.*, b.invoice_number, b.id AS billing_id, b.total_amount, b.amount_paid, c.company_name AS client, u.full_name AS recorded_by_name FROM collections co
                    JOIN billings b ON b.id=co.billing_id JOIN clients c ON c.id=b.client_id JOIN users u ON u.id=co.recorded_by ORDER BY co.payment_date DESC, co.id DESC LIMIT 1000") : [],
                'open' => fn() => many($p, "SELECT b.id, b.invoice_number, b.invoice_date, b.due_date, b.total_amount, b.amount_paid, b.status, (b.total_amount-b.amount_paid) AS balance,
                        DATEDIFF(CURRENT_DATE(), b.due_date) AS days_overdue, c.id AS client_id, c.company_name AS client, t.trip_ref,
                        (SELECT MAX(co.payment_date) FROM collections co WHERE co.billing_id=b.id) AS last_payment
                    FROM billings b JOIN clients c ON c.id=b.client_id JOIN trips t ON t.id=b.trip_id WHERE b.status IN ('Invoiced','Partially Paid') ORDER BY b.due_date, b.id"),
                'receivables' => fn() => $page === 'billing_receivables' ? self::aging($p) : [],
            ]);
            $data['methods'] = Money::METHODS; $data['asOf'] = date('Y-m-d');
            $data['can'] = ['act' => hasRole($u, 'billing'), 'decide' => hasRole($u, 'admin'), 'me' => (int)$u['id']];
            return $data;
        }
        $data = readSections([
            'billings' => fn() => many($p, "SELECT b.*, t.trip_ref, t.status AS trip_status, t.truck_type, t.pickup_point, t.delivery_point, t.dispatched_at, t.completed_at,
                    t.waybill_number, t.units_delivered, c.company_name AS client, c.payment_terms_days, v.plate_number,
                    (b.total_amount-b.amount_paid) AS balance, DATEDIFF(CURRENT_DATE(), b.due_date) AS days_overdue,
                    (SELECT COUNT(*) FROM documents d WHERE d.entity_type='trip' AND d.entity_id=t.id AND d.category='Waybill') AS waybill_docs,
                    (SELECT COUNT(*) FROM documents d WHERE d.entity_type='trip' AND d.entity_id=t.id AND d.category='Acknowledgement Receipt') AS ar_docs,
                    (SELECT COUNT(*) FROM documents d WHERE d.entity_type='trip' AND d.entity_id=t.id AND d.category='Delivery Receipt') AS dr_docs,
                    (SELECT COUNT(*) FROM billing_charges bc WHERE bc.billing_id=b.id AND bc.status='Pending Approval') AS pending_adjustments
                FROM billings b JOIN trips t ON t.id=b.trip_id JOIN clients c ON c.id=b.client_id LEFT JOIN vehicles v ON v.id=t.vehicle_id
                ORDER BY b.id DESC LIMIT 2000"),
            'collections' => fn() => many($p, "SELECT co.*, b.invoice_number, c.company_name AS client, u.full_name AS recorded_by_name FROM collections co
                JOIN billings b ON b.id=co.billing_id JOIN clients c ON c.id=b.client_id JOIN users u ON u.id=co.recorded_by ORDER BY co.id DESC LIMIT 1000"),
            'receivables' => fn() => self::aging($p),
            'pendingRates' => fn() => 0, // Kenneth 16dce05: client rates are Admin-approved; Billing is not prompted to set them.
            'adjustments' => fn() => many($p, "SELECT c.id, c.billing_id, c.kind, c.request_type, c.description, c.amount, c.reason, c.added_by, c.created_at,
                    o.description AS old_description, o.amount AS old_amount, u.full_name AS added_by_name, t.trip_ref, cl.company_name AS client, b.total_amount
                FROM billing_charges c JOIN billings b ON b.id=c.billing_id JOIN trips t ON t.id=b.trip_id JOIN clients cl ON cl.id=b.client_id
                JOIN users u ON u.id=c.added_by LEFT JOIN billing_charges o ON o.id=c.replaces_id
                WHERE c.status='Pending Approval' ORDER BY c.id"),
        ]);
        $data['methods'] = Money::METHODS;
        $data['vatRate'] = Settings::value('vat_rate');
        $data['can'] = ['act' => hasRole($u, 'billing'), 'decide' => hasRole($u, 'admin'), 'me' => (int)$u['id']];
        return $data;
    }

    /** Receivables aging per client, as of today: open invoices (Invoiced / Partially Paid) by days past the due date. */
    private static function aging(PDO $p): array {
        return many($p, "SELECT c.id, c.company_name AS client, COUNT(*) AS invoices, SUM(b.total_amount-b.amount_paid) AS balance,
                SUM(CASE WHEN b.due_date>=CURRENT_DATE() THEN b.total_amount-b.amount_paid ELSE 0 END) AS not_due,
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE(), b.due_date) BETWEEN 1 AND 30 THEN b.total_amount-b.amount_paid ELSE 0 END) AS d1_30,
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE(), b.due_date) BETWEEN 31 AND 60 THEN b.total_amount-b.amount_paid ELSE 0 END) AS d31_60,
                SUM(CASE WHEN DATEDIFF(CURRENT_DATE(), b.due_date) > 60 THEN b.total_amount-b.amount_paid ELSE 0 END) AS d60,
                MAX(DATEDIFF(CURRENT_DATE(), b.due_date)) AS max_days_overdue
            FROM billings b JOIN clients c ON c.id=b.client_id WHERE b.status IN ('Invoiced','Partially Paid') GROUP BY c.id, c.company_name ORDER BY balance DESC");
    }

    /**
     * BILLING_WORKSPACE_V1 — Billing Overview (read only). Counts and short lists over stored billings / collections.
     * The pending-adjustment count needs migration 09 (billing_charges.status); without it only that section is unavailable.
     */
    private static function overview(array $u, PDO $p): array {
        $month = date('Y-m-01');
        $data = readSections([
            'counts' => fn() => one($p, "SELECT COALESCE(SUM(status='Ongoing'),0) AS ongoing, COALESCE(SUM(status='For Billing'),0) AS for_billing,
                    COALESCE(SUM(status IN ('Invoiced','Partially Paid')),0) AS unpaid, COALESCE(SUM(CASE WHEN status IN ('Invoiced','Partially Paid') THEN total_amount-amount_paid END),0) AS outstanding,
                    COALESCE(SUM(CASE WHEN status IN ('Invoiced','Partially Paid') AND due_date < CURRENT_DATE() THEN total_amount-amount_paid END),0) AS overdue,
                    COALESCE(SUM(status IN ('Invoiced','Partially Paid') AND due_date < CURRENT_DATE()),0) AS overdue_n,
                    COALESCE(SUM(CASE WHEN invoice_date >= ? THEN total_amount END),0) AS billed_month
                FROM billings", [$month]),
            'collectedMonth' => fn() => one($p, 'SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM collections WHERE payment_date >= ?', [$month]),
            'pendingAdjustments' => fn() => (int)one($p, "SELECT COUNT(*) AS n FROM billing_charges WHERE status='Pending Approval'")['n'],
            'pendingRates' => fn() => 0, // same Admin-only rate policy as read().
            'forBilling' => fn() => many($p, "SELECT b.id, t.trip_ref, t.completed_at, c.company_name AS client, b.total_amount, b.docs_verified_at,
                    ((SELECT COUNT(*) FROM documents d WHERE d.entity_type='trip' AND d.entity_id=t.id AND d.category='Waybill') > 0
                     AND (SELECT COUNT(*) FROM documents d WHERE d.entity_type='trip' AND d.entity_id=t.id AND d.category='Delivery Receipt') > 0
                     AND (SELECT COUNT(*) FROM documents d WHERE d.entity_type='trip' AND d.entity_id=t.id AND d.category='Acknowledgement Receipt') > 0) AS docs_complete
                FROM billings b JOIN trips t ON t.id=b.trip_id JOIN clients c ON c.id=b.client_id WHERE b.status='For Billing' ORDER BY t.completed_at LIMIT 25"),
            'overdue' => fn() => many($p, "SELECT b.id, b.invoice_number, b.due_date, (b.total_amount-b.amount_paid) AS balance, DATEDIFF(CURRENT_DATE(), b.due_date) AS days_overdue, c.company_name AS client
                FROM billings b JOIN clients c ON c.id=b.client_id WHERE b.status IN ('Invoiced','Partially Paid') AND b.due_date < CURRENT_DATE() ORDER BY b.due_date LIMIT 25"),
            'recentCollections' => fn() => many($p, "SELECT co.id, co.amount, co.payment_date, co.payment_method, b.id AS billing_id, b.invoice_number, c.company_name AS client FROM collections co
                JOIN billings b ON b.id=co.billing_id JOIN clients c ON c.id=b.client_id ORDER BY co.payment_date DESC, co.id DESC LIMIT 15"),
            'aging' => fn() => self::aging($p),
        ]);
        $data['asOf'] = date('Y-m-d'); $data['methods'] = Money::METHODS;   // methods: the shared billing drawer records collections
        $data['can'] = ['act' => hasRole($u, 'billing'), 'decide' => hasRole($u, 'admin'), 'me' => (int)$u['id']];
        return $data;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'pricing_report' => self::pricingReport($u,$d),
            'pricing_recalculate' => self::pricingRecalculate($u,$d),
            'charge_add' => self::chargeAdd($u, $d),
            'charge_change' => self::chargeChange($u, $d),
            'charge_remove' => self::chargeRemove($u, $d),
            'charge_withdraw' => self::chargeWithdraw($u, $d),
            'charge_decide' => self::chargeDecide($u, $d),
            'verify' => self::verify($u, $d),
            'finalize' => self::finalize($u, $d),
            'collect' => self::collect($u, $d),
            'details' => self::details($u, $d),
            'soa' => self::soa($u, $d),
            default => throw new BusinessRuleException('Unknown action.', 404),
        };
    }

    private static function lock(PDO $p, int $id): array {
        $b = one($p, 'SELECT b.*, t.trip_ref, t.status AS trip_status FROM billings b JOIN trips t ON t.id=b.trip_id WHERE b.id=? FOR UPDATE', [$id]);
        must((bool)$b, 'Billing record not found.', 404);
        return $b;
    }
    /*
     * BILLING_ADJUSTMENT_APPROVAL_V1
     * Additional charges and per-trip rate adjustments (peso amounts, plus or minus) are REQUESTS:
     * Billing asks, Admin approves or rejects (never the requester). Only Approved New/Change rows
     * count in the billing total (COUNTED). An approved row is never edited or deleted: a Change or
     * Removal request points to it (replaces_id) and approving that request supersedes / removes it
     * in the same transaction; rejecting it leaves the approved row as it is. Pending requests block
     * invoice finalization only (trip planning, dispatch and completion are not involved).
     */
    public const KINDS = ['Additional Charge', 'Rate Adjustment'];
    private static function pricingReport(array $u,array $d): array {
        requireRole($u,'admin','billing','audit');$p=db();must(TripPricing::ready($p),'Pricing migration 13 is required.',409);
        $after=max(0,(int)($d['after_id']??0));$rows=many($p,'SELECT * FROM billings WHERE id>? ORDER BY id LIMIT 200',[$after]);
        return ['rows'=>array_map(fn($b)=>TripPricing::proposal($p,$b),$rows),'next_after_id'=>count($rows)===200?(int)end($rows)['id']:null];
    }
    private static function pricingRecalculate(array $u,array $d): array {
        requireRole($u,'admin');$id=idField($d,'billing_id');$reason=field($d,'reason',300);
        return transaction(function(PDO $p)use($u,$d,$id,$reason){
            $b=one($p,'SELECT * FROM billings WHERE id=? FOR UPDATE',[$id]);must((bool)$b,'Billing not found.',404);
            $proposal=TripPricing::proposal($p,$b);$proposal['pricing']['frozen']=true;must($proposal['can_apply'],'Recalculation requires verified inputs and an unpaid, unissued open record.',409);
            must(hash_equals($proposal['fingerprint'],field($d,'fingerprint',64)),'Pricing changed after review. Preview again.',409);
            run($p,'UPDATE billing_pricing SET snapshot_json=? WHERE billing_id=?',[json_encode($proposal['pricing'],JSON_THROW_ON_ERROR),$id]);
            run($p,'UPDATE billings SET base_amount=?,total_amount=? WHERE id=?',[$proposal['pricing']['total'],$proposal['proposed'],$id]);
            auditWithChanges($p,$u['id'],'Pricing','billing '.$id,'verified open billing recalculated',$reason,['Total'=>$b['total_amount']],['Total'=>$proposal['proposed']]);return ['message'=>'Verified open record recalculated. Invoices and payments were not changed.'];
        });
    }

    /** Rows that count in the billing total. */
    public const COUNTED = "status='Approved' AND request_type<>'Removal'";
    /** COUNTED for a query that uses a table alias (joins with billing_charges itself). */
    public static function counted(string $alias): string { return "$alias.status='Approved' AND $alias.request_type<>'Removal'"; }

    private static function recompute(PDO $p, int $id): void {
        $sum = '(SELECT COALESCE(SUM(amount),0) FROM billing_charges WHERE billing_id=? AND ' . self::COUNTED . ')';
        run($p, "UPDATE billings SET charges_total=$sum, total_amount=base_amount+$sum WHERE id=?", [$id, $id, $id]);
    }
    private static function money(float $v): string { return number_format($v, 2, '.', ''); }
    private static function editable(array $b): void {
        must($b['status'] === 'For Billing', $b['status'] === 'Ongoing' ? 'The billing draft is view-only while the trip is ongoing.' : 'Charges can only be changed before the invoice is finalized.', 409);
    }
    /** The total must stay above zero (a finalized invoice can never be zero or negative). */
    private static function mustStayPositive(array $b, float $delta, string $what): void {
        $after = round((float)$b['total_amount'] + $delta, 2);
        must($after > 0, "$what would make the billing total PHP " . number_format($after, 2) . '. The total must stay above zero.', 409);
    }
    /** Amount with its sign: Rate Adjustments can increase or decrease; Additional Charges are positive. */
    private static function signedAmount(array $d, string $kind): string {
        $amount = moneyField($d, 'amount');
        if ($kind !== 'Rate Adjustment') return $amount;
        $dir = choiceField($d, 'direction', ['Increase', 'Decrease']);
        return $dir === 'Decrease' ? '-' . $amount : $amount;
    }
    private static function lockCharge(PDO $p, int $cid): array {
        $peek = one($p, 'SELECT billing_id FROM billing_charges WHERE id=?', [$cid]);
        must((bool)$peek, 'Charge not found.', 404);
        $b = self::lock($p, (int)$peek['billing_id']);           // lock order: billing -> charges
        $c = one($p, 'SELECT * FROM billing_charges WHERE id=? FOR UPDATE', [$cid]);
        return [$b, $c];
    }
    /** The approved row a Change / Removal request may target. */
    private static function mustBeReplaceable(PDO $p, array $c): void {
        must($c['status'] === 'Approved' && $c['request_type'] !== 'Removal', 'Only an approved charge or adjustment can be changed or removed (this one is ' . $c['status'] . ').', 409);
        $open = one($p, "SELECT id FROM billing_charges WHERE replaces_id=? AND status='Pending Approval'", [$c['id']]);
        must(!$open, 'A change or removal of this line is already waiting for Admin approval. Withdraw it first, or wait for the decision.', 409);
    }
    private static function label(array $c): string {
        return $c['kind'] . ' "' . $c['description'] . '" PHP ' . number_format((float)$c['amount'], 2);
    }
    private static function notifyAdmin(PDO $p, array $b, int $cid, string $what): void {
        Notify::roles($p, ['admin'], 'Billing approval', $b['trip_ref'] . ": $what is waiting for your approval.", 'billing', 'billing-adj-' . $cid);
    }

    /** New additional charge or per-trip rate adjustment (request; counts only after Admin approval). */
    private static function chargeAdd(array $u, array $d): array {
        requireRole($u, 'billing');
        $id = idField($d, 'billing_id');
        $kind = choiceField($d, 'kind', self::KINDS, false) ?? 'Additional Charge';
        $desc = field($d, 'description', 150);
        $amount = self::signedAmount($d, $kind);
        $reason = $kind === 'Rate Adjustment' ? field($d, 'reason', 300) : optField($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $id, $kind, $desc, $amount, $reason) {
            $b = self::lock($p, $id);
            self::editable($b);
            self::mustStayPositive($b, (float)$amount, 'This ' . strtolower($kind));
            $cid = insert($p, 'billing_charges', ['billing_id' => $id, 'kind' => $kind, 'request_type' => 'New', 'description' => $desc, 'amount' => $amount,
                'status' => 'Pending Approval', 'reason' => $reason, 'added_by' => $u['id']]);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $id, 'billing adjustment requested', $b['trip_ref'] . ": $kind \"$desc\" PHP $amount" . ($reason ? " ($reason)" : ''),
                ['Requested ' . strtolower($kind) => null], ['Requested ' . strtolower($kind) => "$desc: PHP $amount (Pending Approval)"]);
            self::notifyAdmin($p, $b, $cid, "$kind \"$desc\" PHP " . number_format((float)$amount, 2));
            return ['charge_id' => $cid, 'message' => 'Sent to Admin for approval. It counts in the billing total only after it is approved.'];
        });
    }

    /** Change an APPROVED line: a new request that replaces it only when the Admin approves. */
    private static function chargeChange(array $u, array $d): array {
        requireRole($u, 'billing');
        $cid = idField($d, 'charge_id');
        $desc = field($d, 'description', 150);
        $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $d, $cid, $desc, $reason) {
            [$b, $c] = self::lockCharge($p, $cid);
            self::editable($b);
            self::mustBeReplaceable($p, $c);
            $amount = self::signedAmount($d, $c['kind']);
            must($amount !== $c['amount'] || $desc !== $c['description'], 'Nothing changed. Enter the new amount or description.');
            self::mustStayPositive($b, (float)$amount - (float)$c['amount'], 'This change');
            $nid = insert($p, 'billing_charges', ['billing_id' => $b['id'], 'kind' => $c['kind'], 'request_type' => 'Change', 'replaces_id' => $cid, 'description' => $desc,
                'amount' => $amount, 'status' => 'Pending Approval', 'reason' => $reason, 'added_by' => $u['id']]);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $b['id'], 'billing adjustment change requested', $b['trip_ref'] . ': ' . self::label($c) . " -> \"$desc\" PHP $amount ($reason)",
                [$c['kind'] => $c['description'] . ': PHP ' . $c['amount']], [$c['kind'] => "$desc: PHP $amount (Pending Approval)"]);
            self::notifyAdmin($p, $b, $nid, 'change of ' . self::label($c) . ' to PHP ' . number_format((float)$amount, 2));
            return ['charge_id' => $nid, 'message' => 'Change sent to Admin for approval. The current approved amount stays until then.'];
        });
    }

    /** Remove an APPROVED line: a removal request (the line stays until the Admin approves). */
    private static function chargeRemove(array $u, array $d): array {
        requireRole($u, 'billing');
        $cid = idField($d, 'charge_id');
        $reason = field($d, 'reason', 300);
        return transaction(function (PDO $p) use ($u, $cid, $reason) {
            [$b, $c] = self::lockCharge($p, $cid);
            self::editable($b);
            must($c['status'] !== 'Pending Approval', 'This request is still waiting for approval. Withdraw it instead.', 409);
            self::mustBeReplaceable($p, $c);
            self::mustStayPositive($b, -(float)$c['amount'], 'Removing this line');
            $nid = insert($p, 'billing_charges', ['billing_id' => $b['id'], 'kind' => $c['kind'], 'request_type' => 'Removal', 'replaces_id' => $cid, 'description' => $c['description'],
                'amount' => $c['amount'], 'status' => 'Pending Approval', 'reason' => $reason, 'added_by' => $u['id']]);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $b['id'], 'billing adjustment removal requested', $b['trip_ref'] . ': ' . self::label($c) . " ($reason)",
                [$c['kind'] => $c['description'] . ': PHP ' . $c['amount']], [$c['kind'] => 'Removal Pending Approval']);
            self::notifyAdmin($p, $b, $nid, 'removal of ' . self::label($c));
            return ['charge_id' => $nid, 'message' => 'Removal sent to Admin for approval. The line stays in the total until then.'];
        });
    }

    /** The requester (only the Billing user who made it) withdraws a request that is still pending. Nothing is deleted. */
    private static function chargeWithdraw(array $u, array $d): array {
        requireRole($u, 'billing');
        $cid = idField($d, 'charge_id');
        return transaction(function (PDO $p) use ($u, $cid) {
            [$b, $c] = self::lockCharge($p, $cid);
            must($c['status'] === 'Pending Approval', 'Only a pending request can be withdrawn (this one is ' . $c['status'] . ').', 409);
            must((int)$c['added_by'] === (int)$u['id'], 'Only the person who made this request can withdraw it.', 403);
            run($p, "UPDATE billing_charges SET status='Withdrawn', decided_by=?, decided_at=NOW() WHERE id=? AND status='Pending Approval'", [$u['id'], $cid]);
            Notify::resolve($p, 'billing-adj-' . $cid);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $b['id'], 'billing adjustment request withdrawn', $b['trip_ref'] . ': ' . $c['request_type'] . ' ' . self::label($c),
                ['Request status' => 'Pending Approval'], ['Request status' => 'Withdrawn']);
            return ['message' => 'Request withdrawn.'];
        });
    }

    /** Admin approves or rejects a pending request. Approving a Change/Removal replaces the old line atomically. */
    private static function chargeDecide(array $u, array $d): array {
        requireRole($u, 'admin');
        $cid = idField($d, 'charge_id');
        $decision = choiceField($d, 'decision', ['approve', 'reject']);
        $notes = $decision === 'reject' ? field($d, 'reason', 300) : optField($d, 'notes', 300);
        return transaction(function (PDO $p) use ($u, $cid, $decision, $notes) {
            [$b, $c] = self::lockCharge($p, $cid);
            must($c['status'] === 'Pending Approval', 'This request was already decided (' . $c['status'] . '). Reload the page.', 409);
            must((int)$c['added_by'] !== (int)$u['id'], 'The person who requested this cannot approve or reject it (separation of duties).', 403);
            $old = $c['replaces_id'] ? one($p, 'SELECT * FROM billing_charges WHERE id=? FOR UPDATE', [$c['replaces_id']]) : null;
            $what = $c['request_type'] === 'New' ? self::label($c) : strtolower($c['request_type']) . ' of ' . self::label($old ?? $c);
            Notify::resolve($p, 'billing-adj-' . $cid);

            if ($decision === 'reject') {
                run($p, "UPDATE billing_charges SET status='Rejected', decided_by=?, decided_at=NOW(), decision_notes=? WHERE id=? AND status='Pending Approval'", [$u['id'], $notes, $cid]);
                auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $b['id'], 'billing adjustment rejected', $b['trip_ref'] . ": $what: $notes",
                    ['Request status' => 'Pending Approval'], ['Request status' => 'Rejected', 'Billing total' => $b['total_amount']]);
                Notify::user($p, (int)$c['added_by'], 'Billing approval', $b['trip_ref'] . ": Admin rejected the $what: $notes", 'billing');
                return ['message' => 'Request rejected. The billing total is unchanged.'];
            }

            self::editable($b);
            $delta = match ($c['request_type']) { 'New' => (float)$c['amount'], 'Change' => (float)$c['amount'] - (float)$old['amount'], 'Removal' => -(float)$old['amount'] };
            if ($c['request_type'] !== 'New') {
                must($old && $old['status'] === 'Approved', 'The line this request replaces is no longer approved (' . ($old['status'] ?? 'missing') . '). Reject this request.', 409);
            }
            self::mustStayPositive($b, $delta, 'Approving this request');
            if ($old) run($p, 'UPDATE billing_charges SET status=? WHERE id=? AND status=\'Approved\'', [$c['request_type'] === 'Change' ? 'Superseded' : 'Removed', $old['id']]);
            run($p, "UPDATE billing_charges SET status='Approved', decided_by=?, decided_at=NOW(), decision_notes=? WHERE id=? AND status='Pending Approval'", [$u['id'], $notes, $cid]);
            self::recompute($p, (int)$b['id']);
            $after = one($p, 'SELECT total_amount FROM billings WHERE id=?', [$b['id']])['total_amount'];
            must((float)$after > 0, 'The billing total must stay above zero.', 409);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $b['id'], 'billing adjustment approved', $b['trip_ref'] . ": $what" . ($notes ? " ($notes)" : ''),
                ['Request status' => 'Pending Approval', 'Billing total' => $b['total_amount']], ['Request status' => 'Approved', 'Billing total' => $after]);
            Notify::user($p, (int)$c['added_by'], 'Billing approval', $b['trip_ref'] . ": Admin approved the $what. Billing total is now PHP " . number_format((float)$after, 2) . '.', 'billing');
            return ['message' => 'Approved. Billing total is now PHP ' . number_format((float)$after, 2) . '.'];
        });
    }

    /** Document verification: the required trip documents must be there. */
    private static function verify(array $u, array $d): array {
        requireRole($u, 'billing');
        $id = idField($d, 'billing_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $b = self::lock($p, $id);
            must($b['status'] === 'For Billing', 'Only records in For Billing are verified.', 409);
            must($b['trip_status'] === 'Completed', 'The trip is not completed.', 409);
            $missing = [];
            foreach (['Waybill', 'Delivery Receipt', 'Acknowledgement Receipt'] as $cat) if (!Files::count($p, 'trip', (int)$b['trip_id'], $cat)) $missing[] = $cat;
            must(!$missing, 'Cannot verify. Missing supporting documents: ' . implode(', ', $missing) . '.', 422);
            run($p, 'UPDATE billings SET docs_verified_by=?, docs_verified_at=NOW() WHERE id=?', [$u['id'], $id]);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $id, 'supporting documents verified', $b['trip_ref'],
                ['Supporting documents' => $b['docs_verified_at'] ? 'Verified' : 'Not verified'],
                ['Supporting documents' => 'Verified']);
            return ['message' => 'Documents verified. The billing can now be finalized.'];
        });
    }

    /** Finalize -> invoice number, invoice date and due date (date + client payment terms). */
    private static function finalize(array $u, array $d): array {
        requireRole($u, 'billing');
        $id = idField($d, 'billing_id');
        $date = dateField($d, 'invoice_date');
        must($date <= date('Y-m-d'), 'Invoice date cannot be in the future.');
        $number = optField($d, 'invoice_number', 40);
        return transaction(function (PDO $p) use ($u, $id, $date, $number) {
            $b = self::lock($p, $id);
            must($b['status'] === 'For Billing', 'Only records in For Billing can be finalized.', 409);
            core('TripPricing');$pricing=TripPricing::saved($p,$id);
            if($pricing!==null)must(!$pricing['missing']&&$pricing['fuel']!==null,'Pending Fuel Calculation: resolve and review dispatch pricing before issuing an invoice.',409);
            must((bool)$b['docs_verified_at'], 'Verify the supporting documents first.', 409);
            $pending = (int)one($p, "SELECT COUNT(*) AS n FROM billing_charges WHERE billing_id=? AND status='Pending Approval'", [$id])['n'];
            must($pending === 0, "$pending billing adjustment request(s) are waiting for Admin approval. Finalize after the Admin decides, or withdraw the request.", 409);
            self::recompute($p, $id);   // the total is exactly the approved lines (safety net)
            $b['total_amount'] = one($p, 'SELECT total_amount FROM billings WHERE id=?', [$id])['total_amount'];
            must((float)$b['total_amount'] > 0, 'The billing amount is zero.', 409);
            Notify::resolve($p, 'billing-verify-' . $b['trip_id']);
            if ($number !== null) must(!one($p, 'SELECT id FROM billings WHERE invoice_number=? AND id<>?', [$number, $id]), 'Invoice number ' . $number . ' is already used.', 409);
            $number ??= refNumber('SI', $id);
            $c = one($p, 'SELECT company_name, payment_terms_days FROM clients WHERE id=?', [$b['client_id']]);
            $terms = $c['payment_terms_days'] !== null ? (int)$c['payment_terms_days'] : (int)Settings::value('default_payment_terms_days');
            $due = date('Y-m-d', strtotime($date . " +$terms days"));
            run($p, "UPDATE billings SET status='Invoiced', invoice_number=?, invoice_date=?, due_date=?, finalized_by=?, finalized_at=NOW() WHERE id=?", [$number, $date, $due, $u['id'], $id]);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $id, 'invoice finalized', "$number for " . $b['trip_ref'] . ' PHP ' . $b['total_amount'] . ", due $due",
                ['Status' => $b['status'], 'Invoice number' => $b['invoice_number'], 'Invoice date' => $b['invoice_date'], 'Due date' => $b['due_date']],
                ['Status' => 'Invoiced', 'Invoice number' => $number, 'Invoice date' => $date, 'Due date' => $due]);
            return ['message' => "Invoice $number finalized (due $due). It is ready for printing and collection."];
        });
    }

    /** Client payment. Refused until the acknowledgement receipt and payment details are complete. */
    private static function collect(array $u, array $d): array {
        requireRole($u, 'billing');
        $id = idField($d, 'billing_id');
        $amount = moneyField($d, 'amount');
        $pay = Money::paymentFields($d, 'payment_date');
        $ar = field($d, 'ar_number', 40);
        $ref = $pay['reference_number'] ?? ($pay['payment_method'] === 'Cash' ? 'CASH ' . $ar : null);
        must($ref !== null, 'Enter the payment reference number.');
        must(Files::has('ack_receipt'), 'Attach the acknowledgement receipt before confirming the payment.', 422);
        return transaction(function (PDO $p) use ($u, $id, $amount, $pay, $ar, $ref) {
            $b = self::lock($p, $id);
            must(in_array($b['status'], ['Invoiced', 'Partially Paid'], true), 'Payments are recorded for finalized invoices (this record is ' . $b['status'] . ').', 409);
            $balance = round((float)$b['total_amount'] - (float)$b['amount_paid'], 2);
            must((float)$amount <= $balance, 'The amount is more than the balance (PHP ' . number_format($balance, 2) . ').');
            must($pay['payment_date'] >= $b['invoice_date'], 'Payment date is before the invoice date.');
            $cid = insert($p, 'collections', ['billing_id' => $id, 'amount' => $amount, 'payment_date' => $pay['payment_date'], 'payment_method' => $pay['payment_method'],
                'reference' => $ref, 'ar_number' => $ar, 'cheque_number' => $pay['cheque_number'], 'bank_name' => $pay['bank_name'], 'recorded_by' => $u['id']]);
            Files::store($p, $u, 'ack_receipt', 'billing', $id, self::DOC_PAYMENT, true, 'acknowledgement receipt');
            $paid = round((float)$b['amount_paid'] + (float)$amount, 2);
            $status = $paid >= (float)$b['total_amount'] ? 'Paid' : 'Partially Paid';
            run($p, 'UPDATE billings SET amount_paid=?, status=? WHERE id=?', [$paid, $status, $id]);
            auditWithChanges($p, $u['id'], 'Billing', 'billing ' . $id, 'payment recorded', $b['invoice_number'] . ": PHP $amount ({$pay['payment_method']}), AR $ar -> $status",
                ['Amount paid' => $b['amount_paid'], 'Remaining balance' => number_format($balance, 2, '.', ''), 'Status' => $b['status']],
                ['Amount paid' => number_format($paid, 2, '.', ''), 'Remaining balance' => number_format((float)$b['total_amount'] - $paid, 2, '.', ''), 'Status' => $status]);
            Notify::roles($p, ['finance'], 'Collection', $b['invoice_number'] . ' received PHP ' . number_format((float)$amount, 2) . " ($status).", 'billing');
            return ['collection_id' => $cid, 'message' => 'Payment recorded. Invoice is ' . $status . '.'];
        });
    }

    // S4B-2D-D: explicit fields only: keep invoice print fields, omit unrelated metadata.
    private static function details(array $u, array $d): array {
        must(Access::canOpen($u, 'billing'), 'Not allowed.', 403);
        $id = idField($d, 'billing_id'); $p = db();
        $b = one($p, 'SELECT b.*, (b.total_amount-b.amount_paid) AS balance, vu.full_name AS verified_by_name, fu.full_name AS finalized_by_name FROM billings b
            LEFT JOIN users vu ON vu.id=b.docs_verified_by LEFT JOIN users fu ON fu.id=b.finalized_by WHERE b.id=?', [$id]);
        must((bool)$b, 'Billing record not found.', 404);
        $docs = array_merge(Files::of($p, 'trip', (int)$b['trip_id']), Files::of($p, 'billing', $id));
        return [
            'billing' => $b, 'pricing' => TripPricing::saved($p,$id),
            'trip' => one($p, 'SELECT t.id, t.trip_ref, t.truck_type, t.pickup_point, t.delivery_point, t.dispatched_at, t.completed_at, t.waybill_number, t.units_delivered, v.plate_number, d1.full_name AS driver_name, d2.full_name AS driver2_name, h.full_name AS helper_name FROM trips t
                LEFT JOIN vehicles v ON v.id=t.vehicle_id LEFT JOIN personnel d1 ON d1.id=t.driver_id LEFT JOIN personnel d2 ON d2.id=t.driver2_id LEFT JOIN personnel h ON h.id=t.helper_id WHERE t.id=?', [$b['trip_id']]),
            'client' => one($p, 'SELECT id, company_name, tin, business_address, contact_person, contact_number, email, payment_terms_days FROM clients WHERE id=?', [$b['client_id']]),
            // charges = ONLY the lines in the billing total (also used for the printed invoice); requests = everything else.
            'charges' => many($p, 'SELECT c.*, u.full_name AS added_by_name, du.full_name AS decided_by_name FROM billing_charges c JOIN users u ON u.id=c.added_by LEFT JOIN users du ON du.id=c.decided_by WHERE c.billing_id=? AND ' . self::counted('c') . ' ORDER BY c.id', [$id]),
            'requests' => many($p, 'SELECT c.*, u.full_name AS added_by_name, du.full_name AS decided_by_name, o.description AS old_description, o.amount AS old_amount FROM billing_charges c
                JOIN users u ON u.id=c.added_by LEFT JOIN users du ON du.id=c.decided_by LEFT JOIN billing_charges o ON o.id=c.replaces_id
                WHERE c.billing_id=? AND NOT (' . self::counted('c') . ') ORDER BY (c.status=\'Pending Approval\') DESC, c.id DESC', [$id]),
            'collections' => many($p, 'SELECT co.*, u.full_name AS recorded_by_name FROM collections co JOIN users u ON u.id=co.recorded_by WHERE co.billing_id=? ORDER BY co.id', [$id]),
            'documents' => $docs,
            'vatRate' => Settings::value('vat_rate'),
        ];
    }

    /** Statement of account of a client (unpaid invoices). */
    private static function soa(array $u, array $d): array {
        must(Access::canOpen($u, 'billing'), 'Not allowed.', 403);
        $cid = idField($d, 'client_id'); $p = db();
        $c = one($p, 'SELECT id, company_name, business_address FROM clients WHERE id=?', [$cid]);
        must((bool)$c, 'Client not found.', 404);
        return ['client' => $c, 'invoices' => many($p, "SELECT b.invoice_number, b.invoice_date, b.due_date, b.total_amount, b.amount_paid, (b.total_amount-b.amount_paid) AS balance,
                t.trip_ref, t.waybill_number, DATEDIFF(CURRENT_DATE(), b.due_date) AS days_overdue
            FROM billings b JOIN trips t ON t.id=b.trip_id WHERE b.client_id=? AND b.status IN ('Invoiced','Partially Paid') ORDER BY b.invoice_date", [$cid])];
    }
}
