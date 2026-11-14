# UAT role-to-task matrix (generated from evidence)

Generated 2026-11-14 on branch `luis`. Sources: `tests/run_role_action_probe.php` (every action x every role on the isolated 3307 server, each call rolled back),
front-end callers in `public/assets/js` (`App.api(...)` / form actions), and the suites that call each action. Statuses: PASS = role passes the server check,
a UI control calls the action and a suite exercises it through the real action; NOT TESTED; MISSING UI; BLOCKED BY POLICY (retired); NOT APPLICABLE (refused by design).
"browser" = additionally performed through the real UI in the isolated preview. Probe raw code per cell: 403 / 4xx (validation or state) / OK (executed, rolled back).

## Admin / Head Management

Pages (server navigation): dashboard, trips, trucks, crew, clients, fuel-adjustment, routes, mechanics, mechanic_attendance, maintenance_overview, maintenance, maint_history, maintenance_reports, parts_requests, purchase_orders, inventory, stock_movements, purchasing_reports, funds, billing, billing_collections, billing_receivables, finance, finance_budgets, documents, reports, notifications, audit_logs, audit_history, audit_reports, admin, admin_overview, admin_approvals, admin_operations, admin_maintenance, admin_purchasing, admin_finance, admin_reports, admin_analytics

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.backup_create` | PASS | UI handler + suites: uat_gap | OK |
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.fund_category_save` | PASS | UI handler + suites: uat_gap | 400 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.pricing_save` | PASS | UI handler + suites: dispatch_attendance, incident_clearance, integration_workflow, uat_browser_scenario | 400 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.setting_save` | PASS | UI handler + suites: crew_attendance, dispatch_attendance, incident_clearance, integration_workflow, trip_schedule, uat_browser_scenario | OK |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Admin.user_password` | PASS | UI handler + suites: uat_gap | 400 |
| `Admin.user_save` | PASS | UI handler + suites: account_guard, mechanic_attendance | 400 |
| `Admin.user_toggle` | PASS | UI handler + suites: account_guard | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Attendance.list` | PASS | UI handler + suites: crew_attendance, dispatch_attendance, mechanic_attendance, turnover | OK |
| `Attendance.readiness` | PASS | UI handler + suites: dispatch_attendance | OK |
| `Attendance.save` | PASS | UI handler + suites: crew_attendance, dispatch_attendance, turnover + browser | 400 |
| `Billing.charge_decide` | PASS | UI handler + suites: billing_adjustment, pricing | 400 |
| `Billing.details` | PASS | UI handler + suites: billing_adjustment | OK |
| `Billing.pricing_recalculate` | PASS | UI handler + suites: pricing | 400 |
| `Billing.pricing_report` | PASS | UI handler + suites: pricing | OK |
| `Billing.soa` | PASS | UI handler + suites: uat_gap | OK |
| `Clients.create` | PASS | UI handler + suites: pricing | 400 |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.documents` | PASS | UI handler + suites: uat_gap | 400 |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Clients.set_active` | PASS | UI handler + suites: uat_gap | OK |
| `Clients.update` | PASS | UI handler + suites: uat_gap | 400 |
| `Fleet.crew_active` | PASS | UI handler + suites: uat_gap | 409 |
| `Fleet.crew_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.crew_save` | PASS | UI handler + suites: crew_attendance, operations_v43 | 400 |
| `Fleet.crew_status` | PASS | UI handler + suites: uat_gap | 400 |
| `Fleet.truck_active` | PASS | UI handler + suites: uat_gap | 409 |
| `Fleet.truck_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_save` | PASS | UI handler + suites: operations_v43 + browser | 400 |
| `Fleet.truck_status` | PASS | UI handler + suites: operations_v43 | 400 |
| `Funds.details` | PASS | UI handler + suites: dispatcher, planning_dispatcher | OK |
| `Maintenance.details` | PASS | UI handler + suites: incident_clearance, job_helpers, maintenance_overview | OK |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Mechanics.details` | PASS | UI handler + suites: mechanic_attendance | OK |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.count_details` | PASS | UI handler + suites: stock_count | 404 |
| `Purchasing.count_review` | PASS | UI handler + suites: head_management, stock_count | 400 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.part_history` | PASS | UI handler + suites: purchasing_finance | OK |
| `Purchasing.po_decide` | PASS | UI handler + suites: accounting_workspace, head_management, integration_workflow, inventory_finance, purchasing_finance, stock_count, uat_browser_scenario + browser | 400 |
| `Purchasing.po_details` | PASS | UI handler + suites: purchasing_finance | OK |
| `Purchasing.request_details` | PASS | UI handler + suites: uat_gap | OK |
| `Routes.deactivate` | PASS | UI handler + suites: route | 400 |
| `Routes.decide` | PASS | UI handler + suites: operations_v43, pricing, route | 400 |
| `Routes.details` | PASS | UI handler + suites: route | OK |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.pricing_estimate` | PASS | UI handler + suites: uat_gap | OK |
| `Routes.rate_enter` | PASS | UI handler + suites: operations_v43, pricing, route | 400 |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 403 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |

