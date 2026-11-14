# Head Management (Admin) — open coordination items

Branch `luis-maintenance-ui`. Head Management may monitor, decide the approvals the workflows assign to it,
manage users and roles, and read reports, analytics and the audit trail. It must not do departmental processing.

## For the Operations developer (Operations-owned code, not changed here)

The Admin role still has write access to Operations master data:

| File | Rule | Effect for the Admin |
|---|---|---|
| `backend/Modules/Operations/Fleet.php` | `private const EDIT = ['admin', 'operations_head'];` | can add / edit trucks and drivers & helpers |
| `backend/Modules/Operations/Clients.php` | `private const EDIT = ['admin', 'operations_head'];` | can add / edit clients |

Under the Head Management policy these are Operations tasks. Please decide whether the Admin should keep them; if not,
remove `'admin'` from both lists and update the Operations tests that expect it. Trips, dispatch and trip funds already
refuse the Admin (they require `operations_head` or a dispatcher role).

## Already enforced on the server

| Department work | Who can do it | Admin |
|---|---|---|
| Repairs, mechanic assignment | Motorpool (`Maintenance::ACT`) | refused |
| Mechanic masterlist, Time In / Time Out | Motorpool (`Mechanics::EDIT`, changed in this branch) | refused (read only) |
| Receiving POs, releasing parts, stock counts submit | Purchasing | refused |
| Stock adjustments | nobody directly (stock counts reviewed by the Admin) | posts / rejects counts only |
| PO payments, payables, budgets | Finance | refused |
| Billing, collections | Billing | refused (decides billing charge requests only) |

Admin decisions (Approvals page): purchase orders above the approval limit, stock counts, billing charge requests.
