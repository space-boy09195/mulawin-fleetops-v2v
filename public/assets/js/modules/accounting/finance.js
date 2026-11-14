/* assets/js/modules/accounting/finance.js — FINANCE WORKSPACE (FINANCE_WORKSPACE_V1)
   finance_overview  what is waiting for Finance now (approvals, fund releases, payments due, budget)
   finance           Payables & Payments: PO payables, other payables, payment vouchers (page id kept for notifications)
   finance_budgets   Budgets & Expenses: purchasing budget, expenses by category / source, cash flow
   Approvals = Purchase Orders page (purchasing.js); trip fund releases = Trip Funds page (funds.js).
   Backend: backend/Modules/Accounting/Finance.php (PO approval: Purchasing.php po_decide) */
'use strict';
const FinUI = {
  /** Finance-side kit for the shared PO drawer (approve / reject only; Finance never receives or edits POs). */
  poKit: () => typeof PurUI !== 'undefined' ? PurUI.kit({ parts: [] }, { stock: false, approveSmall: UI.role('finance'), approveBig: UI.role('admin') }) : null,
  openPO(id) { const k = FinUI.poKit(); if (k) PurUI.openPO({ id }, k); },
  overdue: (due, today) => due && due < today,
  payPO(d, o) {
    UI.formModalFiles('Record payment · ' + UI.esc(o.po_ref), [
      { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6, value: Math.max(0, +o.received_value - +o.paid_amount).toFixed(2) },
      { name: 'supplier_invoice_no', label: 'Supplier invoice number', required: true, col: 6, value: o.supplier_invoice_no },
      ...UI.payFields(d.methods),
      { name: 'or_doc', label: 'Official receipt / payment confirmation (required)', type: 'file', required: true, col: 12, attrs: 'accept="application/pdf,image/*"' }],
      'Finance.po_pay', { extra: { po_id: o.id }, submit: 'Record payment',
        intro: `Check the supplier invoice against the approved PO and the received items. PO total ${UI.money(o.total_amount)}; received ${UI.money(o.received_value)}; already paid ${UI.money(o.paid_amount)}. Only the received value can be paid.` });
  },
  payPayable(d, p) {
    UI.formModalFiles('Record payment · ' + UI.esc(p.payable_ref), [{ name: 'amount', label: 'Amount', type: 'money', value: p.balance, required: true, col: 12 }, ...UI.payFields(d.methods),
      { name: 'or_doc', label: 'Receipt (optional)', type: 'file', col: 12 }], 'Finance.payable_pay', { extra: { payable_id: p.id }, submit: 'Record payment',
      intro: `${UI.esc(p.payee)} · balance ${UI.money(p.balance)} of ${UI.money(p.amount)}${p.due_on ? ' · due ' + UI.date(p.due_on) : ''}.` });
  },
  addPayable(d) {
    UI.formModalFiles('Add payable', [
      { name: 'payee', label: 'Payee name', required: true, col: 12 },
      { name: 'category', label: 'Category', type: 'select', options: UI.list(d.categories), required: true, col: 6 }, { name: 'vehicle_id', label: 'Truck (optional)', type: 'select', options: UI.opts(d.trucks, v => v.plate_number), col: 6 },
      { name: 'description', label: 'Description', required: true, col: 12 }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6 }, { name: 'due_on', label: 'Due date', type: 'date', col: 6 },
      { name: 'bill', label: 'Bill / invoice file (optional)', type: 'file', col: 12 }], 'Finance.payable_add', { intro: 'Bills that do not come from a purchase order (utilities, outside services, …).' });
  },
  addExpense(d) {
    UI.formModalFiles('Add expense', [
      { name: 'category', label: 'Category', type: 'select', options: UI.list(d.categories), required: true, col: 6 }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6 },
      { name: 'incurred_on', label: 'Date', type: 'date', value: UI.today(), required: true, col: 6 }, { name: 'vehicle_id', label: 'Truck (optional)', type: 'select', options: UI.opts(d.trucks, v => v.plate_number), col: 6 },
      { name: 'payee', label: 'Payee', col: 6 }, { name: 'reference_number', label: 'Reference / OR no.', col: 6 }, { name: 'description', label: 'Description', required: true, col: 12 },
      { name: 'receipt', label: 'Receipt (optional)', type: 'file', col: 12 }], 'Finance.expense_add',
      { intro: 'Fund releases, PO payments, payables and repair costs are recorded automatically. Add only other expenses here (fuel, office, utilities …).' });
  },
  setBudget(d) {
    const b = (d.budgets || []).find(x => x.status === 'Active');
    UI.formModal('Set purchasing budget', [
      { name: 'amount', label: 'Budget amount', type: 'money', required: true, col: 12 }, { name: 'period_start', label: 'Start date', type: 'date', value: UI.today(), required: true, col: 6 },
      { name: 'period_end', label: 'End date', type: 'date', required: true, col: 6 }], 'Finance.budget_set',
      { intro: 'The current active budget is closed. Approved purchase orders that are not fully paid move to the new budget with their reserved amount' + (b ? ` (now ${UI.money(b.reserved_amount)})` : '') + ', so the new amount must cover it.' });
  },
  /** Voucher drawer: who was paid, how, and the source record. */
  openVoucher(v) {
    const src = { fund_request: 'Trip fund', purchase_order: 'Purchase order', payable: 'Payable' }[v.source_type] || v.source_type;
    const go = v.source_type === 'purchase_order' ? 'po' : v.source_type === 'fund_request' && UI.canGo('funds') ? 'funds' : '';
    const el = UI.drawer('Payment voucher ' + UI.esc(v.voucher_number), UI.kv([['Amount', `<b>${UI.money(v.amount)}</b>`], ['Released on', UI.date(v.released_on)], ['Payee', UI.e(v.payee)],
      ['Paid for', UI.esc(src) + (v.source_ref ? ' · <b>' + UI.esc(v.source_ref) + '</b>' : '')], ['Method', UI.e(v.payment_method)],
      v.cheque_number ? ['Cheque', UI.esc(v.cheque_number) + (v.bank_name ? ' · ' + UI.esc(v.bank_name) : '')] : null, v.reference_number ? ['Reference', UI.esc(v.reference_number)] : null,
      ['Recorded by', UI.e(v.recorded_by_name)]]) + (go ? `<div class="mt-3"><button type="button" class="btn btn-sm btn-outline-primary" data-src><i class="bi bi-box-arrow-up-right"></i> Open ${UI.esc(src.toLowerCase())}</button></div>` : ''),
      { subtitle: src });
    el.querySelector('[data-src]')?.addEventListener('click', () => { if (go === 'po') FinUI.openPO(v.source_id); else { UI.closeDrawer(); App.go('funds'); } });
  },
  period(ctx, d) {
    return `<div class="ws-filters no-print" role="group" aria-label="Period"><span class="small text-muted">Period</span>
      <input type="date" class="form-control form-control-sm" data-gf="from" value="${UI.esc(d.period.from)}" aria-label="Start date" title="Start date">
      <input type="date" class="form-control form-control-sm" data-gf="to" value="${UI.esc(d.period.to)}" aria-label="End date" title="End date">
      ${ctx.query.from || ctx.query.to ? '<button type="button" class="btn btn-link btn-sm ws-filter-reset" data-gf-reset="from,to"><i class="bi bi-x-circle"></i> This month</button>' : '<span class="small text-muted">(this month)</span>'}</div>`;
  },
};

