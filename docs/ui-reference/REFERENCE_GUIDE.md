# MULAWIN FleetOps — Maintenance Wireframe Reference

## How Claude Code should use these files

Open `maintenance_reference.html` locally in a browser. It is a standalone, interactive reference for Motorpool and Purchasing only; it is not the running PHP application. Interact with both workspaces, pages, tabs, record details, action previews, and Reports suggestions. Compare these visual/interaction patterns to the live application's implemented functionality. Apply the design to the existing PHP/JS/MySQL repository—not by embedding this demo as the production app.

## Essential interactions

- **Switch workspace:** top-right `Switch workspace` alternates Motorpool and Purchasing (demo only; real app uses server role permissions).
- **Sidebar:** direct navigation links, active page highlight, no `My Workspace` or `More Tools`. Profile/account menu stays in **top navigation**.
- **Overview:** KPI cards route to related pages. Quick Actions below KPIs. Left panels are **Needs Attention** and **Recent Activity**; right are Motorpool **Mechanic Status**, **Trucks in Repair** / Purchasing **Purchase Order Status**, **Inventory Alerts**. Panels show short scrollable lists.
- **Transactional lists:** readable 14px text; close-spaced content-aware table columns; compact search/status tabs and Reset; record click opens details; contextual icon opens a relevant action preview. No unnecessary list/card toggle.
- **Related-record navigation:** Stock Movements → linked Purchase Order, without stale drawers appearing later.
- **Reports:** distinct Motorpool and Purchasing pages, each with four or fewer KPIs, a main visual, a ranking, a short summary, and context-specific lightbulb suggestions. No separate formula/explanation table or synthetic predictions.
- **Mobile:** responsive layout, without shrinking text unreadably.

## Data and workflow cautions

All labels and rows in the HTML prototype are invented demonstration placeholders. Do **not** copy them into the actual repository/database. The production system must use real, authorized data, original statuses, backend permissions, and actual linked actions. `—` KPI figures in the demo intentionally avoid suggesting real totals. Example charts/rankings are not analytics results.

The prototype only demonstrates visual and navigation behavior. It does **not** implement backend approval, mechanic assignment, PO receiving, stock reconciliation, finance flows, or the full role-permission model. Keep these legitimate capabilities in the actual application even when they aren't present in the demo. Do not import demo behavior that conflicts with proper server authorization or stock controls.

### Scope and constraints

Target branch `luis-maintenance-ui` in `C:\xampp\htdocs\RP_Mulawin_FMS_V2`. Motorpool and Purchasing only. Do not modify Operations-owned pages or code. Do not apply migrations, re-seed data, alter `mulawin_v2` business records, push, or merge without approval. Retain the actual top-nav account controls. Verify in the **actual role-authenticated localhost browser** before calling complete.
