<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// Access is lazily loaded by the application; load it explicitly for unit tests.
core('Access');

final class HeadManagementNavigationTest extends TestCase {
    public function testAdminReceivesExecutiveSidebarInBusinessWorkflowOrder(): void {
        $nav=Access::navFor(['role'=>'admin']);
        self::assertSame([
            'dashboard','head-trips','head-fleet','maintenance','clients','routes',
            'finance','billing','fuel-adjustment','head-approvals','reports','admin','head-settings',
        ],array_column($nav,'id'));
        self::assertSame('Executive Dashboard',$nav[0]['label']);
        self::assertSame('Client Management',$nav[4]['label']);
        self::assertSame('System Configuration',$nav[12]['label']);
        self::assertSame('Operations Oversight',$nav[2]['section']);
        self::assertSame('Client & Commercial',$nav[4]['section']);
        self::assertSame('Financial Oversight',$nav[6]['section']);
        self::assertSame('Management Control',$nav[9]['section']);
    }

    public function testBrowserShellUsesRoleSpecificExecutiveNavigationAndLoadsOverviewScreens(): void {
        $core = file_get_contents(dirname(__DIR__, 2).'/public/assets/js/core.js');
        $index = file_get_contents(dirname(__DIR__, 2).'/public/index.php');
        self::assertIsString($core);
        self::assertIsString($index);
        self::assertStringContainsString("if (u.role === 'admin')", $core);
        self::assertStringContainsString("item.section !== section", $core);
        self::assertStringContainsString("UI.esc(item.label)", $core);
        self::assertStringContainsString('class="head-nav-group"', $core);
        self::assertStringContainsString('head-nav-items', $core);
        self::assertStringContainsString('if (headGroup) headGroup.open = true', $core);
        self::assertStringContainsString('Head Management', file_get_contents(dirname(__DIR__,2).'/public/assets/css/layout.css') ?: '');
        self::assertStringContainsString("'administration/head-management'", $index);
    }

    public function testOversightPagesAreAdminOnly(): void {
        foreach(['head-trips','head-fleet','head-approvals','head-settings'] as $page) {
            self::assertTrue(Access::canOpen(['role'=>'admin'],$page));
            foreach(['operations_head','motorpool','finance','billing','audit'] as $role)
                self::assertFalse(Access::canOpen(['role'=>$role],$page));
        }
        self::assertFalse(Access::canOpen(['role'=>'operations_head'],'clients'));
        self::assertTrue(Access::canOpen(['role'=>'admin'],'clients'));
    }

    public function testDedicatedHeadTripPageUsesClientRecordComponents(): void {
        $root=dirname(__DIR__,2);
        $code=file_get_contents($root.'/public/assets/js/modules/administration/head-management.js');
        self::assertIsString($code);
        self::assertStringContainsString("App.page('head-trips'", $code);
        self::assertStringContainsString('const HeadRecords =', $code);
        self::assertStringContainsString('RecordComponents.panel(table', $code);
        self::assertStringContainsString('RecordComponents.mountPanel(root', $code);
        self::assertStringContainsString('RecordComponents.categories(categories', $code);
        self::assertStringContainsString('RecordComponents.bindDropdowns(root', $code);
        self::assertStringContainsString('data-head-trip-records', $code);
        self::assertStringContainsString("title:'Company Trip Records'", $code);
        self::assertStringContainsString("type:'date'", $code);
        self::assertStringContainsString('HeadManagement.trip_details', $code);
        self::assertStringContainsString('UI.chartHtml(', $code);
        self::assertStringContainsString('UI.routeMap(', $code);
        self::assertStringContainsString('Saved road geometry only', $code);
        self::assertStringNotContainsString('data-head-trip-tabs', $code);
        $backend=file_get_contents($root.'/backend/Modules/Administration/HeadManagement.php');
        self::assertStringContainsString('route_preview', $backend);
        self::assertStringContainsString('JOIN trip_routes r', $backend);
    }

