<?php
/*
 * =====================================================================
 *  backend/Core/Access.php  —  WHO CAN OPEN WHICH PAGE
 * =====================================================================
 *  One short menu. Every page belongs to ONE backend module
 *  (backend/Modules/<Department>/<Module>.php) and ONE screen file
 *  (public/assets/js/modules/<department>/<file>.js).
 *
 *  Roles follow PDF #11 "User Roles and Access Requirements".
 *  This list only decides which pages a role can OPEN. Every save action
 *  checks the role again in its module (requireRole / must), so typing a
 *  URL or calling the API directly does not bypass the rules.
 * =====================================================================
 */
declare(strict_types=1);

final class Access {
    /** Small headings in the menu (only shown when the role has pages under them). */
    public const SECTIONS = ['Operations', 'Masterlists', 'Maintenance', 'Purchasing', 'Accounting', 'Billing', 'Audit', 'Records', 'Operations Oversight', 'Client & Commercial', 'Financial Oversight', 'Management Control'];

    /**
     * Shared workflow pages belong in the employee's work area, never in a
     * foreign department heading merely because of the PHP module folder.
     * This is presentation ONLY. Role/record/action permissions must still
     * be enforced by backend modules and are reviewed separately in S4B-2.
     */
    private const NAV_SECTIONS = [
        'operations_head' => [
            'funds' => 'Operations', 'maintenance_overview' => 'Operations', 'maintenance' => 'Operations', 'mechanics' => 'Operations',
            'mechanic_attendance' => 'Operations',
        ],
        'car_carrier_dispatcher' => ['funds' => 'Operations', 'trucks' => 'Operations', 'crew' => 'Operations'],
        'container_wingvan_dispatcher' => ['funds' => 'Operations', 'trucks' => 'Operations', 'crew' => 'Operations'],
        'motorpool' => ['trucks' => 'Maintenance', 'mechanics' => 'Maintenance', 'mechanic_attendance' => 'Maintenance'],
        'finance' => ['finance_overview' => 'Accounting', 'purchase_orders' => 'Accounting', 'purchasing_reports' => 'Accounting', 'clients' => 'Accounting', 'routes' => 'Accounting'],
        'billing' => ['trips' => 'Billing', 'clients' => 'Billing', 'routes' => 'Billing', 'billing_overview' => 'Billing', 'billing' => 'Billing',
            'billing_collections' => 'Billing', 'billing_receivables' => 'Billing', 'reports' => 'Billing'],
        'audit' => [
            'trips' => 'Records', 'trucks' => 'Records', 'crew' => 'Records',
            'clients' => 'Records', 'routes' => 'Records', 'mechanics' => 'Records', 'mechanic_attendance' => 'Records',
            'maintenance_overview' => 'Records', 'maintenance' => 'Records', 'maint_history' => 'Records', 'funds' => 'Records',
            'parts_requests' => 'Records', 'purchase_orders' => 'Records', 'inventory' => 'Records',
            'stock_movements' => 'Records', 'purchasing_reports' => 'Records',
            'billing' => 'Records', 'finance' => 'Records', 'finance_budgets' => 'Records',
            'billing_collections' => 'Records', 'billing_receivables' => 'Records',
            'audit_overview' => 'Audit', 'audit_logs' => 'Audit', 'audit_history' => 'Audit', 'audit_reports' => 'Audit',
            'reports' => 'Records',
        ],
    ];
    private const NAV_LABELS = [
        'audit' => ['audit_overview' => 'Overview'],
        'car_carrier_dispatcher' => ['funds' => 'My Trip Funds'],
        'container_wingvan_dispatcher' => ['funds' => 'My Trip Funds'],
        // MAINTENANCE_OVERVIEW_PHASE1_V1: Motorpool wording (labels only; same pages and permissions).
        'motorpool' => ['maintenance_overview' => 'Overview', 'maintenance' => 'Repair Management', 'maintenance_reports' => 'Reports'],
        // STAGE_B_PURCHASING_UI: concise Purchasing workspace labels (labels only; same pages and permissions).
        'purchasing' => ['purchasing_overview' => 'Overview', 'purchasing_reports' => 'Reports'],
        // FINANCE_WORKSPACE_V1: Finance labels (same pages; Approvals = purchase orders routed to Finance).
        // BILLING_WORKSPACE_V1
        'billing' => ['billing_overview' => 'Overview', 'reports' => 'Billing Reports'],
        'finance' => ['finance_overview' => 'Overview', 'purchase_orders' => 'Approvals', 'funds' => 'Trip Funds'],
    ];

    /** Workspace menu order per role: listed pages first, in this order; the other pages keep the default order. */
    private const NAV_ORDER = [
        'finance' => ['finance_overview', 'purchase_orders', 'funds', 'finance', 'finance_budgets', 'purchasing_reports'],
        'billing' => ['billing_overview', 'billing', 'billing_collections', 'billing_receivables', 'reports'],
        'audit' => ['audit_overview', 'audit_logs', 'audit_history', 'audit_reports'],
    ];