## Operations Head

Pages (server navigation): dashboard, trips, dispatcher-monitoring, trucks, crew, clients, routes, mechanics, mechanic_attendance, maintenance_overview, maintenance, maint_history, maintenance_reports, funds, documents, reports, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Analytics.operations_export` | PASS | UI handler + suites: operations_reports | OK |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Clients.create` | PASS | UI handler + suites: pricing | 400 |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.documents` | PASS | UI handler + suites: uat_gap | 400 |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Clients.set_active` | PASS | UI handler + suites: uat_gap | OK |
| `Clients.update` | PASS | UI handler + suites: uat_gap | 400 |
| `Fleet.crew_active` | PASS | UI handler + suites: uat_gap | 409 |
| `Fleet.crew_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.crew_save` | PASS | UI handler + suites: crew_attendance, operations_v43 | 400 |
| `Fleet.crew_status` | PASS | UI handler + suites: uat_gap | 400 |
| `Fleet.truck_active` | PASS | UI handler + suites: uat_gap | 409 |
| `Fleet.truck_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_save` | PASS | UI handler + suites: operations_v43 + browser | 400 |
| `Fleet.truck_status` | PASS | UI handler + suites: operations_v43 | 400 |
| `Funds.decide` | PASS | UI handler + suites: dispatch_attendance, dispatcher, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario + browser | 400 |
| `Funds.details` | PASS | UI handler + suites: dispatcher, planning_dispatcher | OK |
| `Maintenance.details` | PASS | UI handler + suites: incident_clearance, job_helpers, maintenance_overview | OK |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Mechanics.details` | PASS | UI handler + suites: mechanic_attendance | OK |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Routes.details` | PASS | UI handler + suites: route | OK |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.pricing_estimate` | PASS | UI handler + suites: uat_gap | OK |
| `Routes.request` | PASS | UI handler + suites: map, operations_v43, pricing, route | 400 |
| `Routes.selectable` | PASS | UI handler + suites: trip_routing | OK |
| `Routes.withdraw` | PASS | UI handler + suites: route | 400 |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 409 |
| `Trips.cancel` | PASS | UI handler + suites: fund_release, operations_v43, trip_schedule | 400 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | UI plans through Trips.create_batch (same validation) | 400 |
| `Trips.create_batch` | PASS | UI handler + suites: operations_v43, planning_account, planning_dispatcher, trip_batch | 400 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | OK |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.edit` | PASS | UI handler + suites: trip_approval, trip_routing, trip_schedule | 400 |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.incident_reschedule` | PASS | UI handler + suites: incident_clearance + browser | 400 |
| `Trips.monitor_details` | PASS | UI handler + suites: operations_v43 | OK |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 409 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 409 |
| `Trips.turnover_assign_initial` | PASS | UI handler + suites: account_guard, selected_turnover, turnover | 409 |
| `Trips.turnover_details` | PASS | UI handler + suites: selected_turnover, turnover | 400 |
| `Trips.turnover_list` | PASS | UI handler + suites: selected_turnover, turnover | OK |

## Finance

Pages (server navigation): finance_overview, purchase_orders, funds, finance, finance_budgets, purchasing_reports, clients, routes, billing, billing_collections, billing_receivables, documents, reports, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Billing.details` | PASS | UI handler + suites: billing_adjustment | OK |
| `Billing.soa` | PASS | UI handler + suites: uat_gap | OK |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Finance.budget_set` | PASS | UI handler + suites: accounting_workspace, head_management, integration_workflow, inventory_finance, purchasing_finance, stock_count, uat_browser_scenario | 400 |
| `Finance.expense_add` | PASS | UI handler + suites: uat_gap | 400 |
| `Finance.payable_add` | PASS | UI handler + suites: accounting_workspace, inventory_finance | 400 |
| `Finance.payable_pay` | PASS | UI handler + suites: inventory_finance | 400 |
| `Finance.po_pay` | PASS | UI handler + suites: head_management, integration_workflow, purchasing_finance + browser | 400 |
| `Funds.details` | PASS | UI handler + suites: dispatcher, planning_dispatcher | OK |
| `Funds.liquidate` | PASS | UI handler + suites: fund_release, operations_v43 | 400 |
| `Funds.release` | PASS | UI handler + suites: dispatch_attendance, dispatcher, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario + browser | 409 |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.po_decide` | PASS | UI handler + suites: accounting_workspace, head_management, integration_workflow, inventory_finance, purchasing_finance, stock_count, uat_browser_scenario + browser | 400 |
| `Purchasing.po_details` | PASS | UI handler + suites: purchasing_finance | OK |
| `Routes.details` | PASS | UI handler + suites: route | OK |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.pricing_estimate` | PASS | UI handler + suites: uat_gap | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 403 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |

## Billing

Pages (server navigation): billing_overview, billing, billing_collections, billing_receivables, reports, trips, clients, routes, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Billing.charge_add` | PASS | UI handler + suites: billing_adjustment, pricing | 400 |
| `Billing.charge_change` | PASS | UI handler + suites: billing_adjustment | 400 |
| `Billing.charge_remove` | PASS | UI handler + suites: billing_adjustment | 400 |
| `Billing.charge_withdraw` | PASS | UI handler + suites: billing_adjustment | 404 |
| `Billing.collect` | PASS | UI handler + suites: accounting_workspace, billing_adjustment, integration_workflow, pricing + browser | 400 |
| `Billing.details` | PASS | UI handler + suites: billing_adjustment | OK |
| `Billing.finalize` | PASS | UI handler + suites: accounting_workspace, billing_adjustment, integration_workflow, pricing + browser | 400 |
| `Billing.pricing_report` | PASS | UI handler + suites: pricing | OK |
| `Billing.soa` | PASS | UI handler + suites: uat_gap | OK |
| `Billing.verify` | PASS | UI handler + suites: accounting_workspace, billing_adjustment, integration_workflow, pricing + browser | 409 |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Routes.details` | PASS | UI handler + suites: route | OK |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.pricing_estimate` | PASS | UI handler + suites: uat_gap | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 403 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |

## Motorpool Custodian (also records mechanics: mechanics have no accounts)

Pages (server navigation): trips, trucks, mechanics, mechanic_attendance, maintenance_overview, maintenance, maint_history, mp_parts_requests, maintenance_reports, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Fleet.crew_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_status` | PASS | UI handler + suites: operations_v43 | 400 |
| `Maintenance.add_cost` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43 | 400 |
| `Maintenance.assess` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, uat_browser_scenario + browser | 400 |
| `Maintenance.assign_mechanic` | PASS | UI handler + suites: job_helpers, maintenance_overview, mechanic_attendance, uat_browser_scenario | 409 |
| `Maintenance.cancel` | PASS | UI handler + suites: job_helpers, maintenance_overview | 400 |
| `Maintenance.clearance` | PASS | UI handler + suites: incident_clearance, operations_v43, uat_browser_scenario + browser | 400 |
| `Maintenance.complete` | PASS | UI handler + suites: incident_clearance, integration_workflow, job_helpers, maintenance_overview, operations_v43 | 400 |
| `Maintenance.details` | PASS | UI handler + suites: incident_clearance, job_helpers, maintenance_overview | OK |
| `Maintenance.helper_remove` | PASS | UI handler + suites: job_helpers | 400 |
| `Maintenance.helpers_add` | PASS | UI handler + suites: job_helpers | 400 |
| `Maintenance.inspect` | PASS | UI handler + suites: incident_clearance, integration_workflow, job_helpers, maintenance_overview, operations_v43 | 400 |
| `Maintenance.report` | PASS | UI handler + suites: job_helpers, maintenance_overview, mechanic_attendance, motorpool_workspace, uat_browser_scenario + browser | 400 |
| `Maintenance.verify` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43 + browser | 400 |
| `Mechanics.availability` | NOT APPLICABLE | availability follows Time In/Out and repairs | 409 |
| `Mechanics.details` | PASS | UI handler + suites: mechanic_attendance | OK |
| `Mechanics.save` | PASS | UI handler + suites: incident_clearance, integration_workflow, job_helpers, maintenance_overview, mechanic_attendance, motorpool_workspace, operations_v43, turnover, uat_browser_scenario | 400 |
| `Mechanics.set_active` | PASS | UI handler + suites: job_helpers, mechanic_attendance | OK |
| `Mechanics.time_in` | PASS | UI handler + suites: head_management, incident_clearance, integration_workflow, job_helpers, maintenance_overview, mechanic_attendance, operations_v43, uat_browser_scenario | 409 |
| `Mechanics.time_out` | PASS | UI handler + suites: job_helpers, mechanic_attendance | OK |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | superseded by mp_parts_requests page data | OK |
| `Purchasing.request_cancel` | PASS | UI handler + suites: uat_gap | 409 |
| `Purchasing.request_catalog` | PASS | UI handler + suites: uat_gap | OK |
| `Purchasing.request_create` | PASS | UI handler + suites: integration_workflow, inventory_finance, maintenance_overview, uat_browser_scenario, uat_gap | 400 |
| `Purchasing.request_details` | PASS | UI handler + suites: uat_gap | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 403 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |

## Purchasing Officer

Pages (server navigation): purchasing_overview, parts_requests, purchase_orders, inventory, stock_movements, purchasing_reports, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.count_details` | PASS | UI handler + suites: stock_count | 404 |
| `Purchasing.count_submit` | PASS | UI handler + suites: head_management, stock_count | 400 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.part_history` | PASS | UI handler + suites: purchasing_finance | OK |
| `Purchasing.part_save` | PASS | UI handler + suites: accounting_workspace, head_management, integration_workflow, inventory_finance, maintenance_overview, motorpool_workspace, purchasing_finance, stock_count, uat_browser_scenario | 400 |
| `Purchasing.po_close` | PASS | UI handler + suites: purchasing_finance | 400 |
| `Purchasing.po_create` | PASS | UI handler + suites: accounting_workspace, head_management, integration_workflow, inventory_finance, motorpool_workspace, purchasing_finance, stock_count, uat_browser_scenario | 400 |
| `Purchasing.po_details` | PASS | UI handler + suites: purchasing_finance | OK |
| `Purchasing.po_receive` | PASS | UI handler + suites: accounting_workspace, head_management, integration_workflow, inventory_finance, purchasing_finance, stock_count, uat_browser_scenario + browser | 409 |
| `Purchasing.request_details` | PASS | UI handler + suites: uat_gap | OK |
| `Purchasing.request_process` | PASS | UI handler + suites: head_management, integration_workflow, inventory_finance, maintenance_overview, motorpool_workspace + browser | 409 |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 403 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |

## Audit

Pages (server navigation): audit_overview, audit_logs, audit_history, audit_reports, trips, trucks, crew, clients, routes, mechanics, mechanic_attendance, maintenance_overview, maintenance, maint_history, maintenance_reports, parts_requests, purchase_orders, inventory, stock_movements, purchasing_reports, funds, billing, billing_collections, billing_receivables, finance, finance_budgets, documents, reports, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | refused (403) by design | 403 |
| `Billing.details` | PASS | UI handler + suites: billing_adjustment | OK |
| `Billing.pricing_report` | PASS | UI handler + suites: pricing | OK |
| `Billing.soa` | PASS | UI handler + suites: uat_gap | OK |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Fleet.crew_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_details` | PASS | UI handler + suites: dispatcher | OK |
| `Funds.details` | PASS | UI handler + suites: dispatcher, planning_dispatcher | OK |
| `Maintenance.details` | PASS | UI handler + suites: incident_clearance, job_helpers, maintenance_overview | OK |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Mechanics.details` | PASS | UI handler + suites: mechanic_attendance | OK |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.count_details` | PASS | UI handler + suites: stock_count | 404 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.part_history` | PASS | UI handler + suites: purchasing_finance | OK |
| `Purchasing.po_details` | PASS | UI handler + suites: purchasing_finance | OK |
| `Purchasing.request_details` | PASS | UI handler + suites: uat_gap | OK |
| `Routes.details` | PASS | UI handler + suites: route | OK |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.pricing_estimate` | PASS | UI handler + suites: uat_gap | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 403 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |

## Car Carrier Dispatcher (Night)

Pages (server navigation): dashboard, trips, trucks, crew, clients, funds, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | roster served by Fleet page data (view=attendance) | OK |
| `Attendance.time_in` | PASS | UI handler + suites: _launcher, dispatch_attendance, dispatcher, operations_v43, pricing, turnover + browser | 400 |
| `Attendance.time_out` | PASS | UI handler + suites: dispatcher, turnover | 400 |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.selectable` | PASS | UI handler + suites: trip_routing | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 409 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | 404 |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.progress` | PASS | UI handler + suites: turnover | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |
| `Trips.turnover_accept` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover, turnover_concurrency_worker | 400 |
| `Trips.turnover_details` | PASS | UI handler + suites: selected_turnover, turnover | 400 |
| `Trips.turnover_endorse` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover | 400 |
| `Trips.turnover_list` | PASS | UI handler + suites: selected_turnover, turnover | OK |
| `Trips.turnover_preview` | PASS | UI handler + suites: account_guard, planning_account, pricing, selected_turnover, turnover | OK |

## Container/Wing Van Dispatcher (Night)

Pages (server navigation): dashboard, trips, trucks, crew, clients, funds, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_history` | PASS | UI handler + suites: uat_gap | OK |
| `Attendance.dispatcher_list` | NOT APPLICABLE | roster served by Fleet page data (view=attendance) | OK |
| `Attendance.time_in` | PASS | UI handler + suites: _launcher, dispatch_attendance, dispatcher, operations_v43, pricing, turnover + browser | 400 |
| `Attendance.time_out` | PASS | UI handler + suites: dispatcher, turnover | 400 |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Fleet.crew_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_details` | PASS | UI handler + suites: dispatcher | OK |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.selectable` | PASS | UI handler + suites: trip_routing | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 409 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | 404 |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.progress` | PASS | UI handler + suites: turnover | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |
| `Trips.turnover_accept` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover, turnover_concurrency_worker | 400 |
| `Trips.turnover_details` | PASS | UI handler + suites: selected_turnover, turnover | 400 |
| `Trips.turnover_endorse` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover | 400 |
| `Trips.turnover_list` | PASS | UI handler + suites: selected_turnover, turnover | OK |
| `Trips.turnover_preview` | PASS | UI handler + suites: account_guard, planning_account, pricing, selected_turnover, turnover | OK |