// ---------------------------------------------------------------- Finance Overview
App.page('finance_overview', {
  title: 'Finance Overview',
  subtitle: 'What is waiting for Finance: approvals, fund releases, payments due and the purchasing budget.',
  render(ctx) {
    const d = ctx.data, c = d.counts || {}, b = d.budget || {}, today = d.today;
    const tiles = [
      { label: 'POs to approve', value: c.po_approvals || 0, note: 'routed to Finance by the approval limit', icon: 'bi-check2-square', tone: c.po_approvals ? 'amber' : 'gray', go: 'purchase_orders?tab=approval' },
      { label: 'Trip funds to release', value: +(c.funds_ready?.n || 0), note: UI.money(c.funds_ready?.total || 0) + ' approved by the Operations Head', icon: 'bi-cash-coin', tone: +(c.funds_ready?.n || 0) ? 'amber' : 'gray', go: 'funds' },
      { label: 'Funds to liquidate', value: c.funds_to_liquidate || 0, note: 'released, trip finished', icon: 'bi-receipt-cutoff', tone: c.funds_to_liquidate ? 'blue' : 'gray', go: 'funds' },
      { label: 'PO payments due', value: UI.money(c.po_due?.total || 0), note: (+c.po_due?.n || 0) + ' PO(s): received, not yet paid', icon: 'bi-cart-check', tone: +(c.po_due?.n || 0) ? 'amber' : 'gray', go: 'finance?tab=po' },
      { label: 'Other payables', value: UI.money(c.ap_due?.total || 0), note: (+c.ap_due?.n || 0) + ' unpaid' + (+c.ap_due?.overdue ? ' · ' + c.ap_due.overdue + ' overdue' : ''), icon: 'bi-receipt', tone: +(c.ap_due?.overdue || 0) ? 'red' : (+(c.ap_due?.n || 0) ? 'amber' : 'gray'), go: 'finance?tab=ap' },
      { label: 'Cash out this month', value: UI.money(c.cash_out_month || 0), note: 'payment vouchers released', icon: 'bi-box-arrow-up-right', tone: 'blue', go: 'finance?tab=vouchers' },
    ];
    const item = (go, top, sub, side, data = '') => `<li class="mo-item clickable" ${go ? `data-go="${UI.esc(go)}"` : data} role="link" tabindex="0"><div class="mo-item-main"><div class="mo-item-top">${top}</div>${sub ? `<div class="mo-item-issue">${sub}</div>` : ''}</div><div class="mo-item-side">${side}</div></li>`;
    const list = (title, icon, items, empty, more) => `<section class="panel"><div class="panel-head"><h2 class="panel-title"><i class="bi ${icon} me-1" aria-hidden="true"></i>${UI.esc(title)} <span class="text-muted fw-normal">(${items.length})</span></h2>${more && UI.canGo(more) ? `<div class="panel-tools"><button type="button" class="btn btn-link btn-sm p-0" data-go="${UI.esc(more)}">View all</button></div>` : ''}</div>
      <div class="panel-body flush">${items.length ? `<ul class="mo-list mo-scroll">${items.join('')}</ul>` : `<div class="mo-empty">${UI.esc(empty)}</div>`}</div></section>`;
    const approvals = (d.approvals || []).map(o => item('', `<b>${UI.esc(o.po_ref)}</b> <span class="mo-ref">${UI.esc(o.supplier || '')}</span>`, 'Waiting since ' + UI.date(o.created_at), `<b>${UI.money(o.total_amount)}</b>`, `data-po="${+o.id}"`));
    const funds = (d.fundsReady || []).map(f => item('funds', `<b>${UI.esc(f.fund_ref)}</b> <span class="mo-ref">${UI.esc(f.trip_ref)} · ${UI.esc(f.client)}</span>`, 'Trip scheduled ' + UI.datetime(f.scheduled_at), `<b>${UI.money(f.amount)}</b>`));
    const dues = (d.paymentsDue || []).map(x => item(x.kind === 'po' ? '' : 'finance?tab=ap', `<b>${UI.esc(x.ref)}</b> <span class="mo-ref">${UI.esc(x.payee || '')}</span>`,
      x.kind === 'po' ? 'Purchase order · received items not yet paid' : 'Payable' + (x.due_on ? ' · due ' + UI.date(x.due_on) : ' · no due date'),
      `<b>${UI.money(x.due)}</b>${FinUI.overdue(x.due_on, today) ? ' <span class="badge-s red">Overdue</span>' : ''}`, x.kind === 'po' ? `data-po="${+x.id}"` : ''));
    const used = b.id ? +b.spent_amount + +b.reserved_amount : 0, pct = (v) => b.id && +b.amount ? Math.min(100, v / b.amount * 100).toFixed(1) : 0;
    const budget = `<section class="panel"><div class="panel-head"><h2 class="panel-title"><i class="bi bi-piggy-bank me-1" aria-hidden="true"></i>Purchasing budget</h2>${UI.canGo('finance_budgets') ? '<div class="panel-tools"><button type="button" class="btn btn-link btn-sm p-0" data-go="finance_budgets">Budgets & Expenses</button></div>' : ''}</div><div class="panel-body">
      ${b.id ? `<div class="d-flex justify-content-between flex-wrap gap-1"><span><b>${UI.money(b.available)}</b> available</span><span class="text-muted small">${UI.date(b.period_start)} – ${UI.date(b.period_end)}</span></div>
        <div class="ws-budget-meter" role="img" aria-label="Paid ${pct(b.spent_amount)} percent, reserved ${pct(b.reserved_amount)} percent"><span class="m-spent" style="width:${pct(b.spent_amount)}%"></span><span class="m-res" style="width:${pct(b.reserved_amount)}%"></span></div>
        <div class="ws-legend"><span><i style="background:var(--blue)"></i>Paid ${UI.money(b.spent_amount)}</span><span><i style="background:var(--amber)"></i>Reserved ${UI.money(b.reserved_amount)}</span><span>Budget ${UI.money(b.amount)}</span></div>
        ${!+b.in_period ? '<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">The budget period has ended: every new PO approval is rejected until you set a new budget.</div>' : ''}
        ${+b.amount && used / b.amount >= 0.8 ? `<div class="alert alert-warning py-1 px-2 mt-2 mb-0 small">Budget alert: ${Math.round(used / b.amount * 100)}% is paid or reserved (rule: alert at 80%).</div>` : ''}`
      : '<div class="text-muted">No active purchasing budget. Purchase orders are rejected until you set one.</div>'}
      <div class="ws-foot">Expenses this month: ${UI.money(c.expenses_month || 0)} (all sources).</div></div></section>`;
    ctx.el.innerHTML = `<div class="maint-overview mp-ws">${UI.kpiTiles(tiles)}
      ${UI.quickBar([{ label: 'Approve purchase orders', icon: 'bi-check2-square', go: 'purchase_orders?tab=approval' }, { label: 'Release trip funds', icon: 'bi-cash-coin', go: 'funds' },
        { label: 'Pay suppliers', icon: 'bi-wallet2', go: 'finance?tab=po' }, { label: 'Budgets & expenses', icon: 'bi-piggy-bank', go: 'finance_budgets' }])}
      <div class="mo-grid"><div class="mo-main">
        ${list('Purchase orders to approve', 'bi-check2-square', approvals, 'No purchase order is waiting for your approval.', 'purchase_orders?tab=approval')}
        ${list('Payments due', 'bi-cash-stack', dues, 'Nothing is waiting for payment.', 'finance')}
      </div><div class="mo-side">${budget}
        ${list('Trip funds to release', 'bi-cash-coin', funds, 'No approved trip fund is waiting for release.', 'funds')}
      </div></div></div>`;
    ctx.el.querySelectorAll('[data-po]').forEach(li => { li.onclick = () => FinUI.openPO(li.dataset.po); li.onkeydown = (e) => { if (e.key === 'Enter') FinUI.openPO(li.dataset.po); }; });
  },
});