    public function testFleetWorkspaceUsesSharedClientRecordComponentsAndRealAggregates(): void {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/modules/administration/head-management.js');
        $php=file_get_contents($root.'/backend/Modules/Administration/HeadManagement.php');
        self::assertIsString($js);
        self::assertIsString($php);
        self::assertStringContainsString('const HeadFleetWorkspace =', $js);
        self::assertStringContainsString('data-head-fleet-table', $js);
        self::assertStringContainsString('HeadRecords.render(container', $js);
        self::assertStringContainsString('data-head-fleet-kpi', $js);
        self::assertStringContainsString('fleet_summary', $php);
        self::assertStringContainsString('COUNT(*) FROM vehicles', $php);
        self::assertStringContainsString('COUNT(*) FROM personnel', $php);
        self::assertStringNotContainsString('data-head-fleet-tab="', $js);
        self::assertStringNotContainsString('head-fleet-records-heading', $js);
        self::assertStringNotContainsString("App.api('Fleet.", $js);
    }

    public function testHeadMaintenanceUsesClientRecordComponentsAndKeepsMotorpoolFlow(): void {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/modules/maintenance/maintenance.js');
        $css=file_get_contents($root.'/public/assets/css/layout.css');
        self::assertIsString($js);
        self::assertIsString($css);
        self::assertStringContainsString('const HeadMaintenanceUI =', $js);
        self::assertStringContainsString("App.me?.user?.role === 'admin'", $js);
        self::assertStringContainsString('HeadMaintenanceUI.render(ctx,d,jobs)', $js);
        self::assertStringContainsString('RecordComponents.panel(table', $js);
        self::assertStringContainsString('RecordComponents.mountPanel(root', $js);
        self::assertStringContainsString('RecordComponents.categories(', $js);
        self::assertStringContainsString('RecordComponents.bindDropdowns(root', $js);
        self::assertStringContainsString('data-head-maintenance-category', $js);
        self::assertStringContainsString('data-head-maintenance-export', $js);
        self::assertStringContainsString('head-trip-kpis', $js);
        self::assertStringNotContainsString('head-maintenance-records', $js);
        self::assertStringNotContainsString('data-head-maintenance-tab', $js);
        self::assertStringNotContainsString('.head-maintenance-records', $css);
        self::assertStringContainsString("if (d.can.act) UI.tool(ctx, 'Report truck problem'", $js);
    }

    public function testHeadRouteRatesHaveExecutiveWorkspaceAndKeepApprovalActions(): void {
        $root=dirname(__DIR__,2);
        $code=file_get_contents($root.'/public/assets/js/modules/operations/routes.js');
        $css=file_get_contents($root.'/public/assets/css/layout.css');
        self::assertIsString($code);
        self::assertIsString($css);
        self::assertStringContainsString('const HeadRouteWorkspace =', $code);
        self::assertStringContainsString("App.me?.user?.role === 'admin'", $code);
        self::assertStringContainsString('data-head-route-category', $code);
        self::assertStringContainsString('data-head-route-client', $code);
        self::assertStringContainsString('record-workspace-export', $code);
        self::assertStringContainsString('RecordComponents.bindDropdowns(target', $code);
        self::assertStringContainsString('RecordComponents.panel(table', $code);
        self::assertStringContainsString('RecordComponents.mountPanel(target', $code);
        self::assertStringContainsString('RecordComponents.categories(', $code);
        self::assertStringContainsString('UI.table(cols,rows', $code);
        self::assertStringNotContainsString('head-route-records head-monitor-panel', $code);
        self::assertStringContainsString("d.can.approve&&r.pending_rate_id", $code);
        self::assertStringContainsString("RouteUI.open(r.id,d)", $code);
        self::assertStringContainsString('Routes.decide', $code);
        self::assertStringContainsString('.head-route-workspace', $css);
        self::assertStringContainsString("label:'Closed / Inactive routes'", $code);
        self::assertStringContainsString("['Rejected','Withdrawn','Inactive'].includes(r.status)", $code);
        self::assertStringContainsString('grid-template-columns:repeat(4,minmax(0,1fr))', $css);
    }