## Container/Wing Van Dispatcher (Day)

Pages (server navigation): dashboard, trips, trucks, crew, clients, funds, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | OK |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_history` | PASS | UI handler + suites: uat_gap | OK |
| `Attendance.dispatcher_list` | NOT APPLICABLE | roster served by Fleet page data (view=attendance) | OK |
| `Attendance.time_in` | PASS | UI handler + suites: _launcher, dispatch_attendance, dispatcher, operations_v43, pricing, turnover + browser | 400 |
| `Attendance.time_out` | PASS | UI handler + suites: dispatcher, turnover | 400 |
| `Clients.details` | PASS | UI handler + suites: route, uat_gap | OK |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Fleet.crew_details` | PASS | UI handler + suites: dispatcher | OK |
| `Fleet.truck_details` | PASS | UI handler + suites: dispatcher | OK |
| `Funds.details` | PASS | UI handler + suites: dispatcher, planning_dispatcher | OK |
| `Funds.submit` | PASS | UI handler + suites: dispatch_attendance, dispatcher, fund_release, incident_clearance, integration_workflow, operations_v43, planning_dispatcher, route, trip_schedule, uat_browser_scenario | 409 |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.selectable` | PASS | UI handler + suites: trip_routing | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.assign_resources` | PASS | UI handler + suites: _launcher, dispatcher, operations_v43, operations_v43_worker, planning_dispatcher, pricing, turnover + browser | 409 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 409 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | OK |
| `Trips.diesel_po` | PASS | UI handler + suites: operations_v43, trip_documents | 400 |
| `Trips.dispatch` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, pricing, route, trip_approval, trip_routing, trip_schedule, uat_browser_scenario | 409 |
| `Trips.dispatcher_resources` | PASS | UI handler + suites: dispatcher, operations_v43, planning_account, planning_dispatcher, turnover | OK |
| `Trips.edit` | PASS | UI handler + suites: trip_approval, trip_routing, trip_schedule | OK |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.progress` | PASS | UI handler + suites: turnover | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.resume` | PASS | UI handler + suites: incident_clearance, operations_v43 + browser | 409 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |
| `Trips.turnover_accept` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover, turnover_concurrency_worker | 400 |
| `Trips.turnover_details` | PASS | UI handler + suites: selected_turnover, turnover | 400 |
| `Trips.turnover_endorse` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover | 400 |
| `Trips.turnover_list` | PASS | UI handler + suites: selected_turnover, turnover | OK |
| `Trips.turnover_preview` | PASS | UI handler + suites: account_guard, planning_account, pricing, selected_turnover, turnover | OK |