// ---------------------------------------------------------------- Payables & Payments (page id 'finance')
const HeadFinancePage = {
  title: 'Finance',
  subtitle: 'Payables, payments, expenses, purchasing budget and cash flow.',
  render(ctx) {
    const d = ctx.data, act = d.can.act;
    const head = UI.role('admin');
    const b = d.budgets.find(x => x.status === 'Active');
    const poDue = d.poPayables.reduce((a, o) => a + Math.max(0, +o.received_value - +o.paid_amount), 0);
    const apDue = d.payables.filter(p => p.status !== 'Paid').reduce((a, p) => a + +p.balance, 0);
    ctx.el.innerHTML = UI.cards([
      { label: 'PO payables (received, unpaid)', value: poDue, format: 'money', icon: 'bi-cart-check', color: 'amber' },
      { label: 'Other payables', value: apDue, format: 'money', icon: 'bi-receipt', color: 'amber' },
      { label: 'Expenses in period', value: d.expenses.reduce((a, e) => a + +e.amount, 0), format: 'money', icon: 'bi-wallet2', color: 'red' },
      { label: 'Cash out in period', value: d.disbursements.reduce((a, e) => a + +e.amount, 0), format: 'money', icon: 'bi-box-arrow-up-right', color: 'red' },
      ...(!head ? [{ label: 'Budget available', value: b ? +b.available : 'No budget', format: b ? 'money' : '', icon: 'bi-piggy-bank', color: 'green', hint: b ? `Reserved ${UI.money(b.reserved_amount)} · spent ${UI.money(b.spent_amount)} of ${UI.money(b.amount)}` : 'Set a purchasing budget' }] : []),
    ]);
    const paymentAlerts = [
      {label:'Purchase orders awaiting payment',count:d.poPayables.filter(o=>+o.received_value>+o.paid_amount).length,go:act?'finance?tab=po':null},
      {label:'Other unpaid payables',count:d.payables.filter(p=>p.status!=='Paid'&&+p.balance>0).length,go:act?'finance?tab=ap':null}
    ];
    // Head Management sees the attention panel only when there is something to review.
    if (!head || paymentAlerts.some(item=>item.count>0)) {
      ctx.el.insertAdjacentHTML('beforeend', UI.actionSummary(
        head ? paymentAlerts.filter(item=>item.count>0) : paymentAlerts,{title:'Payment follow-up'}));
    }
    const period = UI.filterModal(ctx, [{ name: 'from', label: 'Start date', type: 'date', col: 6 }, { name: 'to', label: 'End date', type: 'date', col: 6 }], { hint: `Period: ${UI.date(d.period.from)} to ${UI.date(d.period.to)} (this month by default).` });
    const payPO = (o) => UI.formModalFiles('Pay ' + UI.esc(o.po_ref), [
      { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6, value: Math.max(0, +o.received_value - +o.paid_amount).toFixed(2) },
      { name: 'supplier_invoice_no', label: 'Supplier invoice number', required: true, col: 6, value: o.supplier_invoice_no },
      ...UI.payFields(d.methods),
      { name: 'or_doc', label: 'Official receipt / payment confirmation (required)', type: 'file', required: true, col: 12, attrs: 'accept="application/pdf,image/*"' }],
      'Finance.po_pay', { extra: { po_id: o.id }, submit: 'Record payment',
        intro: `Check the supplier invoice against the approved PO and the received items. PO total ${UI.money(o.total_amount)}; received ${UI.money(o.received_value)}; already paid ${UI.money(o.paid_amount)}. You can pay only what was received.` });
    const box = document.createElement('div');
    if (head) {
      ctx.el.classList.add('head-financial-overview');
      document.querySelector('#pageContent .page-title')?.replaceChildren(document.createTextNode('Financial Overview'));
      document.querySelector('#pageContent .page-subtitle')?.replaceChildren(document.createTextNode('Company-wide payables, expenses, payments and cash-flow monitoring.'));
      box.className = 'head-financial-records';
    }
    ctx.el.append(box);
    const financialTabs = [
      ['po', `PO payables (${d.poPayables.length})`, () => UI.table([['PO', 'po_ref'], ['Supplier', 'supplier'], ['Approved by', 'approved_by_name'], ['Status', 'status', 'badge'], ['Total', 'total_amount', 'money'],
        ['Received value', 'received_value', 'money'], ['Paid', 'paid_amount', 'money'], ['Payment', 'payment_status', 'badge']], d.poPayables,
        { title: 'Approved purchase orders (Pending Payment)', actions: (o) => UI.btn('details', 'View Details') + UI.btn('po', 'Print PO', 'outline-secondary') + (act && +o.received_value > +o.paid_amount ? UI.btn('pay', 'Pay', 'primary') : ''),
          onAction: (a, o) => a === 'pay' ? payPO(o) : a === 'details' ? PurUI.details(o.id) : PurUI.printPO(o), empty: 'No purchase order is waiting for payment.' })],
      ['ap', 'Other payables', (body) => {
        const h = UI.table([['Ref', 'payable_ref'], ['Payee', 'payee'], ['Category', 'category'], ['Truck', 'plate_number'], ['Description', 'description'], ['Due date', 'due_on', 'date'],
          ['Amount', 'amount', 'money'], ['Paid', 'paid_amount', 'money'], ['Balance', 'balance', 'money'], ['Status', 'status', 'badge']], d.payables,
          { title: 'Bills to pay (not from a purchase order)', actions: (p) => act && p.status !== 'Paid' ? UI.btn('pay', 'Pay', 'primary') : '',
            onAction: (a, p) => UI.formModalFiles('Pay ' + UI.esc(p.payable_ref), [{ name: 'amount', label: 'Amount', type: 'money', value: p.balance, required: true, col: 12 }, ...UI.payFields(d.methods),
              { name: 'or_doc', label: 'Receipt (optional)', type: 'file', col: 12 }], 'Finance.payable_pay', { extra: { payable_id: p.id } }),
            tools: act ? '<button class="btn btn-sm btn-primary" data-addap><i class="bi bi-plus"></i> Add payable</button>' : '' });
        setTimeout(() => body.querySelector('[data-addap]')?.addEventListener('click', () => UI.formModalFiles('Add payable', [
          { name: 'payee', label: 'Payee name', required: true, col: 12 },
          { name: 'category', label: 'Category', type: 'select', options: UI.list(d.categories), required: true, col: 6 }, { name: 'vehicle_id', label: 'Truck (optional)', type: 'select', options: UI.opts(d.trucks, v => v.plate_number), col: 6 },
          { name: 'description', label: 'Description', required: true, col: 12 }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6 }, { name: 'due_on', label: 'Due date', type: 'date', col: 6 },
          { name: 'bill', label: 'Bill / invoice file (optional)', type: 'file', col: 12 }], 'Finance.payable_add')), 0);
        return h;
      }],
      ['expenses', 'Expenses', (body) => {
        const h = period + UI.table([['Date', 'incurred_on', 'date'], ['Category', 'category'], ['Amount', 'amount', 'money'], ['Trip', 'trip_ref'], ['Payee', 'payee'], ['Reference', 'reference_number'],
          ['Description', 'description'], ['Source', 'source']], d.expenses, { title: 'Operating expenses (fund releases, PO payments, repairs and manual entries)',
            tools: act ? '<button class="btn btn-sm btn-primary" data-addexp><i class="bi bi-plus"></i> Add expense</button>' : '' });
        setTimeout(() => body.querySelector('[data-addexp]')?.addEventListener('click', () => UI.formModalFiles('Add expense', [
          { name: 'category', label: 'Category', type: 'select', options: UI.list(d.categories), required: true, col: 6 }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 6 },
          { name: 'incurred_on', label: 'Date', type: 'date', value: UI.today(), required: true, col: 6 }, { name: 'vehicle_id', label: 'Truck (optional)', type: 'select', options: UI.opts(d.trucks, v => v.plate_number), col: 6 },
          { name: 'payee', label: 'Payee', col: 6 }, { name: 'reference_number', label: 'Reference / OR no.', col: 6 }, { name: 'description', label: 'Description', required: true, col: 12 },
          { name: 'receipt', label: 'Receipt (optional)', type: 'file', col: 12 }], 'Finance.expense_add', { intro: 'Fund releases, PO payments and repair costs are recorded automatically; add only other expenses here (fuel, office, utilities ...).' })), 0);
        return h;
      }],
      ['disb', 'Disbursements', () => period + UI.table([['Voucher', 'voucher_number'], ['Date', 'released_on', 'date'], ['For', 'source_type', (v) => ({ fund_request: 'Trip fund', purchase_order: 'Purchase order', payable: 'Payable' }[v] || v)],
        ['Payee', 'payee'], ['Method', 'payment_method'], ['Reference', 'reference_number'], ['Amount', 'amount', 'money']], d.disbursements, { title: 'Cash released (payment vouchers)',actions:()=>UI.btn('details','View Details'),onAction:(action,row)=>{const drawer=UI.drawer(UI.esc(row.voucher_number),UI.h('Transaction')+UI.kv([['Voucher',UI.e(row.voucher_number)],['Source',UI.e(row.source_type)],['Payee',UI.e(row.payee)],['Amount',UI.money(row.amount)],['Method',UI.e(row.payment_method)],['Reference',UI.e(row.reference_number)],['Release date',UI.date(row.released_on)]])+UI.h('Recorded Activity')+UI.kv([['Recorded at',UI.datetime(row.created_at)],['Recorded by',UI.e(row.recorded_by_name)]]),{subtitle:'Read-only disbursement'});UI.drawerSections(drawer,[['summary','Summary',['Transaction']],['activity','Recorded Activity',['Recorded Activity']]],{key:'disbursement-details'});} })],
      ['budget', 'Purchasing budget', (body) => {
        const h = `<div class="small text-muted mb-2">Purchase orders are approved only when the active budget can cover them. The amount is <b>reserved</b> at approval and becomes <b>spent</b> when Finance pays.</div>`
          + UI.table([['Period', 'period_start', (v, r) => UI.date(v) + ' – ' + UI.date(r.period_end)], ['Budget', 'amount', 'money'], ['Reserved', 'reserved_amount', 'money'], ['Spent', 'spent_amount', 'money'], ['Available', 'available', 'money'], ['Status', 'status', 'badge'], ['Set by', 'set_by_name']], d.budgets,
            { title: 'Budgets', tools: act ? '<button class="btn btn-sm btn-primary" data-setb><i class="bi bi-plus"></i> Set new budget</button>' : '', empty: 'No budget yet.' });
        setTimeout(() => body.querySelector('[data-setb]')?.addEventListener('click', () => UI.formModal('Set purchasing budget', [
          { name: 'amount', label: 'Budget amount', type: 'money', required: true, col: 12 }, { name: 'period_start', label: 'Start date', type: 'date', value: UI.today(), required: true, col: 6 },
          { name: 'period_end', label: 'End date', type: 'date', required: true, col: 6 }], 'Finance.budget_set', { intro: 'The current active budget is closed; reservations of approved POs stay with their budget.' })), 0);
        return h;
      }],
      ['cash', 'Cash flow', () => UI.blocks({ charts: [{ type: 'bar', format: 'money', title: 'Cash in (collections) vs cash out (disbursements), last 12 months', labels: d.cashflow.map(x => x.m),
        datasets: [{ label: 'Cash in (collections)', data: d.cashflow.map(x => +x.cash_in) }, { label: 'Cash out (disbursements)', data: d.cashflow.map(x => +x.cash_out) }] }],
        tables: [{ title: 'Monthly cash flow', columns: [['Month', 'm'], ['Cash in', 'cash_in', 'money'], ['Cash out', 'cash_out', 'money'], ['Net', 'net', 'money']], rows: d.cashflow.map(x => ({ ...x, net: +x.cash_in - +x.cash_out })) }] })],
    ];
    const chosen = ctx.query.from ? 'expenses' : ctx.query.tab || 'po';
    if (head) RecordComponents.executiveTabs(box,['po','expenses','disb','ap','budget','cash'].map(key=>financialTabs.find(tab=>tab[0]===key)).filter(Boolean),chosen,'Financial Records');
    else UI.tabs(box,financialTabs,ctx.query.from ? 'expenses' : ctx.query.tab || null,{forceActive:!!ctx.query.tab||!!ctx.query.from});
  },
};