    public function testExportOnlyAvailableForTripRows(): void {
        $js=file_get_contents(dirname(__DIR__,2).'/public/assets/js/modules/administration/head-management.js');
        self::assertIsString($js);
        self::assertStringContainsString('data-head-record-export', $js);
        self::assertStringContainsString('exportRows:isIncident?null:rows=>HeadTripsUI.exportRows(rows)', $js);
        self::assertStringContainsString('exportRows?\'', $js);
    }

    public function testHeadFinancialAndBillingLayoutsUseFourKPIsWithoutChangingDepartmentActions(): void {
        $root=dirname(__DIR__,2);
        $finance=file_get_contents($root.'/public/assets/js/modules/accounting/finance.js');
        $billing=file_get_contents($root.'/public/assets/js/modules/accounting/billing.js');
        $css=file_get_contents($root.'/public/assets/css/layout.css');
        self::assertIsString($finance);
        self::assertIsString($billing);
        self::assertIsString($css);
        self::assertStringContainsString("const head = UI.role('admin')", $finance);
        self::assertStringContainsString("const head = UI.role('admin')", $billing);
        self::assertStringContainsString('head-financial-records', $finance);
        self::assertStringContainsString('head-financial-records', $billing);
        self::assertStringContainsString('...(!head ? [{ label:', $finance);
        self::assertStringContainsString('...(!head ? [{ label:', $billing);
        self::assertStringContainsString('Finance.po_pay', $finance);
        self::assertStringContainsString('Billing.charge_decide', $billing);
        self::assertStringContainsString('.head-financial-overview', $css);
        self::assertStringContainsString('.head-billing-overview', $css);
    }

    public function testHeadFinanceDoesNotShowEmptyPaymentFollowupAndKeepsKpiVisible(): void {
        $root=dirname(__DIR__,2);
        $code=file_get_contents($root.'/public/assets/js/modules/accounting/finance.js');
        $css=file_get_contents($root.'/public/assets/css/layout.css');
        self::assertIsString($code);
        self::assertIsString($css);
        self::assertStringContainsString('paymentAlerts.some(item=>item.count>0)', $code);
        self::assertStringContainsString("Financial Overview", $code);
        self::assertStringContainsString('head-financial-records', $code);
        self::assertStringContainsString('#pageContent:has(.head-financial-overview)', $css);
        self::assertStringContainsString('min-height:124px', $css);
    }

    public function testExecutiveRecordTablesFollowClientManagementWorkspace(): void {
        $root=dirname(__DIR__,2);
        $records=file_get_contents($root.'/public/assets/js/components/records.js');
        $finance=file_get_contents($root.'/public/assets/js/modules/accounting/finance.js');
        $billing=file_get_contents($root.'/public/assets/js/modules/accounting/billing.js');
        $styles=file_get_contents($root.'/public/assets/css/records.css');
        self::assertIsString($records);
        self::assertIsString($finance);
        self::assertIsString($billing);
        self::assertIsString($styles);
        self::assertStringContainsString('executiveTabs(root, tabs, active, title)', $records);
        self::assertStringContainsString('this.panel(\'\',categories)', $records);
        self::assertStringContainsString('this.mountPanel(root,{title})', $records);
        self::assertStringContainsString('this.bindDropdowns(root', $records);
        self::assertStringContainsString('data-executive-tab', $records);
        self::assertStringContainsString('this.keepExecutiveEmptyTable(root,id)', $records);
        self::assertMatchesRegularExpression('/RecordComponents\.executiveTabs\(box,.*?financialTabs/', $finance);
        self::assertMatchesRegularExpression('/RecordComponents\.executiveTabs\(box,.*?billingTabs/', $billing);
        self::assertStringContainsString('else UI.tabs(box,financialTabs', $finance);
        self::assertStringContainsString('else UI.tabs(box,billingTabs', $billing);
        self::assertStringNotContainsString('mountHeadWorkspace', $records);
        self::assertStringNotContainsString('.head-finance-table', $styles);
    }

