# Mulawin FleetOps — Interactive Workspace UI Implementation Contract

**Purpose:** Implement a working, role-based browser UI in the existing `luis-maintenance-ui` branch, using current PHP/JS backend and FleetOps styling. This is **not an image or a standalone mockup**.

**Important fidelity note:** This contract reconstructs the previously discussed screen requirements and workflow. It is *not* an exported pixel-accurate copy of the prior ChatGPT wireframe. If exact spacing, typography, visual arrangement, or screenshots matter, get the original reference before claiming a pixel-perfect match. Do not invent extra modules, statuses, data fields, or backend actions.

## Safety and execution rules

1. Keep changes on `luis-maintenance-ui` and inspect `git status` before starting. Make a checkpoint if needed. Never merge or push without explicit permission.
2. **Diagnose why the current localhost UI appears unchanged before any redesign:** verify project root and served path, logged-in role, asset script paths, asset cache/versioning, JS module registration, backend page navigation/authorization, console errors, and actual local URL. Report findings.
3. Preserve all existing business rules, page access, accounting approval rules, inventory quantities, attendance validations, and notification routing. Reuse the actual `Actions` dispatcher and existing module actions; don't invent business logic or pretend a button works.
4. No changes, seeding, migrations, database resets, or destructive tests on `mulawin_v2`. Automated tests use only independently verified isolated `_test` databases. Show every proposed database command for approval.
5. No new framework or unrelated stylistic overhaul. Use existing FleetOps design tokens, CSS utilities, reusable core UI helpers, forms/tables/modals/drawers, and responsive patterns. No unrelated pages or decorative elements.
6. Implement **one stage at a time** with manual approval of edits. Do not treat completed syntax checks as UI functionality verification.

## Interaction contract — Motorpool Custodian

Keep these pages separate and navigable in the Motorpool sidebar, with working page headers, clear active state, back links, loading/empty/error states, contextual status indicators, and permission-aware actions:

| Screen | Required visible sections | Working interactions / boundaries |
| --- | --- | --- |
| Maintenance Overview | Clickable KPIs for actual maintenance states, current repair/issue queues, pending parts requests, mechanic status and recent activity | Each KPI navigates to a correctly filtered **authorized** screen. Links to Repairs, Parts Requests and Mechanic Attendance must work. |
| Repair Management | Real reported issues, trucks, assigned mechanics, repair status and details | Existing report, inspect, assign, repair, verify and return-to-service flows only where authorized. Parts request creation remains linked to correct repair/truck. |
| Parts Requests (`mp_parts_requests`) | Status KPIs, open/released/cancelled lists, request reference, linked truck and job, items, requested/released units, status, Purchasing notes | Working create, details, cancel-only-when-allowed, filter by status (`open`, `requested`, `partially_released`, `not_available`, `released`, `cancelled`) and `vehicle_id`. Purchasing controls release; no Motorpool supplier/unit-cost/stock-on-hand/budget/PO data. |
| Mechanic Masterlist | Existing list, availability and current assignment | Preserve existing create/update and availability rules. Filters and navigation must work. |
| Mechanic Attendance (`mechanic_attendance`) | On-duty/Available/Assigned/Unavailable KPIs, live attendance board, current repair, Time In/Out session history | Existing Time In/Out actions for Admin/Motorpool only, duty filter, from/to date range (backend max 62 days), server-side forbidden actions for other roles. Daily Present/Absent detail Admin only. |
| Maintenance History | Completed repairs and return-to-service references | Existing history visibility and read-only details; contextual links only if authorized. |

Motorpool must not gain purchasing processing, inventory stock movement, PO approval or accounting payment permissions.

## Interaction contract — Purchasing Officer

Provide a **distinct workspace** with the existing authorized pages, real data, and relevant contextual links:

| Screen | Required visible sections | Working interactions / boundaries |
| --- | --- | --- |
| Purchasing Overview | KPIs and actionable queues for incoming parts requests, POs awaiting approval, inventory attention and purchasing activity | Clicking KPIs opens authorized, correctly filtered records. Clearly distinguish actions available to Purchasing from actions requiring Finance/Admin. |
| Parts Requests (`parts_requests`) | Incoming Motorpool requests with truck/repair context, request lines, status and fulfillment progress | Authorized Purchasing review, partial/full release or unavailable handling through existing actions; notification back to Motorpool. |
| Purchase Orders (`purchase_orders`) | PO list/status, linked request, supplier and item details, approval state | Use existing create/submit/review actions; approval belongs only to authorized Finance/Admin and threshold rules. No fake approval button for Purchasing. |
| Inventory (`inventory`) | Existing part catalog, availability, low-stock indicators and applicable quantities | Existing inventory transactions only; no inferred values. |
| Stock Movements (`stock_movements`) | Chronological receipts/releases and references | Real records and links to originating actions where authorized. |
| Purchasing Reports (`purchasing_reports`) | Existing purchasing operational reporting views | Preserve actual reporting data and role boundaries. |

## Cross-department workflow

Expose navigable context and statuses for the **existing** supported path:

Operations incident/truck problem -> Motorpool inspection and mechanic assignment -> Motorpool parts request -> Purchasing review/stock release or PO -> Finance/Admin PO approval (according to existing thresholds) -> authorized receipt / stock movement -> Motorpool repair completion -> authorized verification / return to service -> Operations vehicle availability.

**Do not invent status names or new backend transitions.** For each transition, provide the current actor, next authorized actor, record reference and clear next step when supported by real data. Accounting payment integration can only be marked completed if implemented and tested; do not claim it from PO notification tests.

## UI fidelity and functional acceptance

- Visibly differentiated, independently navigable Motorpool and Purchasing dashboards while remaining in the existing FleetOps visual language.
- A consistent hierarchy: page title/context, status KPIs, actionable queue(s), real data tables, details/form modal or drawer, and relevant cross-links. Use the same established component styling across roles.
- All clickable controls have real action handlers and backend authorization, or are clearly non-interactive read-only values. Avoid dead controls and duplicated data-entry paths.
- Filters, sort if supported, empty states, loading states, errors, confirmation prompts, and success feedback work on the actual app, not merely in code snippets.
- Responsive desktop and mobile behavior using current app's CSS system; preserve keyboard accessibility and readable labels.
- Browser validation must show the **actual new UI** for both Motorpool and Purchasing role accounts at the exact correct localhost URL. Verify hard refresh / cache-busting; inspect JS console and network load.
- Verify navigation and actions by role, including Admin, Operations Head, Audit and Finance where applicable. No unauthorized data appears in API payloads.
- Keep `mulawin_v2` unchanged during automated testing. Browser manual tests that could write to it require explicit user approval and backup first.

## Staged deliverables

**Stage 0 — Audit and screenshot/reference gap:** Inspect the actual app, page registry, loading, existing wireframe source (if present), and UI components. Produce a page-by-page gap matrix and exact files to modify. Ask for the original interactive wireframe artifact only if pixel-accurate reproduction is required; otherwise use this behavioral contract and existing visual design.

**Stage A — Motorpool UI:** Implement the actual visible Motorpool dashboard and associated pages. Prove browser functionality and role access. Stop for review.

**Stage B — Purchasing UI:** Implement its separate dashboard and related pages. Prove browser functionality and role access. Stop for review.

**Stage C — Workflow navigation and end-to-end verification:** Connect the cross-role references and notifications as appropriate, run isolated integration/regression tests, and provide manual browser test evidence. Stop for review.

**For every stage report:** changed files, before/after behavior, exact local route, visible UI elements and actions, role matrix, lint/regression results, screenshots or explicit browser observations if a browser is available, unresolved issues, and a local Git checkpoint. Never claim pixel-perfect fidelity without the original wireframe reference.

## First task Claude must do now

Do not begin implementation yet. Read this contract and `docs/MOTORPOOL_PHASE1_HANDOFF.md` (if present). Show the **current versus required UI gap matrix**, identify why the user sees the old localhost screen, list the exact existing modules/UI helpers/actions to reuse, and propose the **smallest Stage A implementation plan** for manual approval. Keep the existing regression/launcher fixes intact, but do not allow unfinished regression testing to be mistaken for UI delivery.