App.page('finance', {
  title: 'Payables & Payments',
  subtitle: 'Purchase order payables, other bills and the payment vouchers released.',
  render(ctx) {
    if (UI.role('admin')) return HeadFinancePage.render(ctx);
    const d = ctx.data, act = d.can.act, q = ctx.query, today = UI.today();
    if (act) UI.tool(ctx, 'Add payable', () => FinUI.addPayable(d), 'bi-plus-lg', 'primary');
    const po = (d.poPayables || []).map(o => ({ ...o, due: Math.max(0, +o.received_value - +o.paid_amount) }));
    const ap = d.payables || [];
    const poDue = po.reduce((a, o) => a + o.due, 0), apOpen = ap.filter(p => p.status !== 'Paid'), apDue = apOpen.reduce((a, p) => a + +p.balance, 0);
    const overdue = apOpen.filter(p => FinUI.overdue(p.due_on, today));
    const TABS = { po: 'PO payables', ap: 'Other payables', vouchers: 'Payment vouchers' };
    const tab = TABS[q.tab] ? q.tab : 'po';
    ctx.el.innerHTML = `${UI.crumb('finance_overview', 'Finance Overview')}${UI.kpiTiles([
      { label: 'PO payments due', value: UI.money(poDue), note: po.filter(o => o.due > 0).length + ' PO(s) with received items not paid', icon: 'bi-cart-check', tone: poDue ? 'amber' : 'gray', go: 'finance?tab=po' },
      { label: 'Other payables', value: UI.money(apDue), note: apOpen.length + ' unpaid bill(s)', icon: 'bi-receipt', tone: apDue ? 'amber' : 'gray', go: 'finance?tab=ap' },
      { label: 'Overdue bills', value: overdue.length, note: overdue.length ? UI.money(overdue.reduce((a, p) => a + +p.balance, 0)) + ' past the due date' : 'none past the due date', icon: 'bi-alarm', tone: overdue.length ? 'red' : 'gray', go: 'finance?tab=ap&status=overdue' },
      { label: 'Released in period', value: UI.money((d.disbursements || []).reduce((a, v) => a + +v.amount, 0)), note: (d.disbursements || []).length + ' voucher(s), ' + UI.date(d.period.from) + ' – ' + UI.date(d.period.to), icon: 'bi-box-arrow-up-right', tone: 'blue', go: 'finance?tab=vouchers' }])}
      <div class="line-tabs no-print" role="tablist">${Object.entries(TABS).map(([k, l]) => `<button type="button" role="tab" class="tab ${k === tab ? 'active' : ''}" data-go="finance?${new URLSearchParams({ ...(k === 'vouchers' ? { from: q.from || '', to: q.to || '' } : {}), tab: k })}">${l}</button>`).join('')}</div>`;
    const box = document.createElement('div'); ctx.el.append(box);
    const next = (role, label) => `<span class="wait-chip ${UI.role(role) ? 'is-you' : ''}"><i class="bi bi-person-workspace" aria-hidden="true"></i>${UI.role(role) ? 'You (Finance)' : 'Finance'}</span><div class="cell-sub">${UI.esc(label)}</div>`;
    if (tab === 'po') {
      const sup = [...new Set(po.map(o => o.supplier).filter(Boolean))].sort();
      const rows = po.filter(o => (!q.supplier || o.supplier === q.supplier) && (q.due !== 'due' || o.due > 0));
      box.innerHTML = UI.generalFilters(ctx, [{ name: 'supplier', label: 'Suppliers', options: sup }, { name: 'due', label: 'Payment state', all: 'All open POs', options: [['due', 'Has an amount to pay now']] }])
        + UI.table([['PO', 'po_ref', (v, o) => `<b>${UI.esc(v)}</b>${o.supplier_invoice_no ? `<div class="cell-sub">Invoice ${UI.esc(o.supplier_invoice_no)}</div>` : ''}`], ['Supplier', 'supplier', (v) => `<span class="cell-clamp">${UI.esc(v)}</span>`],
          ['PO total', 'total_amount', 'money'], ['Received', 'received_value', 'money'], ['Paid', 'paid_amount', 'money'], ['To pay now', 'due', 'money'], ['Payment', 'payment_status', 'badge'],
          ['Next action', 'due', (v, o) => o.due > 0 ? next('finance', 'Record payment') : '<span class="cell-sub">Waiting for delivery (Purchasing)</span>']], rows,
          { title: 'Approved purchase orders not fully paid (' + rows.length + ')', showAllColumns: true, directActions: true,
            actions: (o) => (act && o.due > 0 ? UI.ibtn('pay', 'Record payment', 'bi-cash-coin', 'primary') : '') + UI.ibtn('open', 'Open PO details', 'bi-eye', 'outline-primary'),
            onAction: (a, o) => a === 'pay' ? FinUI.payPO(d, o) : FinUI.openPO(o.id), rowClick: (o) => FinUI.openPO(o.id), empty: 'No purchase order is waiting for payment.' });
    } else if (tab === 'ap') {
      const cats = [...new Set(ap.map(p => p.category))].sort();
      const rows = ap.filter(p => (!q.category || p.category === q.category) && (!q.status || (q.status === 'overdue' ? p.status !== 'Paid' && FinUI.overdue(p.due_on, today) : p.status === q.status)));
      box.innerHTML = UI.generalFilters(ctx, [{ name: 'status', label: 'Statuses', options: [['Unpaid', 'Unpaid'], ['Partially Paid', 'Partially Paid'], ['Paid', 'Paid'], ['overdue', 'Overdue']] }, { name: 'category', label: 'Categories', options: cats }])
        + UI.table([['Payable', 'payable_ref', (v, p) => `<b>${UI.esc(v)}</b>${p.plate_number ? `<div class="cell-sub">${UI.esc(p.plate_number)}</div>` : ''}`], ['Payee', 'payee'], ['Category', 'category'],
          ['Description', 'description', (v) => `<span class="cell-clamp" title="${UI.esc(v)}">${UI.esc(v)}</span>`],
          ['Due', 'due_on', (v, p) => v ? UI.date(v) + (p.status !== 'Paid' && FinUI.overdue(v, today) ? ' <span class="badge-s red">Overdue</span>' : '') : '<span class="text-muted">None</span>'],
          ['Amount', 'amount', 'money'], ['Balance', 'balance', 'money'], ['Status', 'status', 'badge']], rows,
          { title: 'Other payables (' + rows.length + ')', showAllColumns: true, directActions: true,
            actions: (p) => act && p.status !== 'Paid' ? UI.ibtn('pay', 'Record payment', 'bi-cash-coin', 'primary') : '',
            onAction: (a, p) => FinUI.payPayable(d, p), empty: 'No payable matches.' });
    } else {
      const v = d.disbursements || [];
      const rows = v.filter(x => !q.source || x.source_type === q.source);
      box.innerHTML = FinUI.period(ctx, d) + UI.generalFilters(ctx, [{ name: 'source', label: 'Sources', options: [['purchase_order', 'Purchase orders'], ['fund_request', 'Trip funds'], ['payable', 'Payables']].filter(([k]) => v.some(x => x.source_type === k)) }])
        + UI.table([['Voucher', 'voucher_number', (x) => `<b>${UI.esc(x)}</b>`], ['Released', 'released_on', 'date'], ['Paid for', 'source_type', (s, x) => `${UI.esc({ fund_request: 'Trip fund', purchase_order: 'Purchase order', payable: 'Payable' }[s] || s)}${x.source_ref ? `<div class="cell-sub">${UI.esc(x.source_ref)}</div>` : ''}`],
          ['Payee', 'payee'], ['Method', 'payment_method'], ['Amount', 'amount', 'money']], rows,
          { title: 'Payment vouchers (' + rows.length + ')', showAllColumns: true, directActions: true, actions: () => UI.ibtn('open', 'Open voucher', 'bi-eye', 'outline-primary'),
            onAction: (a, x) => FinUI.openVoucher(x), rowClick: FinUI.openVoucher, empty: 'No payment voucher in this period.' });
    }
  },
});