    public function testTripAndApprovalsUseSharedClientRecordComponents(): void {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/modules/administration/head-management.js');
        self::assertIsString($js);
        self::assertStringContainsString('data-head-trip-records', $js);
        self::assertStringContainsString('data-head-approvals-records', $js);
        self::assertStringContainsString('HeadRecords.render(root', $js);
        self::assertStringContainsString('Management Action Records', $js);
        self::assertStringContainsString('RecordComponents.kpis(metrics)', $js);
        self::assertStringContainsString("label:'Route Rates'", $js);
        self::assertStringContainsString("label:'Fund Requests'", $js);
        self::assertStringContainsString('changeCategory:select', $js);
        self::assertStringContainsString('const filterDefs=({', $js);
        self::assertStringContainsString("rates:[['Client','company_name']", $js);
        self::assertStringContainsString('const hasFilters=filters.length>0', $js);
        self::assertStringContainsString('const hasRecords=rows.length>0', $js);
        self::assertStringContainsString('sortOptions=group.cols.filter', $js);
        self::assertStringContainsString('filters,sortOptions,alwaysShowTools:true', $js);
        self::assertStringContainsString('const showTools=hasRecords||alwaysShowTools', $js);
        self::assertStringContainsString('No records in this category yet.', $js);
        self::assertStringContainsString('Choose a sort order for records when available.', $js);
        self::assertStringContainsString('filters,sortOptions,', $js);
        self::assertStringNotContainsString('Read-only monitoring. Decisions remain in their authorized departmental workflows.', $js);
        self::assertStringNotContainsString('data-head-approval-tabs', $js);
        self::assertStringNotContainsString('head-trip-records-heading><h2>Company Trip Records', $js);
    }

    public function testFuelPageUsesFourActualKpisAndSharedConfigurationTabs(): void {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/modules/administration/admin.js');
        $settings=file_get_contents($root.'/backend/Core/Settings.php');
        self::assertIsString($js);
        self::assertIsString($settings);
        self::assertStringContainsString("App.page('fuel-adjustment',{title:'Fuel & Operating Costs'", $js);
        self::assertStringContainsString("RecordComponents.kpis(kpis)", $js);
        self::assertStringContainsString('head-fuel-diesel-content', $js);
        self::assertStringContainsString('head-fuel-setting-grid', $js);
        self::assertStringContainsString('head-fuel-config-state', $js);
        self::assertStringContainsString("Configured Truck Types", $js);
        self::assertStringContainsString("Latest Configuration", $js);
        self::assertStringContainsString("RecordComponents.categories(categories", $js);
        self::assertStringContainsString("RecordComponents.panel(UI.table(columns,rows,options)", $js);
        self::assertStringContainsString("RecordComponents.mountPanel(root", $js);
        self::assertStringContainsString("this.edit(ctx,'diesel')", $js);
        self::assertStringContainsString("this.edit(ctx,row.type)", $js);
        self::assertStringContainsString("App.api('Admin.pricing_save',reviewed)", $js);
        self::assertStringContainsString("requireRole(\$u,'admin')", $settings);
        self::assertStringNotContainsString("Fuel Efficiency per Truck Type</h2>", $js);
        $css=file_get_contents($root.'/public/assets/css/records.css');
        self::assertIsString($css);
        self::assertStringContainsString('.head-fuel-config > .record-workspace-panel', $css);
        self::assertStringContainsString('.head-fuel-setting-grid', $css);
        self::assertStringContainsString('head-fuel-table-intro', $js);
        self::assertStringContainsString('head-fuel-status', $js);
        self::assertStringContainsString('head-fuel-history-empty', $js);
        self::assertStringContainsString('No configuration changes yet', $js);
        self::assertStringContainsString('onAction:(_,row)=>this.edit(ctx,row.type)', $js);
        self::assertStringContainsString('.head-fuel-table-intro', $css);
        self::assertStringContainsString('.head-fuel-history-empty', $css);
    }