    public static function pages(): array {
        $D = ['car_carrier_dispatcher', 'container_wingvan_dispatcher'];
        $all = array_keys(ROLE_LABELS);
        // Motorpool and Purchasing have their own landing pages (Maintenance / Purchasing Overview),
        // so the company-wide Dashboard is not duplicated for them.
        $dash = array_values(array_diff($all, ['motorpool', 'purchasing', 'finance', 'billing', 'audit']));
        return [
            // id => [label, icon, section, module, js file, roles]
            'dashboard'   => ['Dashboard', 'bi-grid-1x2', null, 'Analytics', 'general/dashboard', $dash],
            'trips'       => ['Trip Management', 'bi-signpost-split', null, 'Trips', 'operations/trips',
                              array_merge(['admin', 'operations_head', 'motorpool', 'billing', 'audit'], $D)],

            'dispatcher-monitoring' => ['Dispatcher Monitoring','bi-person-workspace',null,'Trips','operations/ops-head',['operations_head']],
            'head-trips' => ['Trip Monitoring','bi-signpost-split','Operations Oversight','HeadManagement','administration/head-management',['admin']],
            'head-fleet' => ['Fleet & Personnel Status','bi-people', 'Operations Oversight', 'HeadManagement', 'administration/head-management', ['admin']],
            'head-approvals' => ['Management Action Center','bi-clipboard-check', 'Management Control', 'HeadManagement', 'administration/head-management', ['admin']],
            'head-settings' => ['System Configuration','bi-gear', 'Management Control', 'Admin', 'administration/admin', ['admin']],


            'trucks'      => ['Truck Masterlist', 'bi-truck', 'Masterlists', 'Fleet', 'operations/fleet',
                              array_merge(['admin', 'operations_head', 'motorpool', 'audit'], $D)],
            'crew'        => ['Driver & Helper Masterlist', 'bi-person-badge', 'Masterlists', 'Fleet', 'operations/fleet',
                              array_merge(['admin', 'operations_head', 'audit'], $D)],
            'clients'     => ['Client Masterlist', 'bi-building', 'Masterlists', 'Clients', 'operations/clients',
                              array_merge(['admin', 'billing', 'finance', 'audit'], $D)],
            // Approved routes per client (Checklist #20). Roles follow Routes::POLICY['view'].
            'fuel-adjustment' => ['Fuel Adjustment','bi-fuel-pump','Pricing','Admin','administration/admin',['admin']],
            'routes'      => ['Client Routes', 'bi-signpost-2', 'Masterlists', 'Routes', 'operations/routes',
                              ['admin', 'operations_head', 'billing', 'finance', 'audit']],
            'mechanics'   => ['Mechanic Masterlist', 'bi-person-gear', 'Masterlists', 'Mechanics', 'maintenance/mechanics',
                              ['admin', 'operations_head', 'motorpool', 'audit']],
            // MOTORPOOL_WORKSPACE_PHASE1: Time In / Time Out board + session history. Same roles as the Mechanic Masterlist;
            // writes go only through the existing Mechanics.time_in / time_out (Admin + Motorpool).
            'mechanic_attendance' => ['Mechanic Attendance', 'bi-clock-history', 'Masterlists', 'Mechanics', 'maintenance/mechanics',
                              ['admin', 'operations_head', 'motorpool', 'audit']],

            // MAINTENANCE_OVERVIEW_PHASE1_V1: read-only landing page; same roles as Corrective Maintenance.
            'maintenance_overview' => ['Maintenance Overview', 'bi-speedometer2', 'Maintenance', 'Maintenance', 'maintenance/maintenance',
                              ['admin', 'operations_head', 'motorpool', 'audit']],
            'maintenance' => ['Corrective Maintenance', 'bi-wrench-adjustable', 'Maintenance', 'Maintenance', 'maintenance/maintenance',
                              ['admin', 'operations_head', 'motorpool', 'audit']],
            'maint_history' => ['Maintenance History', 'bi-clock-history', 'Maintenance', 'Maintenance', 'maintenance/history',
                              ['admin', 'operations_head', 'motorpool', 'audit']],
            // MOTORPOOL_WORKSPACE_PHASE1: Motorpool's own parts requests (request, track, cancel). Served by Purchasing::read
            // with identification and availability labels only: no costs, stock counts, budget, supplier or PO data.
            'mp_parts_requests' => ['Parts Requests', 'bi-box-seam', 'Maintenance', 'Purchasing', 'maintenance/maintenance',
                              ['motorpool']],
            // UI_REFERENCE_V1: Maintenance Reports (Motorpool workspace "Reports"): repairs, downtime and maintenance cost.
            // Read-only, served by Analytics::maintenanceReport. Replaces Motorpool's use of the general Reports page.
            'maintenance_reports' => ['Maintenance Reports', 'bi-graph-up', 'Maintenance', 'Analytics', 'maintenance/maintenance',
                              ['admin', 'operations_head', 'motorpool', 'audit']],

            // Purchasing workspace (replaces the combined "Parts & Purchasing" page). The five Purchasing pages share
            // one backend module and one screen file. Purchasing Officer: all pages. Finance: Purchase Orders (approvals)
            // and Purchasing Reports. Admin and Audit keep their previous read access (Admin also approves POs above the limit).
            // Motorpool requests and tracks parts inside Repair Management (Purchasing::request_catalog / my_requests).
            'purchasing_overview' => ['Purchasing Overview', 'bi-speedometer2', 'Purchasing', 'Purchasing', 'maintenance/purchasing',
                              ['purchasing']],
            'parts_requests' => ['Parts Requests', 'bi-box-arrow-up', 'Purchasing', 'Purchasing', 'maintenance/purchasing',
                              ['admin', 'purchasing', 'audit']],
            'purchase_orders' => ['Purchase Orders', 'bi-cart-check', 'Purchasing', 'Purchasing', 'maintenance/purchasing',
                              ['admin', 'purchasing', 'finance', 'audit']],
            'inventory'   => ['Inventory', 'bi-box-seam', 'Purchasing', 'Purchasing', 'maintenance/purchasing',
                              ['admin', 'purchasing', 'audit']],
            'stock_movements' => ['Stock Movements', 'bi-arrow-left-right', 'Purchasing', 'Purchasing', 'maintenance/purchasing',
                              ['admin', 'purchasing', 'audit']],
            'purchasing_reports' => ['Purchasing Reports', 'bi-graph-up', 'Purchasing', 'Analytics', 'maintenance/purchasing',
                              ['admin', 'purchasing', 'finance', 'audit']],

            'funds'       => ['Trip Funds', 'bi-cash-coin', 'Accounting', 'Funds', 'accounting/funds',
                              array_merge(['admin', 'operations_head', 'finance', 'audit'], $D)],
            // BILLING_WORKSPACE_V1: Overview (Billing landing page), Billing & Invoices, Collections, Receivables. Same Billing module and rules.
            'billing_overview' => ['Billing Overview', 'bi-speedometer2', 'Billing', 'Billing', 'accounting/billing', ['billing']],
            'billing'     => ['Billing & Invoices', 'bi-receipt', 'Accounting', 'Billing', 'accounting/billing',
                              ['admin', 'billing', 'finance', 'audit']],
            'billing_collections' => ['Collections', 'bi-cash-stack', 'Accounting', 'Billing', 'accounting/billing', ['admin', 'billing', 'finance', 'audit']],
            'billing_receivables' => ['Receivables', 'bi-hourglass-split', 'Accounting', 'Billing', 'accounting/billing', ['admin', 'billing', 'finance', 'audit']],
            // FINANCE_WORKSPACE_V1: Overview (Finance landing page), Payables & Payments (page id 'finance' kept: notifications
            // link to it) and Budgets & Expenses. Admin and Audit keep read access to the two record pages.
            'finance_overview' => ['Finance Overview', 'bi-speedometer2', 'Accounting', 'Finance', 'accounting/finance', ['finance']],
            'finance'     => ['Payables & Payments', 'bi-wallet2', 'Accounting', 'Finance', 'accounting/finance',
                              ['admin', 'finance', 'audit']],
            'finance_budgets' => ['Budgets & Expenses', 'bi-piggy-bank', 'Accounting', 'Finance', 'accounting/finance',
                              ['admin', 'finance', 'audit']],

            'documents'   => ['Documents', 'bi-folder2-open', 'Records', 'Documents', 'general/documents', $all],
            'reports'     => ['Reports', 'bi-bar-chart-line', 'Records', 'Analytics', 'general/reports',
                              ['admin', 'operations_head', 'finance', 'billing', 'audit']],
            'notifications' => ['Notifications', 'bi-bell', 'Records', 'Admin', 'general/notifications', $all],
            // AUDIT_WORKSPACE_V1: read-only audit pages (Admin module, audit_events). Audit uses them instead of its former
            // Activity Log view of User Management (same records, now with filters, record history and reports).
            'audit_overview' => ['Audit Overview', 'bi-speedometer2', 'Audit', 'Admin', 'administration/audit', ['audit']],
            'audit_logs'  => ['Audit Logs', 'bi-journal-text', 'Audit', 'Admin', 'administration/audit', ['admin', 'audit']],
            'audit_history' => ['Transaction History', 'bi-clock-history', 'Audit', 'Admin', 'administration/audit', ['admin', 'audit']],
            'audit_reports' => ['Audit Reports', 'bi-clipboard-data', 'Audit', 'Admin', 'administration/audit', ['admin', 'audit']],
            'admin'       => ['User Management', 'bi-people', 'Records', 'Admin', 'administration/admin', ['admin']],
            // HEAD_MANAGEMENT_V1: Head Management (Admin) workspace. Read-only oversight pages (Oversight module); approval
            // decisions use the existing Purchasing / Billing actions, which check the Admin role and the request state.
            'admin_overview'   => ['Head Management Overview', 'bi-speedometer2', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_approvals'  => ['Approvals', 'bi-check2-square', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_operations' => ['Operations Monitoring', 'bi-signpost-split', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_maintenance' => ['Maintenance Monitoring', 'bi-wrench-adjustable', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_purchasing' => ['Purchasing Monitoring', 'bi-cart-check', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_finance'    => ['Financial Oversight', 'bi-cash-coin', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_reports'    => ['Executive Reports', 'bi-clipboard-check', 'Records', 'Oversight', 'administration/oversight', ['admin']],
            'admin_analytics'  => ['Analytics', 'bi-graph-up', 'Records', 'Oversight', 'administration/oversight', ['admin']],
        ];
    }

    /** Pages the user can open, for the menu. */
    public static function navFor(array $u): array {
        $out = [];
        if (($u['role'] ?? null) === 'admin') {
            // Head Management uses a purpose-built executive navigation. Original
            // page URLs/permissions remain available for existing cross-page links.
            $structure = [
                'dashboard' => ['Executive Dashboard', null],
                'head-trips' => ['Trip Monitoring', 'Operations Oversight'],
                'head-fleet' => ['Fleet & Personnel Status', 'Operations Oversight'],
                'maintenance' => ['Maintenance Monitoring', 'Operations Oversight'],
                'clients' => ['Client Management', 'Client & Commercial'],
                'routes' => ['Route & Contract Rates', 'Client & Commercial'],
                'finance' => ['Financial Overview', 'Financial Oversight'],
                'billing' => ['Billing & Collections', 'Financial Oversight'],
                'fuel-adjustment' => ['Fuel & Operating Costs', 'Financial Oversight'],
                'head-approvals' => ['Management Action Center', 'Management Control'],
                'reports' => ['Management Reports', 'Management Control'],
                'admin' => ['User & Access Management', 'Management Control'],
                'head-settings' => ['System Configuration', 'Management Control'],
            ];
            $pages = self::pages();
            foreach ($structure as $id => [$label, $section]) {
                [$oldLabel, $icon, $oldSection, $module, $js, $roles] = $pages[$id];
                if (in_array('admin', $roles, true))
                    $out[] = ['id'=>$id,'label'=>$label,'icon'=>$icon,'section'=>$section,'js'=>$js];
            }
            return $out;
        }
        foreach (self::pages() as $id => [$label, $icon, $section, $module, $js, $roles]) {
            if (in_array($u['role'], $roles, true)) {
                // Navigation grouping is per ROLE, not per backend department.
                // Existing server-side page/action authorization stays unchanged.
                $section = self::NAV_SECTIONS[$u['role']][$id] ?? $section;
                $label = self::NAV_LABELS[$u['role']][$id] ?? $label;
                if($u['role']==='admin'&&$id==='routes'){$section='Pricing';$label='Route Rates';}
                $out[] = ['id' => $id, 'label' => $label, 'icon' => $icon, 'section' => $section, 'js' => $js];
            }
        }
        if ($order = self::NAV_ORDER[$u['role']] ?? null) {
            $rank = array_flip($order); $pos = array_flip(array_column($out, 'id'));
            usort($out, fn($a, $b) => [$rank[$a['id']] ?? 999, $pos[$a['id']]] <=> [$rank[$b['id']] ?? 999, $pos[$b['id']]]);
        }
        return $out;
    }

    /** Authorized deep links are independent of the role's curated sidebar. */
    public static function routesFor(array $u): array {
        $routes = [];
        foreach (self::pages() as $id => [$label, $icon, $section, $module, $js, $roles]) {
            if (self::canOpen($u, $id))
                $routes[$id] = ['id'=>$id, 'label'=>$label, 'icon'=>$icon, 'section'=>$section, 'js'=>$js];
        }
        // Keep the approved role-specific labels on pages already in the menu.
        foreach (self::navFor($u) as $item) $routes[$item['id']] = $item;
        return array_values($routes);
    }

    public static function canOpen(array $u, string $page): bool {
        $p = self::pages()[$page] ?? null;
        return $p !== null && in_array($u['role'], $p[5], true);
    }

    public static function moduleOf(string $page): ?string { return self::pages()[$page][3] ?? null; }
}
