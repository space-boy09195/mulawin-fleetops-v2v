<?php
/*
 * =====================================================================
 *  backend/Modules/Maintenance/Purchasing.php  —  PARTS & PURCHASING
 * =====================================================================
 *  Objective 2.4  truck-parts requests, inventory check, stock release, inventory update
 *  Objective 2.5  purchase requests, purchase order approval, receiving of parts
 *  Objective 2.6  inventory monitoring: stock-in, stock-out, partially received purchases
 *
 *  PDF #12 Maintenance Module:
 *   A. Stock-out: Motorpool Custodian requests parts (REQUEST FORM) -> Purchasing
 *      Officer checks stock -> releases and the system updates the inventory.
 *   B. Stock-in: Purchasing Officer makes a Purchase Order Request (parts, qty,
 *      unit price; system computes the total) -> automatic approval routing:
 *      PHP 10,000 and below = Finance, above = Admin -> approver approves ->
 *      AUTOMATIC BUDGET VALIDATION (reserve the amount in the same locked step, so
 *      two POs can never use the same remaining money) -> approved PO document with
 *      the approver's e-signature -> Finance sees it as Pending Payment ->
 *      receiving (partial allowed: "Partially Received") adds stock -> Finance pays.
 *
 *  SUPPLIER DETAILS (Nov 2026): the Purchasing Officer types the supplier in the PO form.
 *  Name, address, contact person, phone and email are saved IN the purchase order
 *  (supplier_name ... supplier_email = snapshot), so a PO always prints and pays with the
 *  details it was approved with. Suppliers used before are offered as suggestions; no
 *  supplier record is created. The old suppliers table is kept for purchase orders made
 *  before this change (supplier_id), and every query reads the snapshot first:
 *  COALESCE(o.supplier_name, s.name) with LEFT JOIN suppliers.
 * =====================================================================
 */
declare(strict_types=1);
core('Notify');
core('Files');
core('Settings');