    public function testHeadExecutiveReportsUsesFourPrimaryIndicatorsAndOriginalAggregates(): void {
        $root=dirname(__DIR__,2);
        $reports=file_get_contents($root.'/public/assets/js/modules/general/reports.js');
        $analytics=file_get_contents($root.'/backend/Modules/General/Analytics.php');
        $styles=file_get_contents($root.'/public/assets/css/records.css');
        self::assertIsString($reports);
        self::assertIsString($analytics);
        self::assertIsString($styles);
        self::assertStringContainsString("App.me?.user?.role === 'admin'", $reports);
        self::assertStringContainsString("head-report-config", $reports);
        self::assertStringContainsString('head-report-period-toolbar', $reports);
        self::assertStringContainsString('head-report-preset-buttons', $reports);
        self::assertStringContainsString('head-report-date-summary', $reports);
        self::assertStringContainsString('head-report-config-bottom', $reports);
        self::assertStringContainsString('.head-report-period-toolbar', $styles);
        self::assertStringContainsString('.head-report-date-summary', $styles);
        self::assertStringNotContainsString('.head-report-config-presets', $styles);
        self::assertStringContainsString("head-report-performance", $reports);
        self::assertStringContainsString("head-report-details", $reports);
        self::assertStringContainsString("preferences={", $reports);
        self::assertStringContainsString("UI.statusStrip(top)", $reports);
        self::assertStringContainsString("cards:[]", $reports);
        self::assertStringContainsString("remaining=cards.filter", $reports);
        self::assertStringContainsString("UI.blocks({...section,cards:[],charts:usefulCharts,tables:usefulTables}", $reports);
        self::assertStringContainsString('head-report-no-data', $reports);
        self::assertStringContainsString('chartHasValues=chart=>', $reports);
        self::assertStringContainsString('usefulTables=(section.tables||[]).filter', $reports);
        self::assertStringContainsString('.head-report-no-data', $styles);
        self::assertStringContainsString("UI.printReport([sections[current]]", $reports);
        self::assertStringContainsString("UI.printReport(keys.map", $reports);
        self::assertStringContainsString("UI.tabs(box", $reports);
        self::assertStringContainsString("self::reportInsights(\$sections)", $analytics);
        self::assertStringContainsString(".head-report-category-tab.active", $styles);
    }

    public function testHeadUserManagementUsesSharedAccountWorkspaceWithoutChangingPermissions(): void {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/modules/administration/admin.js');
        $css=file_get_contents($root.'/public/assets/css/records.css');
        self::assertIsString($js);
        self::assertIsString($css);
        self::assertStringContainsString("RecordComponents.kpis([", $js);
        self::assertStringContainsString("RecordComponents.panel(h,RecordComponents.categories(", $js);
        self::assertStringContainsString("RecordComponents.mountPanel(root,{title:'User Accounts'})", $js);
        self::assertStringContainsString('RecordComponents.bindDropdowns(root', $js);
        self::assertStringContainsString('data-head-user-category', $js);
        self::assertStringContainsString('head-user-role-reference', $js);
        self::assertStringContainsString("Admin.user_password", $js);
        self::assertStringContainsString("Admin.user_toggle", $js);
        self::assertStringContainsString("Admin.user_save", $js);
        self::assertStringContainsString('.head-user-management .record-workspace-panel', $css);
        self::assertStringNotContainsString('What each role can do', $js);
    }

    public function testHeadSystemConfigurationUsesDedicatedSharedWorkspace(): void {
        $root=dirname(__DIR__,2);
        $js=file_get_contents($root.'/public/assets/js/modules/administration/admin.js');
        $css=file_get_contents($root.'/public/assets/css/records.css');
        self::assertStringContainsString('renderHeadSettings = (host)', $js);
        self::assertLessThan(strpos($js, 'if (isAdmin) {'), strpos($js, 'const settingText ='));
        self::assertLessThan(strpos($js, 'if (isAdmin) {'), strpos($js, 'const settingModal ='));
        self::assertStringContainsString('if(ctx.query?.headSettings && isAdmin)renderHeadSettings(box)', $js);
        self::assertStringContainsString('else UI.tabs(box,tabs', $js);
        self::assertStringContainsString('RecordComponents.panel(table,RecordComponents.categories(', $js);
        self::assertStringContainsString('RecordComponents.mountPanel(records', $js);
        self::assertStringContainsString('RecordComponents.bindDropdowns(records', $js);
        self::assertStringContainsString('limit:25,showAllColumns:true', $js);
        self::assertStringContainsString('settingModal(row):catForm(row)', $js);
        self::assertStringContainsString("App.api('Attendance.readiness'", $js);
        self::assertStringContainsString("'Admin.setting_save'", $js);
        self::assertStringContainsString("'Admin.fund_category_save'", $js);
        self::assertStringContainsString('.head-settings-workspace .record-workspace-panel', $css);
    }