## Car Carrier Dispatcher (Day)

Pages (server navigation): dashboard, trips, trucks, crew, clients, funds, documents, notifications

| Action | Status | Evidence | Probe |
|---|---|---|---|
| `Admin.change_password` | BLOCKED BY POLICY | replaced by verified password change | 409 |
| `Admin.notifications_read` | PASS | UI handler + suites: uat_gap | 404 |
| `Admin.recovery_code_create` | BLOCKED BY POLICY | recovery codes disabled | 404 |
| `Admin.signature_upload` | PASS | UI handler + suites: uat_gap | 400 |
| `Attendance.dispatcher_list` | NOT APPLICABLE | roster served by Fleet page data (view=attendance) | OK |
| `Attendance.time_in` | PASS | UI handler + suites: _launcher, dispatch_attendance, dispatcher, operations_v43, pricing, turnover + browser | 400 |
| `Attendance.time_out` | PASS | UI handler + suites: dispatcher, turnover | 400 |
| `Clients.rate_save` | BLOCKED BY POLICY | client-level rates retired (route rates) | 409 |
| `Mechanics.availability` | NOT APPLICABLE | refused (403) by design | 403 |
| `Purchasing.adjust` | BLOCKED BY POLICY | manual stock adjustment retired (stock counts reviewed by Admin) | 403 |
| `Purchasing.my_requests` | NOT APPLICABLE | refused (403) by design | 403 |
| `Routes.for_client` | PASS | UI handler + suites: route | OK |
| `Routes.selectable` | PASS | UI handler + suites: trip_routing | OK |
| `Trips.arrival` | PASS | UI handler + suites: integration_workflow, operations_v43, turnover, uat_browser_scenario | 400 |
| `Trips.auto_assign` | BLOCKED BY POLICY | automatic allocation retired (kenneth V4.3) | 409 |
| `Trips.checklist` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.checklist_start` | PASS | UI handler + suites: dispatch_attendance, fund_release, incident_clearance, integration_workflow, operations_v43, route, trip_schedule, uat_browser_scenario | 400 |
| `Trips.complete` | PASS | UI handler + suites: integration_workflow, operations_v43, pricing, trip_documents, trip_routing, trip_schedule, turnover, uat_browser_scenario | 400 |
| `Trips.create` | NOT APPLICABLE | refused (403) by design | 403 |
| `Trips.crew_options` | BLOCKED BY POLICY | served the retired reassign screen | 403 |
| `Trips.details` | PASS | UI handler + suites: crew_attendance, dispatcher, incident_clearance, planning_dispatcher, trip_documents, trip_routing, trip_schedule | 404 |
| `Trips.incident` | PASS | UI handler + suites: incident_clearance, integration_workflow, maintenance_overview, operations_v43, trip_schedule, uat_browser_scenario + browser | 400 |
| `Trips.progress` | PASS | UI handler + suites: turnover | 400 |
| `Trips.reassign` | BLOCKED BY POLICY | Ops Head truck/driver override retired (kenneth) | 403 |
| `Trips.reschedule` | PASS | UI handler + suites: incident_clearance, operations_v43, trip_schedule | 400 |
| `Trips.review` | BLOCKED BY POLICY | Dispatcher request review retired (kenneth) | 403 |
| `Trips.turnover_accept` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover, turnover_concurrency_worker | 400 |
| `Trips.turnover_details` | PASS | UI handler + suites: selected_turnover, turnover | 400 |
| `Trips.turnover_endorse` | PASS | UI handler + suites: account_guard, account_guard_worker, planning_account, pricing, selected_turnover, turnover | 400 |
| `Trips.turnover_list` | PASS | UI handler + suites: selected_turnover, turnover | OK |
| `Trips.turnover_preview` | PASS | UI handler + suites: account_guard, planning_account, pricing, selected_turnover, turnover | OK |

## Summary per role

| Role | PASS | NOT TESTED | MISSING UI | BLOCKED BY POLICY | NOT APPLICABLE |
|---|---|---|---|---|---|
| Admin / Head Management | 52 | 0 | 0 | 8 | 68 |
| Operations Head | 41 | 0 | 0 | 8 | 79 |
| Finance | 25 | 0 | 0 | 8 | 95 |
| Billing | 23 | 0 | 0 | 8 | 97 |
| Motorpool Custodian (also records mechanics: mechanics have no accounts) | 34 | 0 | 0 | 8 | 86 |
| Purchasing Officer | 18 | 0 | 0 | 8 | 102 |
| Audit | 25 | 0 | 0 | 8 | 95 |
| Car Carrier Dispatcher (Night) | 19 | 0 | 0 | 8 | 101 |
| Container/Wing Van Dispatcher (Night) | 23 | 0 | 0 | 8 | 97 |
| Container/Wing Van Dispatcher (Day) | 31 | 0 | 0 | 8 | 89 |
| Car Carrier Dispatcher (Day) | 19 | 0 | 0 | 8 | 101 |