// ---------------------------------------------------------------- Budgets & Expenses
App.page('finance_budgets', {
  title: 'Budgets & Expenses',
  subtitle: 'Purchasing budget (reserved at PO approval, spent at payment), operating and maintenance expenses, cash flow.',
  render(ctx) {
    const d = ctx.data, act = d.can.act, q = ctx.query;
    if (act) { UI.tool(ctx, 'Add expense', () => FinUI.addExpense(d), 'bi-plus-lg', 'outline-secondary'); UI.tool(ctx, 'Set new budget', () => FinUI.setBudget(d), 'bi-piggy-bank', 'primary'); }
    const b = (d.budgets || []).find(x => x.status === 'Active');
    const exp = d.expenses || [], total = exp.reduce((a, e) => a + +e.amount, 0);
    const byCat = {}; (d.expenseMix || []).forEach(m => { byCat[m.category] = (byCat[m.category] || 0) + +m.total; });
    const top = Object.entries(byCat).sort((a, c) => c[1] - a[1]);
    const maint = (byCat.Maintenance || 0) + (byCat.Parts || 0);
    const util = b && +b.amount ? Math.round((+b.spent_amount + +b.reserved_amount) / b.amount * 100) : null;
    ctx.el.innerHTML = `${UI.crumb('finance_overview', 'Finance Overview')}${UI.kpiTiles([
      { label: 'Budget available', value: b ? UI.money(b.available) : 'No budget', note: b ? UI.date(b.period_start) + ' – ' + UI.date(b.period_end) : 'set a purchasing budget', icon: 'bi-piggy-bank', tone: b ? (util >= 80 ? 'amber' : 'green') : 'red' },
      { label: 'Budget used', value: util === null ? '—' : util + '%', note: b ? `paid ${UI.money(b.spent_amount)} + reserved ${UI.money(b.reserved_amount)}` : '', icon: 'bi-speedometer', tone: util >= 80 ? 'amber' : 'gray' },
      { label: 'Expenses in period', value: UI.money(total), note: exp.length + ' record(s), ' + UI.date(d.period.from) + ' – ' + UI.date(d.period.to), icon: 'bi-wallet2', tone: 'blue' },
      { label: 'Maintenance and parts', value: UI.money(maint), note: total ? Math.round(maint / total * 100) + '% of the expenses in period' : 'no expenses in period', icon: 'bi-tools', tone: 'gray', go: 'finance_budgets?category=Maintenance' + (q.from ? '&from=' + q.from + '&to=' + (q.to || '') : '') }])}`;
    const box = document.createElement('div'); ctx.el.append(box);
    const budgets = UI.table([['Period', 'period_start', (v, r) => `<span class="cell-nowrap">${UI.date(v)} – ${UI.date(r.period_end)}</span>`], ['Budget', 'amount', 'money'], ['Reserved', 'reserved_amount', 'money'], ['Paid', 'spent_amount', 'money'],
      ['Available', 'available', 'money'], ['Used', 'amount', (v, r) => +v ? Math.round((+r.spent_amount + +r.reserved_amount) / v * 100) + '%' : '—'], ['Status', 'status', 'badge'], ['Set by', 'set_by_name']], d.budgets || [],
      { title: 'Purchasing budgets', showAllColumns: true, empty: 'No budget yet.', limit: 5 });
    const cats = [...new Set(exp.map(e => e.category))].sort(), srcs = [...new Set(exp.map(e => e.source))].sort();
    const rows = exp.filter(e => (!q.category || e.category === q.category) && (!q.source || e.source === q.source));
    const mix = top.length ? `<section class="panel"><div class="panel-head"><h2 class="panel-title">Expenses by category (period)</h2></div><div class="panel-body">${top.map(([k, v]) => `
      <div class="ws-bar"><div class="ws-bar-top"><span>${UI.esc(k)}</span><b>${UI.money(v)} · ${Math.round(v / total * 100)}%</b></div><div class="ws-bar-track"><div class="ws-bar-fill tone-blue" style="width:${(v / top[0][1] * 100).toFixed(1)}%"></div></div></div>`).join('')}
      <div class="ws-foot">Source of each amount: fund releases, PO payments, payables, repair costs and manual entries (expenses table). Totals follow the period above.</div></div></section>` : '';
    box.innerHTML = budgets + FinUI.period(ctx, d) + `<div class="mo-grid maint-overview" style="grid-template-columns:minmax(0,1.6fr) minmax(0,1fr)"><div class="mo-main">`
      + UI.generalFilters(ctx, [{ name: 'category', label: 'Categories', options: cats }, { name: 'source', label: 'Sources', options: srcs }])
      + UI.table([['Date', 'incurred_on', 'date'], ['Category', 'category'], ['Amount', 'amount', 'money'], ['Source', 'source', (v, e) => `${UI.esc(v)}${e.reference_number ? `<div class="cell-sub">${UI.esc(e.reference_number)}</div>` : ''}`],
        ['Trip / truck', 'trip_ref', (v, e) => UI.e(v || e.plate_number)], ['Payee', 'payee'], ['Description', 'description', (v) => `<span class="cell-clamp" title="${UI.esc(v)}">${UI.esc(v)}</span>`]], rows,
        { title: 'Expenses (' + rows.length + ')', showAllColumns: true, empty: 'No expense in this period.' })
      + `</div><div class="mo-side">${mix}</div></div>`
      + UI.blocks({ charts: [{ type: 'bar', format: 'money', title: 'Cash in (collections) vs cash out (payment vouchers), last 12 months', labels: (d.cashflow || []).map(x => x.m),
        datasets: [{ label: 'Cash in', data: (d.cashflow || []).map(x => +x.cash_in) }, { label: 'Cash out', data: (d.cashflow || []).map(x => +x.cash_out) }] }] });
  },
});
