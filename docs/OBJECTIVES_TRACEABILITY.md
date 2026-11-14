# Mulawin FleetOps — Capstone objectives and acceptance tracker

**Status legend:** UNVERIFIED | VERIFIED | PARTIAL | BROKEN | MISSING | BLOCKED. **All rows default to UNVERIFIED** until actual code + role-based browser/workflow evidence is recorded. This is not a claim that functionality is missing.

| ID | Required capability | Suggested functional acceptance evidence | Status | Evidence / owner |
|---|---|---|---|---|
| 1.1 | Trucks, vehicle documents, driver/helper assignment, availability | Create/update records; verify document access and assignment conflict refusal | UNVERIFIED | |
| 1.2 | Clients, accreditations, approved routes, rates | Open client and approved route with valid document and rate | UNVERIFIED | |
| 1.3 | Submit route for rate setting/approval | Requested → authorized approval → usable rate | UNVERIFIED | |
| 1.4 | Delivery details, schedules, approvals, route selection | Schedule with only approved route/rate and show approval lifecycle | UNVERIFIED | |
| 1.5 | Assign available trucks, drivers, helpers | Block double booking/unavailable assets | UNVERIFIED | |
| 1.6 | Dispatcher fund and fuel allowance requests | Submit → review → approve/release with correct role | UNVERIFIED | |
| 1.7 | Pre-dispatch and arrival checklists | Capture both checklists, photos/timestamps where required | UNVERIFIED | |
| 1.8 | Dispatch, progress, delivery, documents | Trace one trip through delivery with documents | UNVERIFIED | |
| 1.9 | Incidents, damage, location, photos | Incident creates linked evidence and appropriate hold | UNVERIFIED | |
| 1.10 | Incident repair, hold, rescheduling | Hold → repair → verification → rescheduling/resumption | UNVERIFIED | |
| 1.11 | Driver/helper attendance | Time-in/out, history, permission refusal | UNVERIFIED | |
| 2.1 | Findings, problems, repairs, parts, dates, costs, documents | Linked repair record with parts/cost/evidence | UNVERIFIED | |
| 2.2 | Incident repair and truck availability | Incident links to repair; truck unavailable/returned via verified state | UNVERIFIED | |
| 2.3 | Inspection, repair, completion, verification | Authorized state transitions; failure returns appropriately | UNVERIFIED | |
| 2.4 | Parts requests, inventory checks/releases, stock updates | Request → release and correct stock movement | UNVERIFIED | |
| 2.5 | Purchase requests, PO approval, receiving | Shortage → linked PO → approval → delivery/receipt | UNVERIFIED | |
| 2.6 | Monitoring repairs, history, movements, purchases | Filters, record drill-down, accurate KPIs | UNVERIFIED | |
| 2.7 | Mechanic attendance | Time-in/out, current duty, assignment and session history | UNVERIFIED | |
| 3.1 | Client approved routes | Finance sees authorized rates/routes; changes controlled | UNVERIFIED | |
| 3.2 | Finance review/approval of fuel POs | Fuel PO approval path with traceable role, outcome | UNVERIFIED | |
| 3.3 | Trip funds, allowances, PO payments, AP, disbursements | Approved requests → vouchers/payments, balance reconciliation | UNVERIFIED | |
| 3.4 | Completed-trip billing | Eligible completed trip generates correct invoice/charges | UNVERIFIED | |
| 3.5 | Fuel POs + SOAs in financial records | Linked documents visible after trip completion | UNVERIFIED | |
| 3.6 | Collections, outstanding, AR | Partial/full receipts, correct aging and open balances | UNVERIFIED | |
| 3.7 | Purchasing budgets/payments/documents/statuses | Budget available/reserved/spent invariant; PO/payment trace | UNVERIFIED | |
| 4.1 | Users/roles | Admin manages accounts; server rejects unauthorized actions | UNVERIFIED | |
| 4.2 | Driver/helper/mechanic masterlists | CRUD rules, status and assignment effects | UNVERIFIED | |
| 4.3 | Clients/accreditations/routes/rates | Maintain documents and control route approval | UNVERIFIED | |
| 4.4 | Audit trails | Created/modified/approved/processed events with actor and time | UNVERIFIED | |
| 4.5 | Consolidated management summaries | Cross-domain counts reconcile to detail lists | UNVERIFIED | |
| 5.1.1 | Trip counts/statuses/units/incidents | Time-filtered data and definitions reconcile to trips | UNVERIFIED | |
| 5.1.2 | Repairs, downtime, expenses | Downtime definition and cost breakdown verified | UNVERIFIED | |
| 5.1.3 | Billing, collections, operating expenses | Reconciled figures with clear date/status basis | UNVERIFIED | |
| 5.1.4 | Stock-in/out/adjustments/value | Movements reconcile to quantity/value calculation | UNVERIFIED | |
| 5.2.1 | Trip volume/incidents by people/assets/client | Counts and incident rates with low-sample warnings | UNVERIFIED | |
| 5.2.2 | Highest truck downtime/maintenance costs | Ranking drill-down with cost categories and period | UNVERIFIED | |
| 5.2.3 | AR aging | Due date, as-of date, partial payments and overdue buckets | UNVERIFIED | |
| 5.2.4 | Frequent parts | Quantities, values, periods, link to stock movements | UNVERIFIED | |

## Release gates

- **R1 — Workflow integrity:** prove the 5 major cross-department acceptance scenarios in AGENTS.md, including negative role/validation tests.
- **R2 — Financial correctness:** independently reconcile budgets, AP, AR, disbursements, invoice totals, stock movements, and audit records.
- **R3 — Migrated schema:** review pending migrations, back up, verify restore, apply with separate approval, prove old and new data work.
- **R4 — Analytics correctness:** every KPI has denominator, source, interval, amount/status definition and drill-down; insufficient data is labeled honestly.
- **R5 — UX readiness:** all authorized screens and controls usable at desktop/mobile, loading/error/empty states, no console errors.
- **R6 — Integration:** branch compatibility matrix, no unexpected file/schema overlap, no secret commits, approved integration merge, complete regression sweep.

## First execution focus

1. Take a focused inventory of already implemented objectives and ownership in Maintenance, Purchasing, Accounting and Operations; mark VERIFIED only with evidence. Do not spend time on unrelated files.
2. Fix the first **P0 end-to-end broken workflow**, prioritized by production impact. Prefer payments/stock/availability and billing/collection integrity over cosmetic polish.
3. For each completed slice update the relevant rows, log changed files, command/test evidence, and remaining dependency. Then tackle the next broken objective.
4. Build analytics from actual verified business events. Treat Random Forest/regression prediction as an optional, separately evaluated feature, **not** as an excuse to invent historical accuracy.