final class Purchasing {
    /**
     * Role-specific workspace pages. Every page returns ONLY the sections it shows
     * (Access::canOpen() has already limited who may call each page).
     *   purchasing_overview  counts and short work lists, no full tables
     *   parts_requests       requests to process (fulfilment fields only)
     *   purchase_orders      purchase orders (+ what the PO form needs, Purchasing Officer only)
     *   inventory            parts and stock values
     *   stock_movements      stock-in / stock-out / adjustment records
     * Motorpool has none of these pages; it uses request_catalog / my_requests below.
     */
    public static function read(array $u, string $page, array $q): array {
        $p = db();
        // MOTORPOOL_WORKSPACE_PHASE1: Motorpool's Parts Requests page. Separate from the Purchasing pages on purpose:
        // it never reads costs, stock counts, budget, supplier or purchase-order data.
        if ($page === 'mp_parts_requests') return self::motorpoolPage($u, $p);
        $stock = hasRole($u, 'purchasing');
        // S4B-2D-B: only roles that handle purchasing money see the budget.
        $budget = fn() => hasRole($u, 'purchasing', 'finance', 'admin', 'audit')
            // ACCOUNTING_INTEGRITY_G7: in_period = the same date rule po_decide uses; an expired budget is shown as ended, not as available.
            ? (one($p, "SELECT *, amount-reserved_amount-spent_amount AS available, (CURRENT_DATE() BETWEEN period_start AND period_end) AS in_period FROM budgets WHERE status='Active' AND category='Purchasing' ORDER BY id DESC LIMIT 1") ?? []) : [];
        $partsFull = fn() => many($p, "SELECT pt.*, ROUND(pt.quantity_on_hand*pt.unit_cost,2) AS stock_value,
                    (pt.reorder_level>0 AND pt.quantity_on_hand<=pt.reorder_level) AS low_stock FROM parts pt ORDER BY pt.active DESC, pt.part_number");
        $data = match ($page) {
            'purchasing_overview' => self::overview($u, $p, $budget),
            'parts_requests' => readSections([
                'requests' => fn() => self::requestRows($p),
                // The "Create PO" shortcut needs the part list and supplier suggestions: Purchasing Officer only.
                'parts' => fn() => $stock ? $partsFull() : [],
                'supplierSuggestions' => fn() => $stock ? self::supplierSuggestions($p) : [],
                'budget' => $budget,
            ]),
            'purchase_orders' => readSections([
                'orders' => fn() => many($p, "SELECT o.*, COALESCE(o.supplier_name, s.name) AS supplier, ru.full_name AS requested_by_name, du.full_name AS decided_by_name,
                        (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) AS received_value,
                        (SELECT GROUP_CONCAT(CONCAT(pt.part_number, ' ', l.received_qty, '/', l.quantity) SEPARATOR '; ') FROM purchase_order_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.purchase_order_id=o.id) AS items
                    FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id JOIN users ru ON ru.id=o.requested_by LEFT JOIN users du ON du.id=o.decided_by
                    ORDER BY FIELD(o.status,'Pending Approval','Approved','Partially Received','Received','Closed','Rejected','Rejected - Insufficient Budget'), o.id DESC LIMIT 500"),
                // PO form inputs (Purchasing Officer only): parts to order, requests that can be linked, supplier suggestions.
                'parts' => fn() => $stock ? $partsFull() : [],
                'linkableRequests' => fn() => $stock ? many($p, "SELECT r.id, r.request_ref, r.status, v.plate_number FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id
                    WHERE r.status IN ('Requested','Partially Released','Not Available') ORDER BY r.id DESC LIMIT 200") : [],
                'supplierSuggestions' => fn() => $stock ? self::supplierSuggestions($p) : [],
                'budget' => $budget,
            ]),
            'inventory' => readSections(['parts' => $partsFull,
                // STOCK_COUNT_RECONCILIATION_V1: counts waiting for review first, then the latest reviewed ones.
                'counts' => fn() => self::countsReady($p) ? many($p, self::COUNT_SQL . " ORDER BY c.status='Submitted' DESC, c.id DESC LIMIT 300") : []]),
            'stock_movements' => readSections([
                'movements' => fn() => many($p, "SELECT sm.*, pt.part_number, pt.description, v.plate_number, u.full_name AS actor FROM stock_movements sm JOIN parts pt ON pt.id=sm.part_id
                    LEFT JOIN vehicles v ON v.id=sm.vehicle_id JOIN users u ON u.id=sm.actor_id ORDER BY sm.id DESC LIMIT 500"),
            ]),
            default => throw new BusinessRuleException('Unknown page.', 404),
        };
        $data['threshold'] = Settings::value('purchase_approval_threshold');
        $data['can'] = [
            'stock' => $stock,
            'approveSmall' => hasRole($u, 'finance'), 'approveBig' => hasRole($u, 'admin'),
            'count' => $stock, 'reviewCount' => hasRole($u, 'admin'),
        ];
        $data['countsReady'] = self::countsReady($p);
        return $data;
    }

    /** Parts requests as Purchasing needs them to fulfil: no incident notes, driver or employee data. */
    private static function requestRows(PDO $p): array {
        // STAGE_B_PURCHASING_UI: requested/released units and the linked purchase orders (ref + status) per request.
        return many($p, "SELECT r.id, r.request_ref, r.vehicle_id, r.status, r.purpose, r.notes, r.created_at, r.processed_at, v.plate_number, m.job_ref,
                ru.full_name AS requested_by_name, pu.full_name AS processed_by_name,
                (SELECT COALESCE(SUM(l.quantity),0) FROM part_request_lines l WHERE l.part_request_id=r.id) AS requested_units,
                (SELECT COALESCE(SUM(l.released_qty),0) FROM part_request_lines l WHERE l.part_request_id=r.id) AS released_units,
                (SELECT GROUP_CONCAT(CONCAT(o.po_ref, ' (', o.status, ')') ORDER BY o.id SEPARATOR '; ') FROM purchase_orders o WHERE o.part_request_id=r.id) AS linked_pos,
                (SELECT GROUP_CONCAT(CONCAT(pt.part_number, ' x', l.quantity, IF(l.released_qty>0, CONCAT(' (released ', l.released_qty, ')'), '')) SEPARATOR '; ')
                   FROM part_request_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.part_request_id=r.id) AS items
            FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id
            JOIN users ru ON ru.id=r.requested_by LEFT JOIN users pu ON pu.id=r.processed_by
            ORDER BY FIELD(r.status,'Requested','Partially Released','Not Available','Released','Cancelled'), r.id DESC LIMIT 500");
    }

    /** Purchasing Overview: what needs action now. Counts and short lists only. */
    private static function overview(array $u, PDO $p, callable $budget): array {
        return readSections([
            'counts' => fn() => [
                'requests_open' => (int)one($p, "SELECT COUNT(*) AS n FROM part_requests WHERE status='Requested'")['n'],
                'requests_followup' => (int)one($p, "SELECT COUNT(*) AS n FROM part_requests WHERE status IN ('Partially Released','Not Available')")['n'],
                'po_pending' => (int)one($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status='Pending Approval'")['n'],
                'po_to_receive' => (int)one($p, "SELECT COUNT(*) AS n FROM purchase_orders WHERE status IN ('Approved','Partially Received')")['n'],
                'inventory_value' => (float)one($p, 'SELECT COALESCE(SUM(quantity_on_hand*unit_cost),0) AS v FROM parts WHERE active=1')['v'],
                'low_stock' => (int)one($p, 'SELECT COUNT(*) AS n FROM parts WHERE active=1 AND reorder_level>0 AND quantity_on_hand<=reorder_level')['n'],
            ],
            'requests' => fn() => many($p, "SELECT r.id, r.request_ref, r.status, r.created_at, v.plate_number, m.job_ref,
                    (SELECT GROUP_CONCAT(CONCAT(pt.part_number, ' x', l.quantity) SEPARATOR '; ') FROM part_request_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.part_request_id=r.id) AS items
                FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id
                WHERE r.status IN ('Requested','Partially Released','Not Available') ORDER BY FIELD(r.status,'Requested','Partially Released','Not Available'), r.id LIMIT 10"),
            'receiving' => fn() => many($p, "SELECT o.id, o.po_ref, o.status, o.total_amount, o.created_at, COALESCE(o.supplier_name, s.name) AS supplier FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id
                WHERE o.status IN ('Approved','Partially Received') ORDER BY o.id LIMIT 10"),
            // STAGE_B_PURCHASING_UI: POs waiting for Finance/Admin approval, and POs auto-rejected for budget in the last 30 days.
            'approvals' => fn() => many($p, "SELECT o.id, o.po_ref, o.status, o.approver_role, o.total_amount, o.created_at, o.decided_at, COALESCE(o.supplier_name, s.name) AS supplier
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id
                WHERE o.status='Pending Approval' OR (o.status='Rejected - Insufficient Budget' AND o.decided_at >= NOW() - INTERVAL 30 DAY)
                ORDER BY FIELD(o.status,'Pending Approval','Rejected - Insufficient Budget'), o.id LIMIT 10"),
            'payments' => fn() => array_map('floatval', one($p, "SELECT COUNT(*) AS n, COALESCE(SUM(GREATEST((SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id)-o.paid_amount,0)),0) AS due
                FROM purchase_orders o WHERE o.payment_status IN ('Pending Payment','Partially Paid')
                  AND (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=o.id) > o.paid_amount")),
            'lowStock' => fn() => many($p, "SELECT id, part_number, description, unit, quantity_on_hand, reorder_level FROM parts
                WHERE active=1 AND reorder_level>0 AND quantity_on_hand<=reorder_level ORDER BY (quantity_on_hand-reorder_level), part_number LIMIT 10"),
            'budget' => $budget,
            // UI_REFERENCE_V1 (read-only): Purchase Order Status panel and Recent Purchasing Activity of the Overview.
            'poStatus' => fn() => one($p, "SELECT
                    COALESCE(SUM(status='Pending Approval'),0) AS pending_approval,
                    COALESCE(SUM(status IN ('Approved','Partially Received')),0) AS awaiting_delivery,
                    COALESCE(SUM(status='Partially Received'),0) AS partially_received,
                    -- same rule as the Purchase Orders 'Awaiting payment' tab: received value not fully paid
                    COALESCE(SUM(payment_status IN ('Pending Payment','Partially Paid')
                        AND (SELECT COALESCE(SUM(l.received_qty*l.unit_price),0) FROM purchase_order_lines l WHERE l.purchase_order_id=purchase_orders.id) > paid_amount),0) AS awaiting_payment
                FROM purchase_orders"),
            'activity' => fn() => many($p, "SELECT sm.id, sm.movement_type, sm.quantity_delta, sm.reference_type, sm.reference_id, sm.reason, sm.created_at,
                    pt.part_number, pt.unit, u.full_name AS actor,
                    CASE sm.reference_type WHEN 'purchase_order' THEN (SELECT o.po_ref FROM purchase_orders o WHERE o.id=sm.reference_id)
                        WHEN 'part_request' THEN (SELECT r.request_ref FROM part_requests r WHERE r.id=sm.reference_id) END AS ref
                FROM stock_movements sm JOIN parts pt ON pt.id=sm.part_id JOIN users u ON u.id=sm.actor_id ORDER BY sm.id DESC LIMIT 12"),
        ]);
    }

    /**
     * Suppliers used before, for the suggestions of the PO form. Per supplier name and per field,
     * the newest non-empty value wins: purchase orders newest first, then the old masterlist.
     */
    private static function supplierSuggestions(PDO $p): array {
        $out = [];
        $add = function (array $r) use (&$out) {
            $k = strtoupper(trim((string)$r['name']));
            if ($k === '') return;
            $out[$k] ??= ['name' => $k, 'address' => null, 'contact_person' => null, 'contact_number' => null, 'email' => null];
            foreach (['address', 'contact_person', 'contact_number', 'email'] as $f) {
                if (($out[$k][$f] ?? '') === '' && trim((string)($r[$f] ?? '')) !== '') $out[$k][$f] = $r[$f];
            }
        };
        foreach (many($p, "SELECT supplier_name AS name, supplier_address AS address, supplier_contact AS contact_person, supplier_phone AS contact_number,
                    supplier_email AS email FROM purchase_orders WHERE supplier_name IS NOT NULL ORDER BY id DESC LIMIT 2000") as $r) $add($r);
        foreach (many($p, 'SELECT name, address, contact_person, contact_number, email FROM suppliers WHERE active=1') as $r) $add($r);
        ksort($out);
        return array_values($out);
    }

    /** Supplier details typed in the PO form (saved with the PO). */
    private static function supplierSnapshot(array $d): array {
        $name = strtoupper(preg_replace('/\s+/', ' ', field($d, 'supplier_name', 150)));
        $row = ['supplier_name' => $name, 'supplier_address' => optField($d, 'supplier_address', 255),
                'supplier_contact' => optField($d, 'supplier_contact', 120), 'supplier_phone' => phoneField($d, 'supplier_phone', 60),
                'supplier_email' => ($e = optField($d, 'supplier_email', 120)) !== null ? strtolower($e) : null];
        if ($row['supplier_email'] !== null) must((bool)filter_var($row['supplier_email'], FILTER_VALIDATE_EMAIL), 'Enter a valid supplier email address.');
        return $row;
    }

    public static function action(array $u, string $action, array $d): array {
        return match ($action) {
            'part_save' => self::partSave($u, $d),
            // Legacy supplier masterlist writes are disabled: suppliers are entered on Purchase Orders.
            'adjust' => self::adjust($u, $d),
            'request_create' => self::requestCreate($u, $d),
            'request_process' => self::requestProcess($u, $d),
            'request_cancel' => self::requestCancel($u, $d),
            'po_create' => self::poCreate($u, $d),
            'po_decide' => self::poDecide($u, $d),
            'po_receive' => self::poReceive($u, $d),
            'po_close' => self::poClose($u, $d),
            'po_details' => self::poDetails($u, $d),
            'request_details' => self::requestDetails($u, $d),
            'part_history' => self::partHistory($u, $d),
            // STOCK_COUNT_RECONCILIATION_V1 (needs migration 2026-11-12_13): count -> Admin review -> one traceable adjustment.
            'count_submit' => self::countSubmit($u, $d),
            'count_review' => self::countReview($u, $d),
            'count_details' => self::countDetails($u, $d),
            'request_catalog' => self::requestCatalog($u),
            'my_requests' => self::myRequests($u),
            default => throw new BusinessRuleException('Unknown action.', 404),
        };
    }

    /** Records a stock change and keeps quantity_on_hand >= 0 (checked again by the database). */
    private static function move(PDO $p, array $u, int $partId, string $type, int $delta, float $cost, string $reason, ?string $refType, ?int $refId, ?int $vehicleId = null, ?int $jobId = null): void {
        $pt = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [$partId]);
        must((bool)$pt, 'Part not found.', 404);
        must((int)$pt['quantity_on_hand'] + $delta >= 0, 'Not enough stock of ' . $pt['part_number'] . ' (on hand: ' . $pt['quantity_on_hand'] . ').', 409);
        run($p, 'UPDATE parts SET quantity_on_hand=quantity_on_hand+? WHERE id=?', [$delta, $partId]);
        insert($p, 'stock_movements', ['part_id' => $partId, 'movement_type' => $type, 'quantity_delta' => $delta, 'unit_cost' => number_format($cost, 2, '.', ''),
            'reason' => cut($reason, 150), 'reference_type' => $refType, 'reference_id' => $refId, 'vehicle_id' => $vehicleId, 'maintenance_job_id' => $jobId, 'actor_id' => $u['id']]);
    }

    // ---------------------------------------------------------------- parts and suppliers
    private static function partSave(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $id = optId($d, 'id');
        $row = ['part_number' => strtoupper(field($d, 'part_number', 70)), 'description' => field($d, 'description', 200), 'category' => optField($d, 'category', 80),
                'unit' => field($d, 'unit', 20), 'unit_cost' => moneyField($d, 'unit_cost', true), 'reorder_level' => intField($d, 'reorder_level', 0),
                'warranty_info' => optField($d, 'warranty_info', 150)];
        // STOCK_COUNT_RECONCILIATION_V1: a new part starts at 0. Stock comes in only through PO receiving or a reviewed stock count.
        must($id || (int)(intField($d, 'opening_qty', 0, false) ?? 0) === 0,
            'A new part starts with 0 on hand. Receive it on a purchase order, or record a stock count for the Admin to review.', 403);
        $opening = 0;
        return transaction(function (PDO $p) use ($u, $id, $row, $opening) {
            must(!one($p, 'SELECT id FROM parts WHERE part_number=? AND id<>?', [$row['part_number'], $id ?? 0]), 'Part number ' . $row['part_number'] . ' already exists.', 409);
            if ($id) {
                $old = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [$id]);
                must((bool)$old, 'Part not found.', 404);
                $cols = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
                run($p, "UPDATE parts SET $cols WHERE id=?", array_merge(array_values($row), [$id]));
                $before = []; $after = [];
                foreach (['part_number', 'description', 'category', 'unit', 'unit_cost', 'reorder_level', 'warranty_info'] as $key) {
                    $label = ucwords(str_replace('_', ' ', $key));
                    $before[$label] = $old[$key]; $after[$label] = $row[$key];
                }
                auditWithChanges($p, $u['id'], 'Inventory', 'part ' . $id, 'part updated', $row['part_number'], $before, $after);
                return ['message' => 'Part updated.'];
            }
            $id = insert($p, 'parts', $row);
            if ($opening > 0) self::move($p, $u, $id, 'Adjustment', $opening, (float)$row['unit_cost'], 'Opening balance', 'adjustment', null);
            $added = ['Part number' => $row['part_number'], 'Description' => $row['description'], 'Category' => $row['category'],
                'Unit' => $row['unit'], 'Unit cost' => $row['unit_cost'], 'Reorder level' => $row['reorder_level']];
            if ($opening > 0) $added['Opening quantity'] = $opening;
            auditWithChanges($p, $u['id'], 'Inventory', 'part ' . $id, 'part added', $row['part_number'] . ($opening ? " (opening $opening)" : ''), [], $added);
            return ['message' => 'Part ' . $row['part_number'] . ' added.'];
        });
    }

    private static function supplierSave(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $id = optId($d, 'id');
        $row = ['name' => strtoupper(field($d, 'name', 150)), 'contact_person' => optField($d, 'contact_person', 120), 'contact_number' => phoneField($d, 'contact_number', 60),
                'email' => optField($d, 'email', 120), 'address' => optField($d, 'address', 255), 'active' => isset($d['active']) ? (!empty($d['active']) ? 1 : 0) : 1];
        if ($row['email']) must((bool)filter_var($row['email'], FILTER_VALIDATE_EMAIL), 'Enter a valid email.');
        return transaction(function (PDO $p) use ($u, $id, $row) {
            must(!one($p, 'SELECT id FROM suppliers WHERE name=? AND id<>?', [$row['name'], $id ?? 0]), 'Supplier ' . $row['name'] . ' already exists.', 409);
            $old = [];
            if ($id) {
                $old = one($p, 'SELECT * FROM suppliers WHERE id=? FOR UPDATE', [$id]);
                must((bool)$old, 'Supplier not found.', 404);
                $cols = implode(',', array_map(fn($c) => "$c=?", array_keys($row)));
                run($p, "UPDATE suppliers SET $cols WHERE id=?", array_merge(array_values($row), [$id]));
            } else $id = insert($p, 'suppliers', $row);
            // Contact numbers and emails are intentionally excluded from permanent audit snapshots.
            $before = []; $after = [];
            foreach (['name', 'contact_person', 'address', 'active'] as $key) {
                $label = ucwords(str_replace('_', ' ', $key));
                $before[$label] = $key === 'active' ? (isset($old[$key]) ? ((int)$old[$key] ? 'Active' : 'Inactive') : null) : ($old[$key] ?? null);
                $after[$label] = $key === 'active' ? ((int)$row[$key] ? 'Active' : 'Inactive') : $row[$key];
            }
            auditWithChanges($p, $u['id'], 'Purchasing', 'supplier ' . $id, 'supplier saved', $row['name'], $before, $after);
            return ['message' => 'Supplier saved.'];
        });
    }

    /**
     * STOCK_COUNT_RECONCILIATION_V1: the former direct adjustment (any +/- quantity with a reason, applied at once by one
     * person) is refused for every role. Physical discrepancies go through count_submit (Purchasing) and count_review (Admin).
     */
    private static function adjust(array $u, array $d): array {
        throw new BusinessRuleException('Direct stock adjustments are disabled. Record a stock count; the Admin reviews it before the stock changes.', 403);
    }

    private static ?bool $countsReady = null;
    /** True when migration 2026-11-12_13 (stock_counts) is applied. */
    public static function countsReady(PDO $p): bool {
        return self::$countsReady ??= (bool)one($p, "SELECT 1 AS ok FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='stock_counts'");
    }
    private static function requireCounts(PDO $p): void {
        must(self::countsReady($p), 'Stock counts are not available yet: database update 2026-11-12_13 (stock count and reconciliation) has not been applied.', 503);
    }

    /** Purchasing Officer records a physical count. The system quantity is read under lock; stock does NOT change yet. */
    private static function countSubmit(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $p = db(); self::requireCounts($p);
        $partId = idField($d, 'part_id');
        $counted = filter_var($d['counted_qty'] ?? null, FILTER_VALIDATE_INT);
        must($counted !== false && $counted >= 0 && $counted <= MAX_QUANTITY, 'Enter the counted quantity (0 or more).');
        $reason = field($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $partId, $counted, $reason) {
            $pt = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [$partId]);
            must((bool)$pt, 'Part not found.', 404);
            must((int)$pt['active'] === 1, 'This part is inactive.');
            $system = (int)$pt['quantity_on_hand'];
            must((int)$counted !== $system, 'The count matches the system quantity (' . $system . '): there is nothing to reconcile.');
            must(!one($p, "SELECT id FROM stock_counts WHERE part_id=? AND status='Submitted'", [$partId]), 'This part already has a count waiting for review.', 409);
            $id = insert($p, 'stock_counts', ['count_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'part_id' => $partId, 'system_qty' => $system, 'counted_qty' => (int)$counted,
                'reason' => $reason, 'status' => 'Submitted', 'counted_by' => $u['id'], 'counted_at' => date('Y-m-d H:i:s')]);
            $ref = refNumber('SC', $id);
            run($p, 'UPDATE stock_counts SET count_ref=? WHERE id=?', [$ref, $id]);
            $files = Files::store($p, $u, 'evidence', 'stock_count', $id, 'Purchase Document');
            $var = (int)$counted - $system;
            auditWithChanges($p, $u['id'], 'Inventory', 'stock_count ' . $id, 'stock count submitted', "$ref " . $pt['part_number'] . ' system ' . $system . ', counted ' . $counted . ' (' . ($var > 0 ? '+' : '') . $var . '): ' . $reason,
                [], ['Count status' => 'Submitted', 'Part' => $pt['part_number'], 'System quantity' => $system, 'Counted quantity' => (int)$counted, 'Discrepancy' => $var, 'Evidence files' => count($files)]);
            Notify::roles($p, ['admin'], 'Stock count', "$ref: " . $pt['part_number'] . ' counted ' . $counted . ' vs system ' . $system . '. Review and post or reject.', 'inventory', 'stock-count-' . $id);
            return ['message' => "Stock count $ref submitted. The stock changes only after the Admin posts it.", 'id' => $id];
        });
    }

    /** Admin posts (one Adjustment movement to the counted quantity) or rejects a submitted count. */
    private static function countReview(array $u, array $d): array {
        requireRole($u, 'admin');
        $p = db(); self::requireCounts($p);
        $id = idField($d, 'count_id');
        $decision = (string)($d['decision'] ?? '');
        must(in_array($decision, ['post', 'reject'], true), 'Choose Post or Reject.');
        $notes = $decision === 'reject' ? field($d, 'notes', 255) : optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $id, $decision, $notes) {
            $c = one($p, 'SELECT * FROM stock_counts WHERE id=? FOR UPDATE', [$id]);
            must((bool)$c, 'Stock count not found.', 404);
            must($c['status'] === 'Submitted', 'This stock count was already ' . strtolower((string)$c['status']) . '.', 409);
            must((int)$c['counted_by'] !== (int)$u['id'], 'You cannot review a count you entered yourself.', 403);
            $pt = one($p, 'SELECT * FROM parts WHERE id=? FOR UPDATE', [(int)$c['part_id']]);
            $now = date('Y-m-d H:i:s');
            if ($decision === 'reject') {
                run($p, "UPDATE stock_counts SET status='Rejected', reviewed_by=?, reviewed_at=?, review_notes=? WHERE id=? AND status='Submitted'", [$u['id'], $now, $notes, $id]);
                auditWithChanges($p, $u['id'], 'Inventory', 'stock_count ' . $id, 'stock count rejected', $c['count_ref'] . ': ' . $notes, ['Count status' => 'Submitted'], ['Count status' => 'Rejected']);
                Notify::resolve($p, 'stock-count-' . $id);
                Notify::user($p, (int)$c['counted_by'], 'Stock count', $c['count_ref'] . ' was rejected: ' . $notes, 'inventory');
                return ['message' => $c['count_ref'] . ' rejected. The stock did not change.'];
            }
            $before = (int)$pt['quantity_on_hand'];
            must($before === (int)$c['system_qty'], 'The stock of ' . $pt['part_number'] . ' changed after the count (system ' . $c['system_qty'] . ' then, ' . $before . ' now). Reject this count and count again.', 409);
            $delta = (int)$c['counted_qty'] - $before;
            self::move($p, $u, (int)$c['part_id'], 'Adjustment', $delta, (float)$pt['unit_cost'], 'Stock count ' . $c['count_ref'] . ': ' . $c['reason'], 'stock_count', $id);
            $movementId = (int)$p->lastInsertId();
            run($p, "UPDATE stock_counts SET status='Posted', reviewed_by=?, reviewed_at=?, review_notes=?, qty_before=?, qty_after=?, stock_movement_id=? WHERE id=? AND status='Submitted'",
                [$u['id'], $now, $notes, $before, (int)$c['counted_qty'], $movementId, $id]);
            auditWithChanges($p, $u['id'], 'Inventory', 'part ' . $c['part_id'], 'stock count posted', $c['count_ref'] . ' ' . $pt['part_number'] . ' ' . ($delta > 0 ? '+' : '') . $delta,
                ['Quantity on hand' => $before, 'Count status' => 'Submitted'], ['Quantity on hand' => (int)$c['counted_qty'], 'Count status' => 'Posted']);
            Notify::resolve($p, 'stock-count-' . $id);
            Notify::user($p, (int)$c['counted_by'], 'Stock count', $c['count_ref'] . ' was posted: ' . $pt['part_number'] . ' is now ' . $c['counted_qty'] . ' on hand.', 'inventory');
            return ['message' => $c['count_ref'] . ' posted. ' . $pt['part_number'] . ' on hand: ' . $before . ' -> ' . $c['counted_qty'] . '.'];
        });
    }

    /** One stock count with its evidence files. Same roles as the Inventory page. */
    private static function countDetails(array $u, array $d): array {
        must(Access::canOpen($u, 'inventory'), 'Not allowed.', 403);
        $p = db(); self::requireCounts($p);
        $c = one($p, self::COUNT_SQL . ' WHERE c.id=?', [idField($d, 'count_id')]);
        must((bool)$c, 'Stock count not found.', 404);
        return ['count' => $c, 'documents' => many($p, "SELECT id, category, original_filename, mime_type, byte_size, created_at FROM documents WHERE entity_type='stock_count' AND entity_id=? ORDER BY id", [(int)$c['id']])];
    }
    private const COUNT_SQL = "SELECT c.*, pt.part_number, pt.description, pt.unit, pt.unit_cost, ROUND(c.variance*pt.unit_cost,2) AS variance_value,
            cu.full_name AS counted_by_name, ru.full_name AS reviewed_by_name
        FROM stock_counts c JOIN parts pt ON pt.id=c.part_id JOIN users cu ON cu.id=c.counted_by LEFT JOIN users ru ON ru.id=c.reviewed_by";

    // Audit #5E: purchasing workflow changes
    // ---------------------------------------------------------------- parts requests (stock-out)
    private static function lines(array $d, string $qtyKey = 'quantity'): array {
        $out = [];
        foreach ((array)($d['lines'] ?? []) as $l) {
            if (!is_array($l) || empty($l['part_id'])) continue;
            $pid = idField($l, 'part_id');
            must(!isset($out[$pid]), 'The same part was entered twice; combine the quantity in one line.');
            $out[$pid] = ['part_id' => $pid, 'quantity' => intField($l, $qtyKey, 1, true, MAX_QUANTITY)] + (isset($l['unit_price']) ? ['unit_price' => moneyField($l, 'unit_price')] : []);
        }
        must((bool)$out, 'Add at least one item.');
        must(count($out) <= 30, 'At most 30 items per form.');
        return array_values($out);
    }

    private static function requestCreate(array $u, array $d): array {
        requireRole($u, 'motorpool');
        $vid = idField($d, 'vehicle_id'); $jobId = optId($d, 'maintenance_job_id');
        $purpose = field($d, 'purpose', 255);
        $lines = self::lines($d);
        return transaction(function (PDO $p) use ($u, $vid, $jobId, $purpose, $lines) {
            $v = one($p, 'SELECT plate_number FROM vehicles WHERE id=? AND active=1', [$vid]);
            must((bool)$v, 'Choose an active truck.');
            if ($jobId) must((bool)one($p, "SELECT id FROM maintenance_jobs WHERE id=? AND vehicle_id=? AND status IN ('Under Inspection','Under Repair')", [$jobId, $vid]), 'The maintenance record does not belong to this truck or is already closed.');
            foreach ($lines as $l) must((bool)one($p, 'SELECT id FROM parts WHERE id=? AND active=1', [$l['part_id']]), 'Unknown part in the list.');
            $rid = insert($p, 'part_requests', ['request_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'vehicle_id' => $vid, 'maintenance_job_id' => $jobId,
                'purpose' => $purpose, 'status' => 'Requested', 'requested_by' => $u['id']]);
            $ref = refNumber('RF', $rid);
            run($p, 'UPDATE part_requests SET request_ref=? WHERE id=?', [$ref, $rid]);
            foreach ($lines as $l) insert($p, 'part_request_lines', ['part_request_id' => $rid, 'part_id' => $l['part_id'], 'quantity' => $l['quantity']]);
            auditWithChanges($p, $u['id'], 'Inventory', 'request ' . $rid, 'parts request submitted', $ref . ' for ' . $v['plate_number'],
                [], ['Request status' => 'Requested', 'Truck' => $v['plate_number'], 'Requested units' => array_sum(array_column($lines, 'quantity'))]);
            Notify::roles($p, ['purchasing'], 'Parts request', "$ref: parts requested for " . $v['plate_number'] . '. Check the stock and release.', 'parts_requests', 'parts-req-' . $rid);
            return ['message' => "Request $ref sent to the Purchasing Officer."];
        });
    }

    /** Purchasing checks the stock and releases what is available (stock-out). */
    private static function requestProcess(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $id = idField($d, 'request_id');
        $notAvailable = !empty($d['not_available']);
        $notes = optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $d, $id, $notAvailable, $notes) {
            $r = one($p, 'SELECT * FROM part_requests WHERE id=? FOR UPDATE', [$id]);
            must((bool)$r, 'Request not found.', 404);
            must(in_array($r['status'], ['Requested', 'Partially Released', 'Not Available'], true), 'This request is ' . $r['status'] . '.', 409);
            $lines = many($p, 'SELECT l.*, pt.part_number, pt.unit_cost AS cost FROM part_request_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.part_request_id=? FOR UPDATE', [$id]);
            $released = 0;
            if (!$notAvailable) {
                $want = [];
                foreach ((array)($d['release'] ?? []) as $x) if (is_array($x) && !empty($x['line_id'])) $want[(int)$x['line_id']] = (int)($x['qty'] ?? 0);
                foreach ($lines as $l) {
                    $q = $want[(int)$l['id']] ?? 0;
                    if ($q <= 0) continue;
                    must($q <= (int)$l['quantity'] - (int)$l['released_qty'], 'Cannot release more than requested for ' . $l['part_number'] . '.');
                    self::move($p, $u, (int)$l['part_id'], 'Stock Out', -$q, (float)$l['cost'], 'Released for ' . $r['request_ref'], 'part_request', $id, (int)$r['vehicle_id'], $r['maintenance_job_id'] ? (int)$r['maintenance_job_id'] : null);
                    run($p, 'UPDATE part_request_lines SET released_qty=released_qty+?, unit_cost=? WHERE id=?', [$q, $l['cost'], $l['id']]);
                    $released += $q;
                }
                must($released > 0, 'Enter the quantity to release, or mark the request as Not Available.');
            }
            $left = (int)one($p, 'SELECT COALESCE(SUM(quantity-released_qty),0) AS n FROM part_request_lines WHERE part_request_id=?', [$id])['n'];
            $any = (int)one($p, 'SELECT COALESCE(SUM(released_qty),0) AS n FROM part_request_lines WHERE part_request_id=?', [$id])['n'];
            $status = $left === 0 ? 'Released' : ($any > 0 ? 'Partially Released' : 'Not Available');
            run($p, 'UPDATE part_requests SET status=?, processed_by=?, processed_at=NOW(), notes=COALESCE(?, notes) WHERE id=?', [$status, $u['id'], $notes, $id]);
            auditWithChanges($p, $u['id'], 'Inventory', 'request ' . $id, 'parts request processed', $r['request_ref'] . ' -> ' . $status,
                ['Request status' => $r['status'], 'Units released in this action' => 0], ['Request status' => $status, 'Units released in this action' => $released]);
            Notify::user($p, (int)$r['requested_by'], 'Parts request', $r['request_ref'] . ' is ' . $status . '.', 'mp_parts_requests');
            Notify::resolve($p, 'parts-req-' . $id);
            return ['message' => 'Request is now ' . $status . '.' . ($status !== 'Released' ? ' Create a purchase order for the missing items if needed.' : '')];
        });
    }

    private static function requestCancel(array $u, array $d): array {
        requireRole($u, 'motorpool');
        $id = idField($d, 'request_id');
        return transaction(function (PDO $p) use ($u, $id) {
            $r = one($p, 'SELECT * FROM part_requests WHERE id=? FOR UPDATE', [$id]);
            must($r && $r['status'] === 'Requested', 'Only a request that is still Requested can be cancelled.', 409);
            run($p, "UPDATE part_requests SET status='Cancelled' WHERE id=?", [$id]);
            Notify::resolve($p, 'parts-req-' . $id);
            auditWithChanges($p, $u['id'], 'Inventory', 'request ' . $id, 'parts request cancelled', $r['request_ref'],
                ['Request status' => $r['status']], ['Request status' => 'Cancelled']);
            return ['message' => 'Request cancelled.'];
        });
    }

    // ---------------------------------------------------------------- purchase orders (stock-in)
    private static function poCreate(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $supplier = self::supplierSnapshot($d);
        $purpose = field($d, 'purpose', 255);
        $reqId = optId($d, 'part_request_id');
        $lines = self::lines($d);
        foreach ($lines as $l) must(isset($l['unit_price']), 'Enter the unit price of every item.');
        $total = round(array_sum(array_map(fn($l) => $l['quantity'] * (float)$l['unit_price'], $lines)), 2);
        $threshold = Settings::value('purchase_approval_threshold');
        $approver = $total <= $threshold ? 'finance' : 'admin';
        return transaction(function (PDO $p) use ($u, $supplier, $purpose, $reqId, $lines, $total, $approver, $threshold) {
            if ($reqId) must((bool)one($p, 'SELECT id FROM part_requests WHERE id=?', [$reqId]), 'Parts request not found.');
            foreach ($lines as $l) must((bool)one($p, 'SELECT id FROM parts WHERE id=? AND active=1', [$l['part_id']]), 'Unknown part in the list.');
            $id = insert($p, 'purchase_orders', ['po_ref' => 'TMP-' . bin2hex(random_bytes(6)), 'part_request_id' => $reqId, 'purpose' => $purpose] + $supplier + [
                'total_amount' => number_format($total, 2, '.', ''), 'approver_role' => $approver, 'status' => 'Pending Approval', 'requested_by' => $u['id']]);
            $ref = refNumber('PO', $id);
            run($p, 'UPDATE purchase_orders SET po_ref=? WHERE id=?', [$ref, $id]);
            foreach ($lines as $l) insert($p, 'purchase_order_lines', ['purchase_order_id' => $id, 'part_id' => $l['part_id'], 'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'], 'line_total' => number_format($l['quantity'] * (float)$l['unit_price'], 2, '.', '')]);
            auditWithChanges($p, $u['id'], 'Purchasing', 'po ' . $id, 'purchase order request created', "$ref " . $supplier['supplier_name'] . ' PHP ' . number_format($total, 2) . ' -> ' . ($approver === 'finance' ? 'Finance' : 'Admin'),
                [], ['PO status' => 'Pending Approval', 'Supplier' => $supplier['supplier_name'], 'Total amount' => number_format($total, 2, '.', ''), 'Approval role' => $approver]);
            Notify::roles($p, [$approver], 'PO approval', "Purchase order $ref (PHP " . number_format($total, 2) . ') is waiting for your approval.', 'purchase_orders', 'po-approve-' . $id);
            return ['message' => "Purchase order $ref (PHP " . number_format($total, 2) . ') sent to ' . ($approver === 'finance' ? 'Finance (PHP ' . number_format($threshold) . ' and below)' : 'the Admin (above PHP ' . number_format($threshold) . ')') . ' for approval.'];
        });
    }

    /**
     * Approve / reject. On approve the budget is checked and reserved in ONE locked
     * step (SELECT ... FOR UPDATE on the budget row), so two approvals at the same
     * time cannot both use the same remaining money.
     */
    private static function poDecide(array $u, array $d): array {
        must(hasRole($u, 'finance', 'admin'), 'Only Finance or the Admin approve purchase orders.', 403);
        $id = idField($d, 'po_id');
        $decision = choiceField($d, 'decision', ['approve', 'reject']);
        $notes = $decision === 'reject' ? field($d, 'notes', 255) : optField($d, 'notes', 255);
        return transaction(function (PDO $p) use ($u, $id, $decision, $notes) {
            $o = one($p, 'SELECT * FROM purchase_orders WHERE id=? FOR UPDATE', [$id]);
            must((bool)$o, 'Purchase order not found.', 404);
            must($o['status'] === 'Pending Approval', 'This purchase order is ' . $o['status'] . '.', 409);
            Notify::resolve($p, 'po-approve-' . $id);
            must($u['role'] === $o['approver_role'], 'This purchase order is routed to ' . ($o['approver_role'] === 'finance' ? 'Finance' : 'the Admin') . ' (approval limit rule).', 403);
            if ($decision === 'reject') {
                run($p, "UPDATE purchase_orders SET status='Rejected', decided_by=?, decided_at=NOW(), decision_notes=? WHERE id=?", [$u['id'], $notes, $id]);
                auditWithChanges($p, $u['id'], 'Purchasing', 'po ' . $id, 'purchase order rejected', $o['po_ref'] . ': ' . $notes,
                    ['PO status' => $o['status']], ['PO status' => 'Rejected']);
                Notify::user($p, (int)$o['requested_by'], 'PO rejected', $o['po_ref'] . ' was rejected: ' . $notes, 'purchase_orders');
                return ['message' => 'Purchase order rejected.'];
            }
            $b = one($p, "SELECT * FROM budgets WHERE status='Active' AND category='Purchasing' AND CURRENT_DATE() BETWEEN period_start AND period_end ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $available = $b ? round((float)$b['amount'] - (float)$b['reserved_amount'] - (float)$b['spent_amount'], 2) : 0.0;
            if (!$b || $available < (float)$o['total_amount']) {
                $why = !$b ? 'no active purchasing budget for today' : 'available budget PHP ' . number_format($available, 2) . ' is less than PHP ' . number_format((float)$o['total_amount'], 2);
                run($p, "UPDATE purchase_orders SET status='Rejected - Insufficient Budget', decided_by=?, decided_at=NOW(), decision_notes=? WHERE id=?", [$u['id'], cut('System: ' . $why, 255), $id]);
                auditWithChanges($p, null, 'Purchasing', 'po ' . $id, 'purchase order rejected - insufficient budget', $o['po_ref'] . ': ' . $why,
                    ['PO status' => $o['status']], ['PO status' => 'Rejected - Insufficient Budget']);
                Notify::user($p, (int)$o['requested_by'], 'PO rejected', $o['po_ref'] . ' was automatically rejected: insufficient budget (' . $why . ').', 'purchase_orders');
                return ['warning' => 'Rejected - Insufficient Budget: ' . $why . '. No PO document was made.'];
            }
            run($p, 'UPDATE budgets SET reserved_amount=reserved_amount+? WHERE id=?', [$o['total_amount'], $b['id']]);
            run($p, "UPDATE purchase_orders SET status='Approved', payment_status='Pending Payment', budget_id=?, reserved_amount=total_amount, decided_by=?, decided_at=NOW(), decision_notes=? WHERE id=?",
                [$b['id'], $u['id'], $notes, $id]);
            auditWithChanges($p, $u['id'], 'Purchasing', 'po ' . $id, 'purchase order approved', $o['po_ref'] . ': PHP ' . $o['total_amount'] . ' reserved from the budget',
                ['PO status' => $o['status'], 'Reserved amount' => $o['reserved_amount']], ['PO status' => 'Approved', 'Reserved amount' => $o['total_amount']]);
            Notify::user($p, (int)$o['requested_by'], 'PO approved', $o['po_ref'] . ' is approved. Print the PO and proceed with the purchase.', 'purchase_orders');
            Notify::roles($p, ['finance'], 'PO pending payment', $o['po_ref'] . ' (PHP ' . number_format((float)$o['total_amount'], 2) . ') was approved and is Pending Payment.', 'finance', 'po-pay-' . $id);
            return ['message' => 'Approved. PHP ' . number_format((float)$o['total_amount'], 2) . ' is reserved from the purchasing budget. The PO document is ready to print.'];
        });
    }

    /** Receiving (stock-in). Partial deliveries are allowed: "Partially Received". */
    private static function poReceive(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $id = idField($d, 'po_id');
        $invoice = optField($d, 'supplier_invoice_no', 80);
        return transaction(function (PDO $p) use ($u, $d, $id, $invoice) {
            $o = one($p, 'SELECT * FROM purchase_orders WHERE id=? FOR UPDATE', [$id]);
            must((bool)$o, 'Purchase order not found.', 404);
            must(in_array($o['status'], ['Approved', 'Partially Received'], true), 'Only approved purchase orders can be received (this one is ' . $o['status'] . ').', 409);
            $got = [];
            foreach ((array)($d['receive'] ?? []) as $x) if (is_array($x) && !empty($x['line_id'])) $got[(int)$x['line_id']] = (int)($x['qty'] ?? 0);
            $total = 0;
            foreach (many($p, 'SELECT l.*, pt.part_number FROM purchase_order_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.purchase_order_id=? FOR UPDATE', [$id]) as $l) {
                $q = $got[(int)$l['id']] ?? 0;
                if ($q <= 0) continue;
                must($q <= (int)$l['quantity'] - (int)$l['received_qty'], 'Received quantity of ' . $l['part_number'] . ' is more than the remaining ordered quantity.');
                self::move($p, $u, (int)$l['part_id'], 'Stock In', $q, (float)$l['unit_price'], 'Received from ' . $o['po_ref'], 'purchase_order', $id);
                run($p, 'UPDATE parts SET unit_cost=? WHERE id=?', [$l['unit_price'], $l['part_id']]);
                run($p, 'UPDATE purchase_order_lines SET received_qty=received_qty+? WHERE id=?', [$q, $l['id']]);
                $total += $q;
            }
            must($total > 0, 'Enter the quantities actually received.');
            Files::store($p, $u, 'receipt', 'purchase', $id, 'Delivery Receipt / Supplier Invoice');
            $left = (int)one($p, 'SELECT COALESCE(SUM(quantity-received_qty),0) AS n FROM purchase_order_lines WHERE purchase_order_id=?', [$id])['n'];
            $status = $left === 0 ? 'Received' : 'Partially Received';
            run($p, 'UPDATE purchase_orders SET status=?, supplier_invoice_no=COALESCE(?, supplier_invoice_no) WHERE id=?', [$status, $invoice, $id]);
            self::refreshPayment($p, $id);
            auditWithChanges($p, $u['id'], 'Purchasing', 'po ' . $id, 'parts received', $o['po_ref'] . ': ' . $total . ' unit(s), ' . $status,
                ['PO status' => $o['status'], 'Units received in this action' => 0], ['PO status' => $status, 'Units received in this action' => $total]);
            Notify::roles($p, ['finance'], 'Parts received', $o['po_ref'] . ' is ' . $status . '. Check the supplier invoice before payment.', 'finance');
            return ['message' => "Received $total unit(s). PO is $status; inventory updated."];
        });
    }

    /** Close a PO whose balance will not be delivered: frees the reserved budget of the undelivered items. */
    private static function poClose(array $u, array $d): array {
        requireRole($u, 'purchasing');
        $id = idField($d, 'po_id'); $reason = field($d, 'reason', 255);
        return transaction(function (PDO $p) use ($u, $id, $reason) {
            $o = one($p, 'SELECT * FROM purchase_orders WHERE id=? FOR UPDATE', [$id]);
            must($o && in_array($o['status'], ['Approved', 'Partially Received'], true), 'Only an approved, not fully received PO can be closed.', 409);
            $undelivered = (float)one($p, 'SELECT COALESCE(SUM((quantity-received_qty)*unit_price),0) AS v FROM purchase_order_lines WHERE purchase_order_id=?', [$id])['v'];
            $free = round(min($undelivered, (float)$o['reserved_amount']), 2);
            if ($o['budget_id'] && $free > 0) run($p, 'UPDATE budgets SET reserved_amount=GREATEST(reserved_amount-?,0) WHERE id=?', [$free, $o['budget_id']]);
            run($p, "UPDATE purchase_orders SET status='Closed', reserved_amount=GREATEST(reserved_amount-?,0), decision_notes=? WHERE id=?", [$free, cut('Closed: ' . $reason, 255), $id]);
            self::refreshPayment($p, $id);
            auditWithChanges($p, $u['id'], 'Purchasing', 'po ' . $id, 'purchase order closed', $o['po_ref'] . ': PHP ' . number_format($free, 2) . ' returned to the budget. ' . $reason,
                ['PO status' => $o['status'], 'Reserved amount' => $o['reserved_amount']], ['PO status' => 'Closed', 'Reserved amount' => max(0, (float)$o['reserved_amount'] - $free)]);
            return ['message' => 'PO closed. PHP ' . number_format($free, 2) . ' of reserved budget was released.'];
        });
    }

    /** Payment status follows the value of what was received (and what was paid). */
    public static function refreshPayment(PDO $p, int $id): void {
        $o = one($p, 'SELECT * FROM purchase_orders WHERE id=?', [$id]);
        if (!$o['payment_status']) return;
        $received = (float)one($p, 'SELECT COALESCE(SUM(received_qty*unit_price),0) AS v FROM purchase_order_lines WHERE purchase_order_id=?', [$id])['v'];
        $paid = (float)$o['paid_amount'];
        $final = in_array($o['status'], ['Received', 'Closed'], true);
        // ACCOUNTING_INTEGRITY_G5: a PO closed before anything was received (and never paid) owes nothing. It leaves the
        // Finance payables (payment_status NULL, allowed by chk_po_pay) and its "PO pending payment" notification is resolved.
        if ($final && round($received, 2) <= 0 && $paid <= 0) {
            run($p, 'UPDATE purchase_orders SET payment_status=NULL WHERE id=?', [$id]);
            Notify::resolve($p, 'po-pay-' . $id);
            return;
        }
        $status = ($paid <= 0) ? 'Pending Payment' : (($final && $paid >= round($received, 2)) ? 'Paid' : 'Partially Paid');
        if ($status === 'Paid') Notify::resolve($p, 'po-pay-' . $id);
        run($p, 'UPDATE purchase_orders SET payment_status=? WHERE id=?', [$status, $id]);
    }

    private static function poDetails(array $u, array $d): array {
        must(Access::canOpen($u, 'purchase_orders'), 'Not allowed.', 403);
        $id = idField($d, 'po_id'); $p = db();
        $o = one($p, 'SELECT o.*, COALESCE(o.supplier_name, s.name) AS supplier, COALESCE(o.supplier_address, s.address) AS supplier_address,
                COALESCE(o.supplier_contact, s.contact_person) AS supplier_contact, COALESCE(o.supplier_phone, s.contact_number) AS supplier_phone,
                COALESCE(o.supplier_email, s.email) AS supplier_email,
                ru.full_name AS requested_by_name, du.full_name AS decided_by_name, du.signature_document_id AS approver_signature, r.request_ref
            FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id JOIN users ru ON ru.id=o.requested_by LEFT JOIN users du ON du.id=o.decided_by
            LEFT JOIN part_requests r ON r.id=o.part_request_id WHERE o.id=?', [$id]);
        must((bool)$o, 'Purchase order not found.', 404);
        return ['po' => $o,
            'lines' => many($p, 'SELECT l.*, pt.part_number, pt.description, pt.unit FROM purchase_order_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.purchase_order_id=? ORDER BY l.id', [$id]),
            // S4B-2D-B: detailed disbursement records contain Finance-only payment data.
            // Purchasing/Motorpool still receive PO payment_status, but not bank/payment details.
            'payments' => hasRole($u, 'finance', 'admin', 'audit')
                ? many($p, "SELECT d.*, u.full_name AS recorded_by_name FROM disbursements d JOIN users u ON u.id=d.recorded_by WHERE d.source_type='purchase_order' AND d.source_id=? ORDER BY d.id", [$id]) : [],
            'documents' => Files::of($p, 'purchase', $id),
            // STAGE_B_PURCHASING_UI: the PO's recorded activity (created, approved/rejected, received, paid, closed) from the audit log.
            'history' => self::activity($p, 'po ' . $id, ['Purchasing', 'Finance'])];
    }

    /** Recorded audit events of one record (newest first): action, details, actor name, time. No change values. */
    private static function activity(PDO $p, string $ref, array $areas): array {
        $in = implode(',', array_fill(0, count($areas), '?'));
        return many($p, "SELECT e.action, e.details, e.created_at, COALESCE(u.full_name, 'System') AS actor FROM audit_events e LEFT JOIN users u ON u.id=e.actor_id
            WHERE e.record_ref=? AND e.area IN ($in) ORDER BY e.id DESC LIMIT 50", array_merge([$ref], $areas));
    }

    /** STAGE_B_PURCHASING_UI — last 50 stock movements of one part (Inventory drawer). Same roles as the Inventory page. */
    private static function partHistory(array $u, array $d): array {
        must(Access::canOpen($u, 'inventory'), 'Not allowed.', 403);
        $id = idField($d, 'part_id'); $p = db();
        $pt = one($p, 'SELECT pt.*, ROUND(pt.quantity_on_hand*pt.unit_cost,2) AS stock_value FROM parts pt WHERE pt.id=?', [$id]);
        must((bool)$pt, 'Part not found.', 404);
        return ['part' => $pt,
            'movements' => many($p, "SELECT sm.id, sm.movement_type, sm.quantity_delta, sm.unit_cost, sm.reason, sm.reference_type, sm.reference_id, sm.created_at,
                    v.plate_number, u.full_name AS actor,
                    CASE sm.reference_type WHEN 'purchase_order' THEN (SELECT po_ref FROM purchase_orders WHERE id=sm.reference_id)
                        WHEN 'part_request' THEN (SELECT request_ref FROM part_requests WHERE id=sm.reference_id)
                        WHEN 'stock_count' THEN sm.reason END AS reference
                FROM stock_movements sm LEFT JOIN vehicles v ON v.id=sm.vehicle_id JOIN users u ON u.id=sm.actor_id WHERE sm.part_id=? ORDER BY sm.id DESC LIMIT 50", [$id]),
            'usage' => one($p, "SELECT COALESCE(SUM(CASE WHEN movement_type='Stock Out' THEN -quantity_delta END),0) AS issued_90d,
                    COUNT(CASE WHEN movement_type='Stock Out' THEN 1 END) AS issues_90d
                FROM stock_movements WHERE part_id=? AND created_at >= NOW() - INTERVAL 90 DAY", [$id])];
    }

    /**
     * One parts request with its lines.
     *  - Purchasing / Admin / Audit (Parts Requests page): fulfilment view incl. quantity on hand.
     *  - Motorpool: only requests made by Motorpool, and only an availability label (no stock counts, no costs).
     */
    private static function requestDetails(array $u, array $d): array {
        $motorpool = hasRole($u, 'motorpool');
        must($motorpool || Access::canOpen($u, 'parts_requests'), 'Not allowed.', 403);
        $id = idField($d, 'request_id'); $p = db();
        $r = one($p, 'SELECT r.id, r.request_ref, r.status, r.purpose, r.notes, r.created_at, r.processed_at, r.requested_by, r.processed_by,
                v.plate_number, v.truck_number, v.make_brand, v.engine_number, m.job_ref, ru.full_name AS requested_by_name, pu.full_name AS processed_by_name
            FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id JOIN users ru ON ru.id=r.requested_by
            LEFT JOIN users pu ON pu.id=r.processed_by WHERE r.id=?' . ($motorpool ? " AND ru.role='motorpool'" : ''), [$id]);
        must((bool)$r, 'Request not found.', 404);
        $cols = $motorpool ? self::availabilitySql('pt') . ' AS availability' : 'pt.quantity_on_hand';
        $out = ['request' => $r, 'lines' => many($p, "SELECT l.id, l.part_id, l.quantity, l.released_qty, pt.part_number, pt.description, pt.unit, $cols
            FROM part_request_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.part_request_id=? ORDER BY l.id", [$id])];
        // STAGE_B_PURCHASING_UI: Purchasing / Admin / Audit also get the linked POs and the request's recorded activity.
        // Motorpool's response is unchanged (no PO, supplier or cost data).
        if (!$motorpool) {
            $out['orders'] = many($p, "SELECT o.id, o.po_ref, o.status, o.payment_status, o.total_amount, o.created_at, COALESCE(o.supplier_name, s.name) AS supplier
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.part_request_id=? ORDER BY o.id", [$id]);
            $out['history'] = self::activity($p, 'request ' . $id, ['Inventory']);
        }
        return $out;
    }

    /** In stock / Low stock / Out of stock, so Motorpool can see availability without stock counts or costs. */
    private static function availabilitySql(string $alias): string {
        return "CASE WHEN $alias.quantity_on_hand<=0 THEN 'Out of stock' WHEN $alias.reorder_level>0 AND $alias.quantity_on_hand<=$alias.reorder_level THEN 'Low stock' ELSE 'In stock' END";
    }

    /** Parts Motorpool may pick in the request form: identification and availability only. */
    private static function requestCatalog(array $u): array {
        requireRole($u, 'motorpool');
        return ['parts' => many(db(), 'SELECT pt.id, pt.part_number, pt.description, pt.unit, ' . self::availabilitySql('pt') . ' AS availability
            FROM parts pt WHERE pt.active=1 ORDER BY pt.part_number')];
    }

    /**
     * MOTORPOOL_WORKSPACE_PHASE1 — page data of Motorpool's Parts Requests page.
     *   requests  the department's requests (same rows as my_requests)
     *   summary   counts per status over ALL of the department's requests (not cut by the list limit)
     *   trucks / jobs  what the existing "Request parts" form needs (truck identity, open repairs)
     * Never: unit cost, quantity on hand, stock value, budget, supplier or purchase-order data.
     */
    private static function motorpoolPage(array $u, PDO $p): array {
        requireRole($u, 'motorpool');
        $data = readSections([
            'requests' => fn() => self::myRequestRows($p),
            'summary' => fn() => array_map('intval', one($p, "SELECT COALESCE(SUM(r.status='Requested'),0) AS requested,
                    COALESCE(SUM(r.status='Partially Released'),0) AS partially_released, COALESCE(SUM(r.status='Not Available'),0) AS not_available,
                    COALESCE(SUM(r.status='Released'),0) AS released, COALESCE(SUM(r.status='Cancelled'),0) AS cancelled
                FROM part_requests r JOIN users ru ON ru.id=r.requested_by WHERE ru.role='motorpool'")),
            'trucks' => fn() => many($p, 'SELECT id, plate_number, truck_number FROM vehicles WHERE active=1 ORDER BY plate_number'),
            'jobs' => fn() => many($p, "SELECT m.id, m.job_ref, m.vehicle_id, m.status, m.problem, v.plate_number FROM maintenance_jobs m JOIN vehicles v ON v.id=m.vehicle_id
                WHERE m.status IN ('Under Inspection','Under Repair') ORDER BY m.id DESC"),
        ]);
        $data['can'] = ['act' => true];
        return $data;
    }

    /** Motorpool's own parts requests with fulfilment progress (requested vs released units). No costs, no stock counts. */
    private static function myRequests(array $u): array {
        requireRole($u, 'motorpool');
        return ['requests' => self::myRequestRows(db())];
    }

    private static function myRequestRows(PDO $p): array {
        return many($p, "SELECT r.id, r.request_ref, r.status, r.purpose, r.notes, r.created_at, r.processed_at, r.vehicle_id, v.plate_number, m.job_ref, ru.full_name AS requested_by_name,
                (SELECT COALESCE(SUM(l.quantity),0) FROM part_request_lines l WHERE l.part_request_id=r.id) AS requested_units,
                (SELECT COALESCE(SUM(l.released_qty),0) FROM part_request_lines l WHERE l.part_request_id=r.id) AS released_units,
                (SELECT GROUP_CONCAT(CONCAT(pt.part_number, ' x', l.quantity, IF(l.released_qty>0, CONCAT(' (released ', l.released_qty, ')'), '')) SEPARATOR '; ')
                   FROM part_request_lines l JOIN parts pt ON pt.id=l.part_id WHERE l.part_request_id=r.id) AS items
            FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id LEFT JOIN maintenance_jobs m ON m.id=r.maintenance_job_id JOIN users ru ON ru.id=r.requested_by
            WHERE ru.role='motorpool' ORDER BY FIELD(r.status,'Requested','Partially Released','Not Available','Released','Cancelled'), r.id DESC LIMIT 300");
    }

    // =================================================================
    // PURCHASING_ANALYTICS_V1 — Purchasing Reports (page purchasing_reports; Admin, Purchasing, Finance, Audit)
    // Descriptive, diagnostic and rule-based decision support computed with SQL from the recorded transactions.
    // Nothing here writes: recommendations only link to the authorized record or page where a person acts.
    // Forecast: a moving average is shown ONLY when enough complete months of stock-out history exist, together with
    // its measured back-test error; otherwise the result is "Insufficient historical data".
    // =================================================================
    public const ANALYTICS_RULES = [
        'usage_window_days' => 90,  // recent-use window for the cover calculation
        'forecast_min_months' => 6, // complete months of stock-out history needed before any moving average is shown
        'forecast_window' => 3,     // moving-average window (months)
    ];

    public static function analytics(array $u, array $q): array {
        must(Access::canOpen($u, 'purchasing_reports'), 'Not allowed.', 403);
        $p = db();
        $date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $from = $date($q['from'] ?? '') ?? date('Y-m-01', strtotime('-2 months'));
        $to = $date($q['to'] ?? '') ?? date('Y-m-d');
        if ($to < $from) [$from, $to] = [$to, $from];
        if ((strtotime($to) - strtotime($from)) / 86400 > 731) $from = date('Y-m-d', strtotime($to . ' -731 days'));
        $R = self::ANALYTICS_RULES;
        $per = [$from, $to];
        $open = "('Approved','Partially Received')";

        $data = readSections([
            // ---------------- descriptive
            'stockNow' => fn() => one($p, "SELECT COUNT(*) AS parts, COALESCE(SUM(quantity_on_hand),0) AS units, COALESCE(SUM(quantity_on_hand*unit_cost),0) AS value,
                    SUM(reorder_level>0 AND quantity_on_hand<=reorder_level) AS low, SUM(quantity_on_hand=0) AS out_of_stock FROM parts WHERE active=1"),
            'movements' => fn() => many($p, "SELECT movement_type, COUNT(*) AS n, COALESCE(SUM(ABS(quantity_delta)),0) AS units, COALESCE(SUM(quantity_delta),0) AS net,
                    COALESCE(SUM(ABS(quantity_delta)*unit_cost),0) AS value FROM stock_movements WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY movement_type", $per),
            'requests' => fn() => one($p, "SELECT COUNT(DISTINCT r.id) AS n, COALESCE(SUM(l.quantity),0) AS requested, COALESCE(SUM(l.released_qty),0) AS released,
                    COUNT(DISTINCT CASE WHEN r.status='Released' THEN r.id END) AS fully_released, COUNT(DISTINCT CASE WHEN r.status IN ('Not Available','Partially Released') THEN r.id END) AS short,
                    COUNT(DISTINCT CASE WHEN r.status='Cancelled' THEN r.id END) AS cancelled
                FROM part_requests r JOIN part_request_lines l ON l.part_request_id=r.id WHERE DATE(r.created_at) BETWEEN ? AND ?", $per),
            'poStatus' => fn() => many($p, "SELECT status, COUNT(*) AS n, COALESCE(SUM(total_amount),0) AS total FROM purchase_orders WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY status ORDER BY FIELD(status,'Pending Approval','Approved','Partially Received','Received','Closed','Rejected','Rejected - Insufficient Budget')", $per),
            'spend' => fn() => one($p, "SELECT COALESCE(SUM(CASE WHEN status IN ('Approved','Partially Received','Received','Closed') THEN total_amount END),0) AS approved,
                    COUNT(CASE WHEN status IN ('Approved','Partially Received','Received','Closed') THEN 1 END) AS approved_n,
                    COALESCE(SUM(paid_amount),0) AS paid FROM purchase_orders WHERE decided_at IS NOT NULL AND DATE(decided_at) BETWEEN ? AND ?", $per),
            'received' => fn() => (float)one($p, "SELECT COALESCE(SUM(quantity_delta*unit_cost),0) AS v FROM stock_movements WHERE movement_type='Stock In' AND DATE(created_at) BETWEEN ? AND ?", $per)['v'],
            'bySupplier' => fn() => many($p, "SELECT COALESCE(o.supplier_name, s.name) AS supplier, COUNT(*) AS n, SUM(o.total_amount) AS total, SUM(o.paid_amount) AS paid
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id
                WHERE o.status IN ('Approved','Partially Received','Received','Closed') AND DATE(o.decided_at) BETWEEN ? AND ? GROUP BY 1 ORDER BY total DESC LIMIT 15", $per),
            'monthly' => fn() => many($p, "SELECT m.ym, COALESCE(po.approved,0) AS approved, COALESCE(mv.issued,0) AS issued, COALESCE(mv.received,0) AS received FROM
                  (SELECT DATE_FORMAT(decided_at,'%Y-%m') AS ym FROM purchase_orders WHERE decided_at IS NOT NULL AND DATE(decided_at) BETWEEN ? AND ?
                   UNION SELECT DATE_FORMAT(created_at,'%Y-%m') FROM stock_movements WHERE DATE(created_at) BETWEEN ? AND ?) m
                LEFT JOIN (SELECT DATE_FORMAT(decided_at,'%Y-%m') AS ym, SUM(total_amount) AS approved FROM purchase_orders
                    WHERE status IN ('Approved','Partially Received','Received','Closed') AND DATE(decided_at) BETWEEN ? AND ? GROUP BY 1) po ON po.ym=m.ym
                LEFT JOIN (SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, SUM(CASE WHEN movement_type='Stock Out' THEN -quantity_delta*unit_cost END) AS issued,
                    SUM(CASE WHEN movement_type='Stock In' THEN quantity_delta*unit_cost END) AS received FROM stock_movements WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY 1) mv ON mv.ym=m.ym
                ORDER BY m.ym", [$from, $to, $from, $to, $from, $to, $from, $to]),
            // ---------------- diagnostic
            'topUsed' => fn() => many($p, "SELECT pt.id, pt.part_number, pt.description, pt.unit, pt.quantity_on_hand, SUM(-sm.quantity_delta) AS units, SUM(-sm.quantity_delta*sm.unit_cost) AS value,
                    COUNT(*) AS releases, COUNT(DISTINCT sm.vehicle_id) AS trucks
                FROM stock_movements sm JOIN parts pt ON pt.id=sm.part_id WHERE sm.movement_type='Stock Out' AND DATE(sm.created_at) BETWEEN ? AND ?
                GROUP BY pt.id ORDER BY units DESC, value DESC LIMIT 15", $per),
            'shortages' => fn() => many($p, "SELECT pt.id, pt.part_number, pt.description, pt.unit, COUNT(DISTINCT r.id) AS requests, SUM(l.quantity-l.released_qty) AS short_units,
                    MAX(r.created_at) AS last_at FROM part_request_lines l JOIN part_requests r ON r.id=l.part_request_id JOIN parts pt ON pt.id=l.part_id
                WHERE l.released_qty < l.quantity AND r.status IN ('Not Available','Partially Released','Released') AND DATE(r.created_at) BETWEEN ? AND ?
                GROUP BY pt.id HAVING requests >= 1 ORDER BY requests DESC, short_units DESC LIMIT 15", $per),
            'outstanding' => fn() => many($p, "SELECT pt.id, pt.part_number, pt.description, pt.unit, pt.quantity_on_hand, SUM(l.quantity-l.released_qty) AS outstanding, COUNT(DISTINCT r.id) AS requests,
                    MIN(r.id) AS first_request_id, MIN(r.created_at) AS oldest
                FROM part_request_lines l JOIN part_requests r ON r.id=l.part_request_id JOIN parts pt ON pt.id=l.part_id
                WHERE r.status IN ('Requested','Partially Released','Not Available') AND l.released_qty < l.quantity GROUP BY pt.id ORDER BY outstanding DESC"),
            'unreceived' => fn() => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.status, o.decided_at, DATEDIFF(CURRENT_DATE(), DATE(o.decided_at)) AS days,
                    SUM(l.quantity-l.received_qty) AS units, SUM((l.quantity-l.received_qty)*l.unit_price) AS value
                FROM purchase_orders o JOIN purchase_order_lines l ON l.purchase_order_id=o.id LEFT JOIN suppliers s ON s.id=o.supplier_id
                WHERE o.status IN $open AND l.received_qty < l.quantity GROUP BY o.id ORDER BY o.decided_at"),
            'adjustments' => fn() => many($p, "SELECT pt.part_number, pt.description, COUNT(*) AS n, SUM(sm.quantity_delta) AS net, SUM(sm.quantity_delta*sm.unit_cost) AS value
                FROM stock_movements sm JOIN parts pt ON pt.id=sm.part_id WHERE sm.movement_type='Adjustment' AND DATE(sm.created_at) BETWEEN ? AND ? GROUP BY pt.id ORDER BY ABS(SUM(sm.quantity_delta*sm.unit_cost)) DESC LIMIT 15", $per),
            // ---------------- prescriptive (current state, not limited to the period)
            // UI_REFERENCE_V1: "Pending orders" KPI = purchase orders open now (awaiting approval or delivery).
            'openOrders' => fn() => array_map('intval', one($p, "SELECT COALESCE(SUM(status='Pending Approval'),0) AS pending, COALESCE(SUM(status IN $open),0) AS receiving FROM purchase_orders")),
            'reorder' => fn() => many($p, "SELECT pt.id, pt.part_number, pt.description, pt.unit, pt.unit_cost, pt.quantity_on_hand, pt.reorder_level,
                    COALESCE((SELECT SUM(l.quantity-l.released_qty) FROM part_request_lines l JOIN part_requests r ON r.id=l.part_request_id
                        WHERE l.part_id=pt.id AND r.status IN ('Requested','Partially Released','Not Available')),0) AS outstanding,
                    COALESCE((SELECT SUM(ol.quantity-ol.received_qty) FROM purchase_order_lines ol JOIN purchase_orders o ON o.id=ol.purchase_order_id
                        WHERE ol.part_id=pt.id AND o.status IN ('Pending Approval','Approved','Partially Received')),0) AS on_order,
                    COALESCE((SELECT SUM(ol.quantity-ol.received_qty) FROM purchase_order_lines ol JOIN purchase_orders o ON o.id=ol.purchase_order_id
                        WHERE ol.part_id=pt.id AND o.status IN $open),0) AS on_order_approved,
                    COALESCE((SELECT SUM(ol.quantity-ol.received_qty) FROM purchase_order_lines ol JOIN purchase_orders o ON o.id=ol.purchase_order_id
                        WHERE ol.part_id=pt.id AND o.status='Pending Approval'),0) AS on_order_pending,
                    (SELECT MIN(o.id) FROM purchase_order_lines ol JOIN purchase_orders o ON o.id=ol.purchase_order_id
                        WHERE ol.part_id=pt.id AND o.status IN $open AND ol.received_qty < ol.quantity) AS open_po_id,
                    (SELECT MIN(o.id) FROM purchase_order_lines ol JOIN purchase_orders o ON o.id=ol.purchase_order_id
                        WHERE ol.part_id=pt.id AND o.status='Pending Approval') AS pending_po_id,
                    COALESCE((SELECT SUM(-sm.quantity_delta) FROM stock_movements sm WHERE sm.part_id=pt.id AND sm.movement_type='Stock Out' AND sm.created_at >= NOW() - INTERVAL {$R['usage_window_days']} DAY),0) AS used_recent
                FROM parts pt WHERE pt.active=1 ORDER BY pt.part_number"),
            'followUp' => fn() => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.status, o.decided_at, DATEDIFF(CURRENT_DATE(), DATE(o.decided_at)) AS days
                FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id WHERE o.status IN $open ORDER BY o.decided_at"),   // no invented delivery deadline: POs record no expected delivery date
            'shortRequests' => fn() => many($p, "SELECT r.id, r.request_ref, r.status, r.created_at, v.plate_number,
                    GROUP_CONCAT(CONCAT(pt.part_number, ' short ', l.quantity-l.released_qty, ' (on hand ', pt.quantity_on_hand, ')') SEPARATOR '; ') AS lines_short,
                    (SELECT COUNT(*) FROM purchase_orders o WHERE o.part_request_id=r.id AND o.status IN ('Pending Approval','Approved','Partially Received')) AS open_pos
                FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id JOIN part_request_lines l ON l.part_request_id=r.id JOIN parts pt ON pt.id=l.part_id
                WHERE r.status IN ('Not Available','Partially Released') AND l.quantity-l.released_qty > pt.quantity_on_hand GROUP BY r.id ORDER BY r.created_at"),
            'ledger' => fn() => many($p, "SELECT pt.id, pt.part_number, pt.quantity_on_hand, COALESCE(SUM(sm.quantity_delta),0) AS ledger FROM parts pt
                LEFT JOIN stock_movements sm ON sm.part_id=pt.id GROUP BY pt.id HAVING ledger <> pt.quantity_on_hand"),
            'countsPending' => fn() => self::countsReady($p) ? many($p, "SELECT c.id, c.count_ref, c.counted_at, c.system_qty, c.counted_qty, c.variance, pt.part_number, ROUND(c.variance*pt.unit_cost,2) AS value
                FROM stock_counts c JOIN parts pt ON pt.id=c.part_id WHERE c.status='Submitted' ORDER BY c.counted_at") : [],
            // ---------------- PURCHASING_REPORTS_V2: chart transactions, usage per part, summary breakdowns
            'flowMoves' => fn() => many($p, "SELECT sm.id, sm.created_at, sm.movement_type, ABS(sm.quantity_delta) AS qty, sm.unit_cost, ROUND(ABS(sm.quantity_delta)*sm.unit_cost,2) AS value,
                    sm.reference_type, sm.reference_id, pt.part_number, pt.unit, v.plate_number,
                    CASE sm.reference_type WHEN 'purchase_order' THEN (SELECT o.po_ref FROM purchase_orders o WHERE o.id=sm.reference_id)
                        WHEN 'part_request' THEN (SELECT r.request_ref FROM part_requests r WHERE r.id=sm.reference_id) END AS ref,
                    CASE WHEN sm.reference_type='purchase_order' THEN (SELECT o.status FROM purchase_orders o WHERE o.id=sm.reference_id) END AS po_status
                FROM stock_movements sm JOIN parts pt ON pt.id=sm.part_id LEFT JOIN vehicles v ON v.id=sm.vehicle_id
                WHERE sm.movement_type IN ('Stock In','Stock Out') AND DATE(sm.created_at) BETWEEN ? AND ? ORDER BY sm.created_at, sm.id LIMIT 3000", $per),
            'usage' => fn() => many($p, "SELECT sm.part_id, COUNT(*) AS releases, SUM(-sm.quantity_delta) AS units FROM stock_movements sm
                WHERE sm.movement_type='Stock Out' AND DATE(sm.created_at) BETWEEN ? AND ? GROUP BY sm.part_id", $per),
            'requestsByDay' => fn() => many($p, "SELECT DATE(created_at) AS d, status FROM part_requests WHERE DATE(created_at) BETWEEN ? AND ?", $per),
            'detail' => fn() => [
                'approved' => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.status, o.decided_at, o.total_amount, o.paid_amount
                    FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id
                    WHERE o.status IN ('Approved','Partially Received','Received','Closed') AND o.decided_at IS NOT NULL AND DATE(o.decided_at) BETWEEN ? AND ? ORDER BY o.decided_at, o.id", $per),
                'paidPos' => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.status, o.paid_amount, o.payment_status
                    FROM purchase_orders o LEFT JOIN suppliers s ON s.id=o.supplier_id
                    WHERE o.decided_at IS NOT NULL AND DATE(o.decided_at) BETWEEN ? AND ? AND o.paid_amount > 0 ORDER BY o.decided_at, o.id", $per),
                // Payment vouchers follow po_details: Finance, Admin and Audit only.
                'payments' => hasRole($u, 'finance', 'admin', 'audit') ? many($p, "SELECT d.voucher_number, d.released_on, d.payment_method, d.amount, o.po_ref, o.id AS po_id
                    FROM disbursements d JOIN purchase_orders o ON o.id=d.source_id
                    WHERE d.source_type='purchase_order' AND o.decided_at IS NOT NULL AND DATE(o.decided_at) BETWEEN ? AND ? ORDER BY d.released_on, d.id", $per) : null,
                'outstanding' => many($p, "SELECT o.id, o.po_ref, COALESCE(o.supplier_name, s.name) AS supplier, o.status, pt.part_number, pt.unit,
                        l.quantity, l.received_qty, l.quantity-l.received_qty AS outstanding, l.unit_price, ROUND((l.quantity-l.received_qty)*l.unit_price,2) AS value
                    FROM purchase_orders o JOIN purchase_order_lines l ON l.purchase_order_id=o.id JOIN parts pt ON pt.id=l.part_id LEFT JOIN suppliers s ON s.id=o.supplier_id
                    WHERE o.status IN $open AND l.received_qty < l.quantity ORDER BY o.decided_at, o.id, l.id"),
                'shortLines' => many($p, "SELECT r.id, r.request_ref, r.status, v.plate_number, pt.part_number, pt.unit, l.quantity-l.released_qty AS required, pt.quantity_on_hand AS available,
                        (l.quantity-l.released_qty) - pt.quantity_on_hand AS shortage,
                        (SELECT GROUP_CONCAT(o.po_ref SEPARATOR ', ') FROM purchase_orders o WHERE o.part_request_id=r.id AND o.status IN ('Pending Approval','Approved','Partially Received')) AS linked_po
                    FROM part_requests r JOIN vehicles v ON v.id=r.vehicle_id JOIN part_request_lines l ON l.part_request_id=r.id JOIN parts pt ON pt.id=l.part_id
                    WHERE r.status IN ('Not Available','Partially Released') AND l.quantity-l.released_qty > pt.quantity_on_hand ORDER BY r.created_at, l.id"),
            ],
            // ---------------- predictive (only with enough real history)
            'history' => fn() => many($p, "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, SUM(-quantity_delta) AS units FROM stock_movements
                WHERE movement_type='Stock Out' AND created_at < DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01') GROUP BY 1 ORDER BY 1"),
        ]);

        // Decision rules, evaluated in PHP on the SQL figures above; every row carries its explanation.
        $recs = [];
        foreach (($data['reorder'] ?? []) as $r) {
            $onHand = (int)$r['quantity_on_hand']; $level = (int)$r['reorder_level']; $out = (int)$r['outstanding']; $onOrder = (int)$r['on_order']; $used = (int)$r['used_recent'];
            $needed = max(0, $level - $onHand) + $out;
            $suggest = max(0, $needed - $onOrder);
            if ($level > 0 && $onHand <= $level) {
                $recs[] = ['rule' => 'reorder', 'part_id' => (int)$r['id'], 'part' => $r['part_number'], 'description' => $r['description'], 'suggest' => $suggest, 'unit' => $r['unit'], 'on_hand' => $onHand, 'level' => $level, 'outstanding' => $out, 'on_order' => $onOrder,
                    'value' => round($suggest * (float)$r['unit_cost'], 2),
                    'why' => "On hand $onHand is at or below the reorder level $level. Need = (reorder level $level − on hand $onHand, not below 0) + outstanding requests $out = $needed; already ordered and not yet received (POs pending approval or approved) $onOrder."
                        . ($suggest ? " Suggested order: $suggest {$r['unit']}." : ' Already covered by open purchase orders.')];
            }
            // UNSUPPORTED_CLASSIFICATION_CLEANUP: the 30-day stock-cover 'replenish' rule was removed (no company policy for the cover
            // period). Only the configured reorder level drives the reorder recommendation.
        }
        // Forecast honesty: moving average only with enough complete months of real stock-out history, with a back-test.
        $hist = $data['history'] ?? [];
        $forecast = ['status' => 'insufficient', 'months' => count($hist), 'required' => $R['forecast_min_months'], 'window' => $R['forecast_window']];
        if (count($hist) >= $R['forecast_min_months']) {
            $units = array_map(fn($h) => (float)$h['units'], $hist); $w = $R['forecast_window']; $errs = [];
            for ($i = $w; $i < count($units); $i++) $errs[] = abs($units[$i] - array_sum(array_slice($units, $i - $w, $w)) / $w);
            $forecast = ['status' => 'ok', 'months' => count($hist), 'required' => $R['forecast_min_months'], 'window' => $w,
                'next' => round(array_sum(array_slice($units, -$w)) / $w, 1), 'mae' => round(array_sum($errs) / max(1, count($errs)), 1), 'tested' => count($errs),
                'mean' => round(array_sum($units) / count($units), 1)];
        }
        // ---------------- PURCHASING_REPORTS_V2
        if (!class_exists('Analytics')) Actions::load('Analytics');
        $buckets = Analytics::buckets($from, $to);
        $bi = function (string $d) use ($buckets): ?int { $d = substr($d, 0, 10); foreach ($buckets as $i => $b) if ($d >= $b['from'] && $d <= $b['to']) return $i; return null; };
        $zero = array_fill(0, count($buckets), 0.0);
        $flow = ['in' => $zero, 'out' => $zero, 'moves' => array_fill(0, count($buckets), [])];
        foreach (($data['flowMoves'] ?? []) as $m) {
            if (($i = $bi($m['created_at'])) === null) continue;
            $flow[$m['movement_type'] === 'Stock In' ? 'in' : 'out'][$i] += (float)$m['value'];
            $flow['moves'][$i][] = $m;
        }
        $flow['in'] = array_map(fn($x) => round($x, 2), $flow['in']); $flow['out'] = array_map(fn($x) => round($x, 2), $flow['out']);
        $reqShort = array_fill(0, count($buckets), 0);
        foreach (($data['requestsByDay'] ?? []) as $r) if (in_array($r['status'], ['Partially Released', 'Not Available'], true) && ($i = $bi($r['d'])) !== null) $reqShort[$i]++;
        $usage = []; foreach (($data['usage'] ?? []) as $u2) $usage[(int)$u2['part_id']] = $u2;
        $partsNow = [];
        foreach (($data['reorder'] ?? []) as $r) $partsNow[] = $r + ['releases' => (int)($usage[(int)$r['id']]['releases'] ?? 0)];
        $data['flow'] = $flow + ['pointTips' => self::flowRules($flow, $reqShort, $partsNow, $buckets)];
        $data['buckets'] = $buckets; $data['granularity'] = Analytics::bucketUnit($from, $to);
        $data['partTips'] = self::partRules($partsNow);
        $top = $data['bySupplier'][0] ?? null;
        $data['detail']['topSupplier'] = $top ? array_values(array_filter($data['detail']['approved'] ?? [], fn($o) => $o['supplier'] === $top['supplier'])) : [];
        unset($data['flowMoves'], $data['usage'], $data['requestsByDay']);
        unset($data['reorder'], $data['history']);
        $data['recommendations'] = $recs;
        $data['forecast'] = $forecast;
        $data['rules'] = $R;
        $data['period'] = ['from' => $from, 'to' => $to];
        $data['countsReady'] = self::countsReady($p);
        return $data;
    }

    /** PURCHASING_REPORTS_V2 — minimum Stock Out releases in the period before usage counts as "frequent" (enough history). */
    public const FREQUENT_RELEASES = 3;

    /**
     * Replenishment suggestions for parts that are low NOW (on hand <= reorder level, or less than what open requests still need).
     * Uses: releases in the period, on hand, reorder level, open request quantities, approved and awaiting-approval PO quantities.
     * Never states an order quantity or a coverage forecast; never creates a PO.
     *   A  frequent use (>= FREQUENT_RELEASES releases) and approved incoming quantity does not cover the need -> review replenishment
     *   B  approved incoming quantity covers the need -> follow up the delivery (no new purchase suggested)
     *   C  otherwise (too few releases in the period) -> insufficient usage history for a demand-based recommendation
     * "Need" = (reorder level - on hand, not below 0) + quantities still requested and not released.
     */
    public static function partRules(array $parts): array {
        $out = ['A' => [], 'B' => [], 'C' => []];
        foreach ($parts as $r) {
            $onHand = (int)$r['quantity_on_hand']; $level = (int)$r['reorder_level']; $req = (int)$r['outstanding'];
            $low = ($level > 0 && $onHand <= $level) || $req > $onHand;
            if (!$low) continue;
            $need = max(0, $level - $onHand) + $req;
            $approved = (int)($r['on_order_approved'] ?? 0); $pending = (int)($r['on_order_pending'] ?? 0);
            $part = $r['part_number']; $inv = ['go' => 'inventory?open=' . (int)$r['id'], 'label' => 'Part'];
            $pendNote = $pending > 0 ? ' A PO for this part is awaiting approval.' : '';
            if ($need > 0 && $approved >= $need) {
                $out['B'][] = ['text' => "$part: stock is low, but an approved PO covers the shortage. Follow up the delivery.",
                    'go' => $r['open_po_id'] ? 'purchase_orders?open=' . (int)$r['open_po_id'] : $inv['go'], 'label' => $r['open_po_id'] ? 'Open PO' : 'Part'];
            } elseif ((int)($r['releases'] ?? 0) >= self::FREQUENT_RELEASES) {
                $out['A'][] = ['text' => "$part: on hand $onHand, reorder level $level, released " . (int)$r['releases'] . " time(s) in the period; approved incoming quantity ($approved) does not cover the shortage. Review replenishment." . $pendNote] + $inv;
            } else {
                $out['C'][] = ['text' => "$part: stock is low. Insufficient usage history for a demand-based restocking recommendation." . $pendNote]
                    + ($pending > 0 && $r['pending_po_id'] ? ['go' => 'purchase_orders?open=' . (int)$r['pending_po_id'], 'label' => 'Open PO'] : $inv);
            }
        }
        return array_merge($out['A'], $out['B'], $out['C']);
    }

    /**
     * Suggestions for one chart period (what to follow up from that period's records; nothing is predicted):
     *  - a PO received in the period that is still only partly delivered;
     *  - parts requests made in the period that are still short of stock;
     *  - parts issued in the period that are now at or below their reorder level.
     */
    public static function flowRules(array $flow, array $reqShort, array $partsNow, array $buckets): array {
        $low = []; foreach ($partsNow as $r) if ((int)$r['reorder_level'] > 0 && (int)$r['quantity_on_hand'] <= (int)$r['reorder_level']) $low[$r['part_number']] = (int)$r['id'];
        $tips = [];
        foreach ($buckets as $i => $b) {
            $t = []; $seenPo = [];
            foreach ($flow['moves'][$i] as $m) if ($m['movement_type'] === 'Stock In' && $m['po_status'] === 'Partially Received' && !isset($seenPo[$m['reference_id']])) {
                $seenPo[$m['reference_id']] = 1;
                $t[] = ['text' => $m['ref'] . ' was only partly delivered. Follow up the remaining items.', 'go' => 'purchase_orders?open=' . (int)$m['reference_id'], 'label' => 'Open PO'];
            }
            if (!empty($reqShort[$i])) $t[] = ['text' => $reqShort[$i] . ' parts request(s) made in this period are still short of stock.', 'go' => 'parts_requests?tab=followup', 'label' => 'Follow up'];
            $issuedLow = [];
            foreach ($flow['moves'][$i] as $m) if ($m['movement_type'] === 'Stock Out' && isset($low[$m['part_number']])) $issuedLow[$m['part_number']] = 1;
            if ($issuedLow) $t[] = ['text' => 'Parts issued in this period are now at or below their reorder level: ' . implode(', ', array_keys($issuedLow)) . '.', 'go' => 'inventory?stock=low', 'label' => 'Low stock'];
            $tips[] = $t;
        }
        return $tips;
    }
}