    public function testHeadCompanyCommandCenterReusesVerifiedReadOnlySources(): void {
        $root=dirname(__DIR__,2);
        $dashboard=file_get_contents($root.'/public/assets/js/modules/general/dashboard.js');
        $analytics=file_get_contents($root.'/backend/Modules/General/Analytics.php');
        $css=file_get_contents($root.'/public/assets/css/records.css');
        self::assertIsString($dashboard);
        self::assertIsString($analytics);
        self::assertIsString($css);
        self::assertStringContainsString("if (role === 'admin')", $dashboard);
        self::assertStringContainsString("RPMDashboard.executive(d)", $dashboard);
        self::assertStringContainsString("const approvals=['rates','funds','purchase_orders']", $dashboard);
        self::assertStringContainsString("label:'Active Trips'", $dashboard);
        self::assertStringContainsString("label:'Pending Approvals'", $dashboard);
        self::assertStringContainsString("Outstanding receivables", $dashboard);
        self::assertStringContainsString("Fuel & Operating Costs", $dashboard);
        self::assertStringContainsString("No recent operational records in the available summaries", $dashboard);
        self::assertStringContainsString("UI.canGo(d.page)", $dashboard);
        self::assertStringContainsString("this.alerts(alerts)", $dashboard);
        self::assertStringContainsString("this.breakdown(cards,'admin')", $dashboard);
        self::assertStringContainsString('verifiedChart=', $dashboard);
        self::assertStringContainsString('UI.chartHtml(', $dashboard);
        self::assertStringContainsString('.rpm-executive-dashboard .rpm-exec-charts', $css);
        self::assertStringContainsString("readSections([", $analytics);
        self::assertStringContainsString("'executive' => \$executive", $analytics);
        self::assertStringContainsString("pricing_settings_history", $analytics);
        self::assertStringContainsString(".rpm-executive-dashboard .rpm-exec-departments", $css);
    }

    public function testHeadTripDetailsRejectOtherRolesWithoutDatabaseAccess(): void {
        Actions::load('HeadManagement');
        foreach (['operations_head','billing','finance','audit'] as $role) {
            try {
                HeadManagement::action(['id'=>1,'role'=>$role],'trip_details',['trip_id'=>1]);
                self::fail('Unauthorized trip monitoring detail access.');
            } catch (BusinessRuleException $e) {
                self::assertSame(403, $e->httpStatus);
            }
        }
    }

    public function testHeadManagementOversightRejectsDirectMutations(): void {
        Actions::load('HeadManagement');
        try {
            HeadManagement::action(['role'=>'admin','id'=>1],'change',[]);
            self::fail('Oversight mutation unexpectedly permitted.');
        } catch (BusinessRuleException $e) {
            self::assertSame(403,$e->httpStatus);
        }
    }

    public function testHeadManagementCannotChangeDepartmentMasterData(): void {
        Actions::load('Fleet');
        Actions::load('Mechanics');
        foreach([
            fn()=>Fleet::action(['id'=>1,'role'=>'admin'],'truck_save',[]),
            fn()=>Fleet::action(['id'=>1,'role'=>'admin'],'crew_save',[]),
            fn()=>Mechanics::action(['id'=>1,'role'=>'admin'],'save',[]),
        ] as $mutate) {
            try { $mutate(); self::fail('Department master data was editable by Head Management.'); }
            catch (BusinessRuleException $e) { self::assertSame(403,$e->httpStatus); }
        }
    }
}
