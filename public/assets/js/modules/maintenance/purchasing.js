/* assets/js/modules/maintenance/purchasing.js — PURCHASING WORKSPACE (Overview, Parts Requests, Purchase Orders, Inventory, Stock Movements)
   A. Parts request (stock-out): Motorpool requests (from Repair Management) -> Purchasing checks stock and releases.
   B. Purchase order (stock-in): Purchasing creates -> routed to Finance (<= limit) or Admin
      (> limit) -> budget check -> approved PO (with e-signature) -> receiving (partial allowed).
   Printable REQUEST FORM (PDF #13) and PURCHASE ORDER.
   Supplier details are typed in the PO form and saved with the PO (no supplier masterlist tab);
   suppliers used before are suggested and fill in their address and contact.
   Backend: backend/Modules/Maintenance/Purchasing.php (payment: Accounting/Finance.php) */
'use strict';
const PurUI = {
  data: null,
  async details(id, kind = 'po') {
    let r;try{r=await App.api(kind==='po'?'Purchasing.po_details':'Purchasing.request_details',kind==='po'?{po_id:id}:{request_id:id});}catch(error){return UI.toast(error.message,'error');}
    const order=kind==='po',record=order?r.po:r.request,lines=r.lines||[],reference=order?record.po_ref:record.request_ref;
    const received=order?lines.reduce((sum,line)=>sum+Number(line.received_qty||0),0):lines.reduce((sum,line)=>sum+Number(line.released_qty||0),0);
    const requested=lines.reduce((sum,line)=>sum+Number(line.quantity||0),0);
    const rejected=String(record.status).startsWith('Rejected')||record.status==='Cancelled';
    const milestones=order?[
      {label:'Submitted',at:record.created_at,state:record.created_at?'complete':'pending'},
      {label:rejected?record.status:'Approval decision',at:record.decided_at,state:rejected?'exception':record.decided_at?'complete':'pending'},
      {label:'Parts received',state:received>0&&received===requested?'complete':received>0?'current':'pending',note:received+' of '+requested+' units recorded; dated receiving history is not provided by this endpoint.'}
    ]:[{label:'Requested',at:record.created_at,state:record.created_at?'complete':'pending'},{label:'Parts released',state:received>0&&received===requested?'complete':rejected?'exception':received>0?'current':'pending',note:received+' of '+requested+' units recorded.'}];
    const html=UI.workflow(milestones,{status:record.status})+UI.h('Overview')+UI.kv([['Reference',UI.e(reference)],['Status',UI.badge(record.status)],['Requested by',UI.e(record.requested_by_name)],['Created',UI.datetime(record.created_at)],['Maintenance record',UI.e(record.job_ref)],['Approval role',UI.e(record.approver_role)],['Purpose',UI.e(record.purpose)],['Supplier',UI.e(record.supplier)],['Truck',UI.e(record.plate_number)],['Payment status',UI.e(record.payment_status)],...(order?[['Order total',UI.money(record.total_amount)],['Paid amount',UI.money(record.paid_amount)],['Received value',UI.money(lines.reduce((sum,line)=>sum+Number(line.received_qty||0)*Number(line.unit_price||0),0))],['Supplier invoice',UI.e(record.supplier_invoice_no)]]:[])])+UI.h('Items and Quantities')+UI.table([['Part','part_number'],['Description','description'],['Requested / Ordered','quantity','num'],[order?'Received':'Released',order?'received_qty':'released_qty','num'],...(order?[['Unit price','unit_price','money']]:[])],lines,{plain:true})+UI.h('Processing Record')+UI.kv([['Decision by',UI.e(record.decided_by_name)],['Decision date',UI.datetime(record.decided_at)],['Decision notes',UI.e(record.decision_notes)],['Processed by',UI.e(record.processed_by_name)],['Notes',UI.e(record.notes)]])+UI.h('Payments')+UI.table([['Voucher','voucher_number'],['Amount','amount','money'],['Released','released_on','date'],['Recorded by','recorded_by_name']],r.payments||[],{plain:true,empty:'No payment details returned for this record and role.'})+UI.h('Documents')+UI.docs(r.documents||[]);
    const drawer=UI.drawer(UI.esc(reference),html,{subtitle:'Read-only '+(order?'Purchase Order':'Parts Request')});
    UI.drawerSections(drawer,[['summary','Overview',['Overview']],['items','Items & Quantities',['Items and Quantities']],['record','Processing Record',['Processing Record']],['payments','Payments',['Payments']],['docs','Documents',['Documents']]],{key:'purchasing-details'});
  },
  lineEditor(withPrice, lines = []) {
    const parts = PurUI.data.parts.filter(p => +p.active);
    const row = (l = {}) => `<div class="row g-2 mb-1 pl-row"><div class="col-${withPrice ? 6 : 9}"><select class="form-select form-select-sm" data-part><option value="">Choose part…</option>
        ${parts.map(p => `<option value="${p.id}" data-cost="${p.unit_cost}" ${String(p.id) === String(l.part_id) ? 'selected' : ''}>${UI.esc(p.part_number)} · ${UI.esc(p.description)} (on hand ${p.quantity_on_hand} ${UI.esc(p.unit)})</option>`).join('')}</select></div>
      <div class="col-${withPrice ? 2 : 3}"><input class="form-control form-control-sm" type="number" min="1" max="100000" placeholder="Qty" data-qty value="${UI.esc(l.quantity || '')}"></div>
      ${withPrice ? `<div class="col-2"><input class="form-control form-control-sm" type="number" min="0" step="0.01" placeholder="Unit price" data-price value="${UI.esc(l.unit_price || '')}"></div><div class="col-2 small text-end pt-1" data-lt></div>` : ''}</div>`;
    const rows = (lines.length ? lines : [{}, {}, {}]).map(row).join('');
    return `<div class="small text-muted mb-1">${withPrice ? 'Part · Qty · Unit price · Amount' : 'Part · Qty'} ${parts.length ? '' : '<b class="text-danger">No parts yet: Purchasing adds them on the Inventory page.</b>'}</div><div data-lines>${rows}</div>
      <button type="button" class="btn btn-sm btn-link p-0" data-addrow>+ Add item</button>${withPrice ? '<div class="text-end fw-bold" data-total></div><div class="small text-end" data-route></div>' : ''}`;
  },
  wireLines(w, withPrice) {
    const host = w.querySelector('[data-lines]');
    const tpl = host.querySelector('.pl-row').outerHTML;
    w.querySelector('[data-addrow]').onclick = () => { host.insertAdjacentHTML('beforeend', tpl); host.lastElementChild.querySelectorAll('input').forEach(i => i.value = ''); host.lastElementChild.querySelector('select').value = ''; calc(); };
    const calc = () => {
      if (!withPrice) return;
      let t = 0;
      host.querySelectorAll('.pl-row').forEach(r => { const q = +r.querySelector('[data-qty]').value || 0, p = +r.querySelector('[data-price]').value || 0; r.querySelector('[data-lt]').textContent = q && p ? UI.money(q * p) : ''; t += q * p; });
      w.querySelector('[data-total]').textContent = 'Total: ' + UI.money(t);
      const lim = +PurUI.data.threshold;
      w.querySelector('[data-route]').innerHTML = t ? (t <= lim ? `Approval goes to <b>Finance</b> (PHP ${UI.num(lim)} and below).` : `Approval goes to the <b>Admin</b> (above PHP ${UI.num(lim)}).`) : '';
    };
    host.addEventListener('input', calc);
    host.addEventListener('change', (e) => { if (withPrice && e.target.matches('[data-part]')) { const pr = e.target.closest('.pl-row').querySelector('[data-price]'); if (!pr.value) pr.value = +e.target.selectedOptions[0]?.dataset.cost || ''; } calc(); });
    calc();
  },
  readLines(w, withPrice) {
    return [...w.querySelectorAll('.pl-row')].map(r => ({ part_id: r.querySelector('[data-part]').value, quantity: r.querySelector('[data-qty]').value, ...(withPrice ? { unit_price: r.querySelector('[data-price]').value } : {}) }))
      .filter(l => l.part_id || l.quantity);
  },
  /** Supplier fields of the PO form; picking a suggested supplier fills the empty fields. */
  supplierFields() {
    return [
      { name: 'supplier_name', label: 'Supplier name', required: true, col: 6, attrs: 'list="poSupList" autocomplete="off" maxlength="150"', help: 'Type the name. Suppliers used before are suggested.' },
      { name: 'supplier_contact', label: 'Contact person (optional)', col: 6, attrs: 'maxlength="120"' },
      { name: 'supplier_address', label: 'Supplier address (optional)', col: 12, attrs: 'maxlength="255"' },
      { name: 'supplier_phone', label: 'Contact number (optional)', col: 6, attrs: 'maxlength="60" inputmode="tel"', placeholder: '0917 123 4567' },
      { name: 'supplier_email', label: 'Supplier email (optional)', type: 'email', col: 6, attrs: 'maxlength="120"' },
    ];
  },
  wireSupplier(w) {
    const list = PurUI.data.supplierSuggestions || [];
    w.querySelector('form').insertAdjacentHTML('beforeend', `<datalist id="poSupList">${list.map(x => `<option value="${UI.esc(x.name)}"></option>`).join('')}</datalist>`);
    const f = (n) => w.querySelector(`[name="${n}"]`);
    f('supplier_name').addEventListener('change', () => {
      const hit = list.find(x => x.name.toUpperCase() === f('supplier_name').value.trim().toUpperCase()); if (!hit) return;
      [['supplier_address', 'address'], ['supplier_contact', 'contact_person'], ['supplier_phone', 'contact_number'], ['supplier_email', 'email']]
        .forEach(([field, key]) => { if (!f(field).value.trim() && hit[key]) f(field).value = hit[key]; });
    });
  },
  newPO(prefill = {}) {
    const d = PurUI.data;
    UI.modal('Purchase Order Request', UI.fields([
      ...PurUI.supplierFields(),
      { name: 'part_request_id', label: 'Related parts request (optional)', type: 'select', col: 6, value: prefill.part_request_id, options: (d.linkableRequests || (d.requests || []).filter(r => ['Not Available', 'Partially Released', 'Requested'].includes(r.status))).map(r => [r.id, r.request_ref + ' · ' + r.plate_number]) },
      { name: 'purpose', label: 'Purpose', required: true, col: 12, value: prefill.purpose }]) + '<div class="mt-2">' + PurUI.lineEditor(true, prefill.lines || []) + '</div>'
      + (d.budget && d.budget.id && +d.budget.in_period ? `<div class="small text-muted mt-2">Purchasing budget available now: <b>${UI.money(d.budget.available)}</b> (checked again at approval).</div>`
        : d.budget && d.budget.id ? `<div class="small text-danger mt-2">The purchasing budget period ended on ${UI.date(d.budget.period_end)}: approval will be rejected until Finance sets a new budget.</div>`
        : '<div class="small text-danger mt-2">No active purchasing budget: approval will be rejected until Finance sets one.</div>'),
      async (x, form, w) => { const r = await App.api('Purchasing.po_create', { ...x, lines: PurUI.readLines(w, true) }); UI.result(r); App.route(); },
      { submit: 'Submit for approval', onShow: (w) => { PurUI.wireLines(w, true); PurUI.wireSupplier(w); } });
  },
  async processRequest(rq) {
    const r = await App.api('Purchasing.request_details', { request_id: rq.id });
    const rows = r.lines.map(l => { const left = l.quantity - l.released_qty, can = Math.min(left, l.quantity_on_hand);
      return `<tr><td>${UI.esc(l.part_number)}<div class="small text-muted">${UI.esc(l.description)}</div></td><td class="num">${l.quantity}</td><td class="num">${l.released_qty}</td><td class="num">${l.quantity_on_hand}</td>
        <td><input class="form-control form-control-sm" type="number" min="0" max="${left}" value="${can > 0 ? can : 0}" data-rel="${l.id}" ${left ? '' : 'disabled'}></td></tr>`; }).join('');
    const wrap = UI.modal('Process ' + UI.esc(rq.request_ref), `<p class="small text-muted">Truck ${UI.esc(rq.plate_number)} · ${UI.esc(rq.purpose)}. Release what is in stock; the inventory is updated automatically.</p>
      <table class="data-table"><thead><tr><th>Part</th><th class="num">Requested</th><th class="num">Released</th><th class="num">On hand</th><th>Release now</th></tr></thead><tbody>${rows}</tbody></table>
      ${UI.fields([{ name: 'notes', label: 'Notes', col: 12 }])}`,
      async (x, form) => { const release = [...form.querySelectorAll('[data-rel]')].map(i => ({ line_id: i.dataset.rel, qty: +i.value || 0 }));
        const res = await App.api('Purchasing.request_process', { request_id: rq.id, release, notes: x.notes }); UI.result(res); App.route(); },
      { submit: 'Release parts', extraFooter: '<button type="button" class="btn btn-outline-danger btn-sm me-auto" data-na>Not available (no stock)</button>' });
    wrap.querySelector('[data-na]').onclick = async () => { if (!await UI.confirmProceed('Confirm no stock', 'Mark this parts request as not available because the required stock is unavailable?', 'Confirm not available')) return; try { const res = await App.api('Purchasing.request_process', { request_id: rq.id, not_available: 1 }); UI.result(res); bootstrap.Modal.getInstance(wrap).hide(); App.route(); } catch (e) { UI.toast(e.message, 'error'); } };
  },
  async printRequest(rq) {
    const r = await App.api('Purchasing.request_details', { request_id: rq.id }); const q = r.request;
    UI.printForm('REQUEST FORM', `<div class="pf-grid"><div><b>Truck No. / Trailer No.:</b> ${UI.esc(q.plate_number)}${q.truck_number ? ' #' + UI.esc(q.truck_number) : ''}</div><div><b>DATE:</b> ${UI.date(q.created_at)}</div>
      <div><b>Model No. / Engine No.:</b> ${UI.esc([q.make_brand, q.engine_number].filter(Boolean).join(' / '))}</div><div><b>Series No.:</b> ${UI.esc(q.request_ref)}</div></div>
      <div class="mt-1"><b>PARTICULARS</b></div><table><thead><tr><th style="width:12%">ITEM NO.</th><th>ITEMS / DESCRIPTION</th><th class="num" style="width:12%">QTY.</th><th style="width:12%">UNIT</th></tr></thead>
      <tbody>${r.lines.map(l => `<tr><td>${UI.esc(l.part_number)}</td><td>${UI.esc(l.description)}</td><td class="num">${l.quantity}</td><td>${UI.esc(l.unit)}</td></tr>`).join('')}${UI.pfBlank(4, r.lines.length, 12)}
      <tr><th colspan="2">TOTAL</th><th class="num">${r.lines.reduce((a, l) => a + +l.quantity, 0)}</th><th></th></tr></tbody></table>
      <div><b>Reason / Purpose:</b> ${UI.esc(q.purpose)}${q.job_ref ? ' (' + UI.esc(q.job_ref) + ')' : ''}</div>
      <div class="pf-signs">${UI.sign('Requested by', q.requested_by_name, q.requested_by, 'part_request', q.id)}${UI.sign('Received by', q.processed_by_name || '', q.processed_by, 'part_request', q.id)}${UI.sign('Approved by')}</div>`, { number: q.request_ref });
  },
  async printPO(o) {
    const r = await App.api('Purchasing.po_details', { po_id: o.id }); const p = r.po;
    const approved = ['Approved', 'Partially Received', 'Received', 'Closed'].includes(p.status);
    UI.printForm('PURCHASE ORDER', `<div class="pf-grid"><div><b>SUPPLIER:</b> ${UI.esc(p.supplier)}</div><div><b>DATE:</b> ${UI.date(p.created_at)}</div>
      <div><b>ADDRESS:</b> ${UI.esc(p.supplier_address || '')}</div><div><b>CONTACT:</b> ${UI.esc([p.supplier_contact, p.supplier_phone, p.supplier_email].filter(Boolean).join(' · '))}</div>
      <div style="grid-column:1/3"><b>PURPOSE:</b> ${UI.esc(p.purpose)}${p.request_ref ? ' (from ' + UI.esc(p.request_ref) + ')' : ''}</div></div>
      <table><thead><tr><th>Part no.</th><th>Description</th><th class="num">Qty</th><th>Unit</th><th class="num">Unit price</th><th class="num">Amount</th></tr></thead>
      <tbody>${r.lines.map(l => `<tr><td>${UI.esc(l.part_number)}</td><td>${UI.esc(l.description)}</td><td class="num">${l.quantity}</td><td>${UI.esc(l.unit)}</td><td class="num">${UI.money(l.unit_price)}</td><td class="num">${UI.money(l.line_total)}</td></tr>`).join('')}${UI.pfBlank(6, r.lines.length, 8)}
      <tr><th colspan="5">TOTAL</th><th class="num">${UI.money(p.total_amount)}</th></tr></tbody></table>
      <div class="pf-note">Status: ${UI.esc(p.status)}${approved ? ' · Approved by ' + UI.esc(p.decided_by_name) + ' (' + (p.approver_role === 'finance' ? 'Finance' : 'Admin') + ') on ' + UI.datetime(p.decided_at) + '. Budget reserved by the system.' : ' · NOT APPROVED — do not use for purchasing.'}</div>
      <div class="pf-signs">${UI.sign('Requested by (Purchasing Officer)', p.requested_by_name, p.requested_by, 'purchase_order', p.id)}${UI.sign('Approved by (' + (p.approver_role === 'finance' ? 'Finance' : 'Admin') + ')' + (approved ? ' · approved in the system' : ''), approved ? p.decided_by_name : '', approved ? p.decided_by : null, 'purchase_order', p.id)}</div>`, { number: p.po_ref });
  },
  async receive(o) {
    const r = await App.api('Purchasing.po_details', { po_id: o.id });
    const rows = r.lines.map(l => { const left = l.quantity - l.received_qty;
      return `<tr><td>${UI.esc(l.part_number)}<div class="small text-muted">${UI.esc(l.description)}</div></td><td class="num">${l.quantity}</td><td class="num">${l.received_qty}</td>
        <td><input class="form-control form-control-sm" type="number" min="0" max="${left}" value="${left}" data-rcv="${l.id}" ${left ? '' : 'disabled'}></td></tr>`; }).join('');
    UI.modal('Receive parts for ' + UI.esc(o.po_ref), `<p class="small text-muted">Check the actual parts and quantities against the approved PO. Enter only what arrived; a short delivery stays "Partially Received".</p>
      <table class="data-table"><thead><tr><th>Part</th><th class="num">Ordered</th><th class="num">Received</th><th>Received now</th></tr></thead><tbody>${rows}</tbody></table>
      ${UI.fields([{ name: 'supplier_invoice_no', label: 'Supplier invoice / delivery receipt no.', col: 6 }, { name: 'receipt', label: 'Delivery receipt / invoice file (optional)', type: 'file', col: 6 }])}`,
      async (x, form) => { const receive = [...form.querySelectorAll('[data-rcv]')].map(i => ({ line_id: i.dataset.rcv, qty: +i.value || 0 }));
        const res = await App.apiForm('Purchasing.po_receive', { po_id: o.id, receive, supplier_invoice_no: x.supplier_invoice_no }, form); UI.result(res); App.route(); }, { submit: 'Record received parts' });
  },
};


/** Row actions and forms shared by the Purchasing pages (Purchasing Officer acts, Finance/Admin approve, Admin/Audit read). */
PurUI.kit = (d, can) => {
    // STAGE_B_PURCHASING_UI: one action list per record, shown as icon buttons in table rows and as labelled buttons
    // in the details drawer (big = true). Only actions the role and the record's status allow are listed.
    const act = (big) => (key, label, icon, cls) => big ? UI.btn(key, label, cls, icon) : UI.ibtn(key, label, icon, cls);
    const reqActs = (r, big) => { const a = act(big);
      return (can.stock && ['Requested', 'Partially Released', 'Not Available'].includes(r.status) ? a('process', 'Process request (check stock and release)', 'bi-box-arrow-up', 'primary') : '')
      + (can.stock && ['Not Available', 'Partially Released'].includes(r.status) ? a('po', 'Create PO for the shortage', 'bi-cart-plus', 'outline-primary') : '')
      + a('print', 'Print request form', 'bi-printer', 'outline-secondary'); };
    const reqAct = async (a, r) => {
      if (a === 'details') return PurUI.details(r.id, 'request');
      if (a === 'print') PurUI.printRequest(r);
      if (a === 'process') PurUI.processRequest(r);
      if (a === 'po') { const x = await App.api('Purchasing.request_details', { request_id: r.id });
        PurUI.newPO({ part_request_id: r.id, purpose: 'Parts for ' + r.plate_number + ' (' + r.request_ref + ')', lines: x.lines.filter(l => l.quantity - l.released_qty > 0).map(l => ({ part_id: l.part_id, quantity: l.quantity - l.released_qty, unit_price: (d.parts.find(p => p.id == l.part_id) || {}).unit_cost })) }); }
    };
    const poActs = (o, big) => {
      const a = act(big); let h = '';
      if (o.status === 'Pending Approval' && ((o.approver_role === 'finance' && can.approveSmall) || (o.approver_role === 'admin' && can.approveBig))) h += a('approve', 'Approve PO', 'bi-check-lg', 'success') + a('reject', 'Reject PO', 'bi-x-lg', 'outline-danger');
      if (can.stock && ['Approved', 'Partially Received'].includes(o.status)) h += a('receive', 'Receive items', 'bi-box-arrow-in-down', 'primary') + (big ? a('close', 'Close balance (no further delivery)', 'bi-slash-circle', 'outline-secondary') : '');
      return h + a('print', 'Print PO', 'bi-printer', 'outline-secondary');
    };
    const poAct = (a, o) => {
      if (a === 'details') return PurUI.details(o.id);
      if (a === 'print') PurUI.printPO(o);
      if (a === 'approve') UI.confirmAction('Approve ' + UI.esc(o.po_ref), `Approve ${UI.money(o.total_amount)} to ${UI.esc(o.supplier)}? The system checks the purchasing budget and reserves the amount; if the budget is not enough the PO is rejected automatically.`
        + (App.me.user.has_signature ? '' : '<div class="small text-warning mt-2">You have no e-signature yet (name menu > My e-signature). Your name is printed instead.</div>'), 'Purchasing.po_decide', { po_id: o.id, decision: 'approve' }, { submit: 'Approve' });
      if (a === 'reject') UI.confirmAction('Reject ' + UI.esc(o.po_ref), 'The Purchasing Officer will be notified.', 'Purchasing.po_decide', { po_id: o.id, decision: 'reject' }, { danger: true, fields: [{ name: 'notes', label: 'Reason', required: true, col: 12 }] });
      if (a === 'receive') PurUI.receive(o);
      if (a === 'close') UI.confirmAction('Close ' + UI.esc(o.po_ref), 'Use this when the rest of the order will not be delivered. The reserved budget of the undelivered items is released.', 'Purchasing.po_close', { po_id: o.id }, { fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
    };
    // STOCK_COUNT_RECONCILIATION_V1: no direct +/- adjustment. A discrepancy is recorded as a stock count for the Admin to post.
    const partActs = (p, big) => { const a = act(big);
      return (can.count && d.countsReady && +p.active ? a('count', 'Record stock count', 'bi-clipboard-check', 'outline-primary') : '') + (can.stock ? a('edit', 'Edit part', 'bi-pencil', 'outline-secondary') : ''); };
    const partForm = (p = {}) => UI.formModal(p.id ? 'Edit part ' + UI.esc(p.part_number) : 'Add part', [
      { name: 'part_number', label: 'Part no. / ID', required: true, col: 4, value: p.part_number }, { name: 'description', label: 'Description', required: true, col: 8, value: p.description },
      { name: 'category', label: 'Category', col: 4, value: p.category }, { name: 'unit', label: 'Unit', required: true, col: 4, value: p.unit || 'pc' }, { name: 'unit_cost', label: 'Unit cost', type: 'money', required: true, col: 4, value: p.unit_cost },
      { name: 'reorder_level', label: 'Reorder level (low-stock alert)', type: 'number', required: true, col: 4, value: p.reorder_level ?? 0 }, { name: 'warranty_info', label: 'Warranty', col: 4, value: p.warranty_info },
      ], 'Purchasing.part_save', { extra: p.id ? { id: p.id } : {},
        intro: p.id ? '' : 'A new part starts with 0 on hand. Stock comes in when a purchase order is received, or through a stock count posted by the Admin.' });
    const partAct = (a, p) => a === 'edit' ? partForm(p) : a === 'count' ? PurUI.countForm(d, p) : null;
  return { reqActs, reqAct, poActs, poAct, partActs, partForm, partAct };
};

/* STOCK_COUNT_RECONCILIATION_V1 — physical count (Purchasing) -> review (Admin) -> one Adjustment movement.
   Data: Purchasing::read('inventory').counts; actions Purchasing.count_submit / count_review / count_details (server-checked). */
PurUI.countForm = (d, part) => {
  const parts = (d.parts || []).filter(p => +p.active);
  const sel = part ? part.id : '';
  const wrap = UI.modal('Record stock count', `<p class="modal-intro">Count the part physically and enter what you found. The stock does <b>not</b> change now:
      the Admin reviews the count and posts it, which records one traceable adjustment.</p>
    ${UI.fields([{ name: 'part_id', label: 'Part', type: 'select', required: true, col: 12, value: sel, options: parts.map(p => [p.id, p.part_number + ' · ' + p.description]) }])}
    <div class="sc-figures" data-fig></div>
    ${UI.fields([{ name: 'counted_qty', label: 'Counted quantity', type: 'number', required: true, col: 6, attrs: 'min="0" max="100000" step="1"' },
      { name: 'reason', label: 'Reason for the difference', required: true, col: 6, placeholder: 'Damaged, lost, miscount on receiving…' },
      { name: 'evidence', label: 'Supporting evidence (photo or count sheet, optional)', type: 'file', multiple: true, col: 12, attrs: 'accept="application/pdf,image/png,image/jpeg"' }])}`,
    async (x, form) => { const r = await App.apiForm('Purchasing.count_submit', { part_id: x.part_id, counted_qty: x.counted_qty, reason: x.reason }, form); UI.result(r); App.route(); },
    { submit: 'Submit for review', confirmBeforeSubmit: false });
  const f = (n) => wrap.querySelector(`[name="${n}"]`), fig = wrap.querySelector('[data-fig]');
  const show = () => {
    const p = parts.find(x => String(x.id) === f('part_id').value), raw = f('counted_qty').value;
    if (!p) { fig.innerHTML = ''; return; }
    const open = (d.counts || []).find(c => +c.part_id === +p.id && c.status === 'Submitted');
    const diff = raw === '' ? null : Math.trunc(+raw) - +p.quantity_on_hand;
    fig.innerHTML = `<div class="sc-fig"><span>System quantity</span><b>${UI.num(p.quantity_on_hand)} ${UI.esc(p.unit)}</b></div>
      <div class="sc-fig"><span>Counted</span><b>${raw === '' ? '—' : UI.num(+raw) + ' ' + UI.esc(p.unit)}</b></div>
      <div class="sc-fig ${diff ? (diff < 0 ? 'is-short' : 'is-over') : ''}"><span>Discrepancy</span><b>${diff === null ? '—' : (diff > 0 ? '+' : '') + UI.num(diff)}</b>
        ${diff ? `<small>${UI.money(Math.abs(diff) * +p.unit_cost)} at the recorded unit cost</small>` : ''}</div>
      ${open ? `<div class="alert alert-warning py-1 px-2 small mb-0 w-100">${UI.esc(open.count_ref)} for this part is still waiting for review. Only one open count per part is allowed.</div>` : ''}
      ${diff === 0 ? '<div class="alert alert-success py-1 px-2 small mb-0 w-100">The count matches the system: nothing to reconcile.</div>' : ''}`;
  };
  f('part_id').addEventListener('change', show); f('counted_qty').addEventListener('input', show); show();
};
PurUI.countReview = (c, decision) => decision === 'post'
  ? UI.confirmAction('Post ' + UI.esc(c.count_ref), `Set <b>${UI.esc(c.part_number)}</b> from ${UI.num(c.system_qty)} to ${UI.num(c.counted_qty)} ${UI.esc(c.unit)}
      (${+c.variance > 0 ? '+' : ''}${UI.num(c.variance)}, ${UI.money(Math.abs(+c.variance_value))}). One stock adjustment is recorded with your name.
      If the stock changed after the count, posting is refused and the part must be counted again.`, 'Purchasing.count_review', { count_id: c.id, decision: 'post' },
      { submit: 'Post adjustment', fields: [{ name: 'notes', label: 'Review note (optional)', col: 12 }] })
  : UI.confirmAction('Reject ' + UI.esc(c.count_ref), 'The stock does not change. The Purchasing Officer is notified with your reason.', 'Purchasing.count_review',
      { count_id: c.id, decision: 'reject' }, { danger: true, submit: 'Reject count', fields: [{ name: 'notes', label: 'Reason', required: true, col: 12 }] });
PurUI.countActs = (c, can) => c.status === 'Submitted' && can.reviewCount && +c.counted_by !== +App.me.user.id
  ? UI.ibtn('post', 'Post adjustment', 'bi-check-lg', 'success') + UI.ibtn('reject', 'Reject count', 'bi-x-lg', 'outline-danger') : '';
PurUI.openCount = async (c, can) => {
  const seq = App.navSeq; let x; try { x = await App.api('Purchasing.count_details', { count_id: c.id }); } catch (e) { return UI.toast(e.message, 'error'); }
  if (seq !== App.navSeq) return;
  const k = x.count;
  const next = k.status === 'Submitted' ? ['admin', 'Admin', 'Review the count: post the adjustment or reject it'] : [null, k.status, k.status === 'Posted' ? 'Adjustment recorded in Stock Movements' : 'Rejected: the stock did not change'];
  const el = UI.drawer(UI.esc(k.count_ref), `${PurUI.nextBox(next)}
    ${UI.kv([['Status', UI.badge(k.status)], ['Part', `<b>${UI.esc(k.part_number)}</b> · ${UI.esc(k.description)}`],
      ['System quantity', UI.num(k.system_qty) + ' ' + UI.esc(k.unit)], ['Counted quantity', UI.num(k.counted_qty) + ' ' + UI.esc(k.unit)],
      ['Discrepancy', `<b>${+k.variance > 0 ? '+' : ''}${UI.num(k.variance)}</b> · ${UI.money(Math.abs(+k.variance_value))} at ${UI.money(k.unit_cost)} each`],
      ['Reason', UI.e(k.reason)], ['Counted by', UI.esc(k.counted_by_name) + ' · ' + UI.datetime(k.counted_at)],
      ['Review', k.reviewed_at ? UI.esc(k.reviewed_by_name) + ' · ' + UI.datetime(k.reviewed_at) + (k.review_notes ? `<div class="cell-sub">${UI.esc(k.review_notes)}</div>` : '') : 'Waiting for the Admin'],
      k.status === 'Posted' ? ['Posted change', UI.num(k.qty_before) + ' → ' + UI.num(k.qty_after) + ' ' + UI.esc(k.unit)] : null])}
    ${UI.h('Evidence')}${UI.docs(x.documents, 'No evidence file attached.')}
    <div class="d-flex flex-wrap gap-2 mt-3" data-cacts>${k.status === 'Submitted' && can.reviewCount && +k.counted_by !== +App.me.user.id
      ? UI.btn('post', 'Post adjustment', 'success', 'bi-check-lg') + UI.btn('reject', 'Reject count', 'outline-danger', 'bi-x-lg') : ''}</div>`, { subtitle: 'Stock count · ' + UI.esc(k.part_number) });
  PurUI.bindDrawerActions(el.querySelector('[data-cacts]'), k, (a, row) => PurUI.countReview(row, a));
};

/** Page toolbar button. */
PurUI.tool = (ctx, label, icon, fn, cls = 'btn-primary') => { const b = document.createElement('button'); b.className = 'btn btn-sm ' + cls; b.innerHTML = `<i class="bi ${icon}"></i> ${label}`; b.onclick = fn; ctx.tools.append(b); };
PurUI.prep = (ctx) => { PurUI.data = ctx.data; return { d: ctx.data, can: ctx.data.can, k: PurUI.kit(ctx.data, ctx.data.can) }; };
PurUI.waitingForMe = (d, can) => (d.orders || []).filter(o => o.status === 'Pending Approval' && ((o.approver_role === 'finance' && can.approveSmall) || (o.approver_role === 'admin' && can.approveBig)));

/* STAGE_B_PURCHASING_UI — who has the next step, derived only from the stored statuses and the approval routing
   (approver_role) of each record. No employee is named; the responsible role is the one the workflow rules assign. */
PurUI.poNext = (o) => {
  const due = Math.max(0, (+o.received_value || 0) - (+o.paid_amount || 0));
  if (o.status === 'Pending Approval') return o.approver_role === 'finance' ? ['finance', 'Finance', 'Approve or reject'] : ['admin', 'Admin', 'Approve or reject'];
  if (['Approved', 'Partially Received'].includes(o.status)) return ['purchasing', 'Purchasing', due > 0 ? 'Receive the rest (Finance can pay ' + UI.money(due) + ' received)' : 'Receive delivered items'];
  if (['Pending Payment', 'Partially Paid'].includes(o.payment_status) && due > 0) return ['finance', 'Finance', 'Record payment of ' + UI.money(due)];
  if (/^Rejected/.test(o.status)) return [null, 'No one', 'Rejected: create a new PO if still needed'];
  return [null, 'Completed', o.payment_status === 'Paid' ? 'Received and paid' : 'Closed'];
};
PurUI.reqNext = (r) => {
  const pos = r.linked_pos || '';
  const openPo = /\((Pending Approval|Approved|Partially Received)\)/.test(pos);
  if (r.status === 'Requested') return ['purchasing', 'Purchasing', 'Check stock and release'];
  if (['Partially Released', 'Not Available'].includes(r.status)) return openPo ? ['purchasing', 'Purchasing', 'Waiting for the linked PO delivery, then release'] : ['purchasing', 'Purchasing', 'Release from stock or create a PO for the shortage'];
  return [null, 'Completed', r.status === 'Released' ? 'All parts released' : 'Cancelled'];
};
PurUI.chip = ([role, who]) => {
  const me = role && UI.role(role);
  return `<span class="wait-chip ${role ? (me ? 'is-you' : '') : 'is-done'}"><i class="bi ${role ? 'bi-person-workspace' : 'bi-check2'}" aria-hidden="true"></i>${UI.esc(me ? 'You (' + who + ')' : who)}</span>`;
};
PurUI.nextBox = (n) => `<div class="ws-next ${n[0] ? '' : 'is-done'}"><i class="bi ${n[0] ? 'bi-arrow-right-circle' : 'bi-check-circle'}" aria-hidden="true"></i><div><b>${n[0] ? 'Waiting for ' + UI.esc(n[1]) : UI.esc(n[1])}</b><div>${UI.esc(n[2])}</div></div></div>`;
PurUI.timeline = (rows) => (rows || []).length ? `<ul class="ws-timeline">${rows.map(h => `<li><b>${UI.esc(h.action.charAt(0).toUpperCase() + h.action.slice(1))}</b> · ${UI.esc(h.actor)}<div class="tl-when">${UI.datetime(h.created_at)}</div>${h.details ? `<div class="cell-sub">${UI.esc(h.details)}</div>` : ''}</li>`).join('')}</ul>` : '<div class="text-muted small">No activity recorded.</div>';
PurUI.bar = (done, total) => `${UI.num(done)} / ${UI.num(total)}<span class="qty-bar" aria-hidden="true"><span style="width:${total ? Math.min(100, Math.round(done / total * 100)) : 0}%"></span></span>`;
PurUI.bindDrawerActions = (root, row, onAct) => root.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => { UI.closeDrawer(); onAct(b.dataset.act, row); }));

/** Parts request drawer: summary, next step, lines (requested / released / outstanding / on hand), linked POs, activity. */
PurUI.openRequest = async (r, k) => {
  const seq = App.navSeq; const x = await App.api('Purchasing.request_details', { request_id: r.id }); const q = x.request;
  if (seq !== App.navSeq) return;   // WS_NAV_STATE_V1: the user moved on while loading; do not open on the new page
  const lines = x.lines.map(l => ({ ...l, outstanding: Math.max(0, l.quantity - l.released_qty) }));
  const short = lines.filter(l => l.outstanding > 0 && l.outstanding > (+l.quantity_on_hand || 0)).length;
  const html = `${PurUI.nextBox(PurUI.reqNext({ ...r, status: q.status }))}
    ${UI.kv([['Status', UI.badge(q.status)], ['Truck', UI.e(q.plate_number) + (q.truck_number ? ' · ' + UI.esc(q.truck_number) : '')], ['Repair', UI.e(q.job_ref)], ['Purpose', UI.e(q.purpose)],
      ['Requested', UI.esc(q.requested_by_name) + ' · ' + UI.datetime(q.created_at)], ['Processed', q.processed_at ? UI.esc(q.processed_by_name || '') + ' · ' + UI.datetime(q.processed_at) : 'Not processed yet'],
      q.notes ? ['Notes', UI.esc(q.notes)] : null])}
    ${UI.h('Parts', short ? `<span class="badge-s red">${short} line(s) short in stock</span>` : '')}
    <div class="table-wrap"><table class="data-table"><thead><tr><th>Part</th><th class="count-col">Requested</th><th class="count-col">Released</th><th class="count-col">Outstanding</th><th class="count-col">On hand</th></tr></thead>
    <tbody>${lines.map(l => `<tr><td><b>${UI.esc(l.part_number)}</b><div class="cell-sub">${UI.esc(l.description)}</div></td><td class="count-col">${UI.num(l.quantity)}</td><td class="count-col">${UI.num(l.released_qty)}</td>
      <td class="count-col">${l.outstanding ? `<b>${UI.num(l.outstanding)}</b>` : '0'}</td><td class="count-col">${UI.num(l.quantity_on_hand)} ${UI.esc(l.unit)}</td></tr>`).join('')}</tbody></table></div>
    ${UI.h('Linked purchase orders')}${(x.orders || []).length ? `<ul class="ws-trucks">${x.orders.map(o => `<li class="${UI.canGo('purchase_orders') ? 'clickable' : ''}" data-po="${o.id}" tabindex="0"><span><b>${UI.esc(o.po_ref)}</b> · ${UI.esc(o.supplier || '')}<div class="cell-sub">${UI.date(o.created_at)} · ${UI.money(o.total_amount)}</div></span><span>${UI.badge(o.status)} ${o.payment_status ? UI.badge(o.payment_status) : ''}</span></li>`).join('')}</ul>` : '<div class="text-muted small">No purchase order is linked to this request.</div>'}
    ${UI.h('Activity')}${PurUI.timeline(x.history)}
    <div class="d-flex flex-wrap gap-2 mt-3">${k.reqActs(r, true)}</div>`;
  const el = UI.drawer(UI.esc(q.request_ref), html, { subtitle: 'Parts request · ' + UI.esc(q.plate_number) });
  const root = el || document.querySelector('.offcanvas.app-drawer');
  root.querySelectorAll('[data-po]').forEach(li => li.addEventListener('click', () => { if (UI.canGo('purchase_orders')) { UI.closeDrawer(); App.go('purchase_orders', { open: li.dataset.po }); } }));
  PurUI.bindDrawerActions(root.querySelector('.d-flex.flex-wrap.gap-2.mt-3'), r, k.reqAct);
};

/** Purchase order drawer: supplier, amounts (total / received / paid / balance), line progress, payments, documents, activity. */
PurUI.openPO = async (o, k) => {
  const seq = App.navSeq; const x = await App.api('Purchasing.po_details', { po_id: o.id }); const p = x.po;
  if (seq !== App.navSeq) return;   // WS_NAV_STATE_V1: the user moved on while loading; do not open on the new page
  const received = x.lines.reduce((a, l) => a + l.received_qty * l.unit_price, 0);
  const row = { ...o, ...p, received_value: received };
  const html = `${PurUI.nextBox(PurUI.poNext(row))}
    ${UI.kv([['Status', UI.badge(p.status) + (p.payment_status ? ' ' + UI.badge(p.payment_status) : '')], ['Supplier', `<b>${UI.esc(p.supplier)}</b>` + [p.supplier_contact, p.supplier_phone, p.supplier_email].filter(Boolean).map(v => `<div class="cell-sub">${UI.esc(v)}</div>`).join('') + (p.supplier_address ? `<div class="cell-sub">${UI.esc(p.supplier_address)}</div>` : '')],
      ['Purpose', UI.e(p.purpose)], ['Parts request', p.request_ref ? UI.esc(p.request_ref) : 'Not linked'],
      ['Requested', UI.esc(p.requested_by_name) + ' · ' + UI.datetime(p.created_at)],
      ['Approval', (p.approver_role === 'finance' ? 'Finance' : 'Admin') + (p.decided_at ? ' · ' + UI.esc(p.decided_by_name || 'System') + ' · ' + UI.datetime(p.decided_at) : ' · not decided yet') + (p.decision_notes ? `<div class="cell-sub">${UI.esc(p.decision_notes)}</div>` : '')],
      p.supplier_invoice_no ? ['Supplier invoice', UI.esc(p.supplier_invoice_no)] : null])}
    ${UI.h('Amounts')}${UI.kv([['PO total', UI.money(p.total_amount)], ['Received value', UI.money(received)], ['Paid', UI.money(p.paid_amount)], ['Received, not yet paid', UI.money(Math.max(0, received - p.paid_amount))]])}
    ${UI.h('Items')}<div class="table-wrap"><table class="data-table"><thead><tr><th>Part</th><th>Received / ordered</th><th class="money-col">Unit price</th><th class="money-col">Amount</th></tr></thead>
    <tbody>${x.lines.map(l => `<tr><td><b>${UI.esc(l.part_number)}</b><div class="cell-sub">${UI.esc(l.description)}</div></td><td class="cell-nowrap">${PurUI.bar(+l.received_qty, +l.quantity)} ${UI.esc(l.unit)}</td><td class="money-col">${UI.money(l.unit_price)}</td><td class="money-col">${UI.money(l.line_total)}</td></tr>`).join('')}</tbody></table></div>
    ${(x.payments || []).length ? UI.h('Payments') + UI.table([['Voucher', 'voucher_number'], ['Released', 'released_on', 'date'], ['Method', 'payment_method'], ['Amount', 'amount', 'money']], x.payments, { plain: true }) : ''}
    ${UI.h('Documents')}${UI.docs(x.documents)}
    ${UI.h('Activity')}${PurUI.timeline(x.history)}
    <div class="d-flex flex-wrap gap-2 mt-3">${k.poActs(row, true)}</div>`;
  const el = UI.drawer(UI.esc(p.po_ref), html, { subtitle: 'Purchase order · ' + UI.esc(p.supplier) });
  const root = el || document.querySelector('.offcanvas.app-drawer');
  PurUI.bindDrawerActions(root.querySelector('.d-flex.flex-wrap.gap-2.mt-3'), row, k.poAct);
};

/** Part drawer: stock figures, 90-day usage and the last 50 movements with their PO / request references. */
PurUI.openPart = async (pt, k) => {
  const seq = App.navSeq; const x = await App.api('Purchasing.part_history', { part_id: pt.id }); const p = x.part;
  if (seq !== App.navSeq) return;   // WS_NAV_STATE_V1: the user moved on while loading; do not open on the new page
  const html = `${UI.kv([['Description', UI.esc(p.description)], ['Category', UI.e(p.category)], ['On hand', `<b>${UI.num(p.quantity_on_hand)} ${UI.esc(p.unit)}</b>` + (+p.reorder_level > 0 && +p.quantity_on_hand <= +p.reorder_level ? ' <span class="badge-s red">Low stock</span>' : '')],
      ['Reorder level', UI.num(p.reorder_level)], ['Unit cost', UI.money(p.unit_cost)], ['Stock value', UI.money(p.stock_value)], ['Issued, last 90 days', UI.num(x.usage.issued_90d) + ' ' + UI.esc(p.unit) + ' in ' + UI.num(x.usage.issues_90d) + ' release(s)'],
      p.warranty_info ? ['Warranty', UI.esc(p.warranty_info)] : null])}
    ${UI.h('Stock movements (latest 50)')}${UI.table([['Date and time', 'created_at', 'datetime'], ['Type', 'movement_type', 'badge'], ['Qty', 'quantity_delta', (v) => (v > 0 ? '+' : '') + UI.num(v)], ['Reference', 'reference', (v, m) => UI.e(v || m.reason)], ['Truck', 'plate_number'], ['By', 'actor']], x.movements, { plain: true, limit: 10, empty: 'No stock movement recorded.' })}
    <div class="d-flex flex-wrap gap-2 mt-3">${k.partActs(pt, true)}</div>`;
  const el = UI.drawer(UI.esc(p.part_number), html, { subtitle: 'Inventory part' });
  const root = el || document.querySelector('.offcanvas.app-drawer');
  PurUI.bindDrawerActions(root.querySelector('.d-flex.flex-wrap.gap-2.mt-3'), pt, k.partAct);
};

/** Opens the record named by ?open=<id> once (links from the Overview, notifications and other drawers). */
PurUI.openFromQuery = (ctx, rows, fn) => { const o = UI.takeQuery(ctx, 'open').open; if (!o) return; const r = rows.find(x => String(x.id) === String(o)); if (r) fn(r); else UI.toast('That record is not in this list.', 'warn'); };
/** Short attention-list panel used by the Purchasing Overview (same look as the Maintenance Overview lists). */
PurUI.list = (title, icon, items, empty, more) => `<section class="panel"><div class="panel-head"><h2 class="panel-title"><i class="bi ${icon} me-1" aria-hidden="true"></i>${UI.esc(title)} <span class="text-muted fw-normal">(${items.length})</span></h2>${more ? `<div class="panel-tools"><button type="button" class="btn btn-link btn-sm p-0" data-go="${UI.esc(more)}">View all</button></div>` : ''}</div>
  <div class="panel-body flush">${items.length ? `<ul class="mo-list mo-scroll">${items.join('')}</ul>` : `<div class="mo-empty">${UI.esc(empty)}</div>`}</div></section>`;
PurUI.item = (go, top, sub, side) => `<li class="mo-item clickable" data-go="${UI.esc(go)}" role="link" tabindex="0"><div class="mo-item-main"><div class="mo-item-top">${top}</div>${sub ? `<div class="mo-item-issue">${sub}</div>` : ''}</div><div class="mo-item-side">${side}</div></li>`;

// ---------------------------------------------------------------- Purchasing Overview (landing page of the Purchasing Officer)
App.page('purchasing_overview', {
  title: 'Purchasing Overview',
  subtitle: 'Monitor parts requests, purchase orders and inventory.',
  render(ctx) {
    const { d, can } = PurUI.prep(ctx), c = d.counts || {}, n = (k) => +c[k] || 0, b = d.budget || {}, st = d.poStatus && !Array.isArray(d.poStatus) ? d.poStatus : {};
    const expired = b.id && !+b.in_period;
    // UI_REFERENCE_V1: four KPI cards -> Quick actions -> left: Needs attention + Recent purchasing activity;
    // right: Purchase order status + Inventory alerts. Every row opens the real record or the filtered list.
    const tiles = [
      { label: 'Pending requests', value: n('requests_open'), note: `need processing · ${n('requests_followup')} to follow up`, icon: 'bi-box-arrow-up', tone: n('requests_open') ? 'amber' : 'gray', go: 'parts_requests?tab=todo' },
      { label: 'Orders to receive', value: n('po_to_receive'), note: 'approved, delivery not complete', icon: 'bi-truck', tone: n('po_to_receive') ? 'blue' : 'gray', go: 'purchase_orders?tab=receive' },
      { label: 'Low stock items', value: n('low_stock'), note: 'at or below the reorder level', icon: 'bi-exclamation-triangle', tone: n('low_stock') ? 'red' : 'gray', go: 'inventory?stock=low' },
      { label: 'Open orders', value: n('po_pending') + n('po_to_receive'), note: `${n('po_pending')} awaiting approval`, icon: 'bi-cart-check', tone: n('po_pending') + n('po_to_receive') ? 'blue' : 'gray', go: 'purchase_orders?tab=' + (n('po_pending') ? 'approval' : 'receive') },
    ];
    const reqs = d.requests || [];
    const attention = [
      ...reqs.filter(r => r.status === 'Requested').map(r => UI.wsEntry('parts_requests?open=' + r.id, `<b>${UI.esc(r.request_ref)}</b> <span class="mo-ref">${UI.esc(r.plate_number)}${r.job_ref ? ' · ' + UI.esc(r.job_ref) : ''}</span>`,
        'Check stock and release · ' + UI.esc(r.items || ''), UI.badge(r.status))),
      ...(d.receiving || []).map(o => UI.wsEntry('purchase_orders?open=' + o.id, `<b>${UI.esc(o.po_ref)}</b> <span class="mo-ref">${UI.esc(o.supplier || '')}</span>`,
        'Receive delivered items · ' + UI.money(o.total_amount), UI.badge(o.status))),
      ...(d.approvals || []).filter(o => o.status !== 'Pending Approval').map(o => UI.wsEntry('purchase_orders?open=' + o.id, `<b>${UI.esc(o.po_ref)}</b> <span class="mo-ref">${UI.esc(o.supplier || '')}</span>`,
        'Rejected for insufficient budget on ' + UI.date(o.decided_at) + ': create a new PO if still needed', UI.badge(o.status))),
    ];
    const refGo = (m) => m.reference_type === 'purchase_order' ? 'purchase_orders?open=' + m.reference_id : m.reference_type === 'part_request' ? 'parts_requests?open=' + m.reference_id
      : m.reference_type === 'stock_count' ? 'inventory?view=counts&open=' + m.reference_id : 'stock_movements';
    const activity = (Array.isArray(d.activity) ? d.activity : []).map(m => UI.wsEntry(refGo(m), `<b>${UI.esc(m.movement_type)}</b> · ${UI.esc(m.part_number)} <span class="mo-ref">${+m.quantity_delta > 0 ? '+' : ''}${UI.num(m.quantity_delta)} ${UI.esc(m.unit)}</span>`,
      `${m.ref ? UI.esc(m.ref) + ' · ' : ''}${UI.esc(m.actor)} · ${UI.datetime(m.created_at)}`, ''));
    const budgetRej = (d.approvals || []).filter(o => o.status !== 'Pending Approval').length;
    const poRows = [
      UI.wsEntry('purchase_orders?tab=approval', '<b>Pending approval</b>', 'With Finance or the Admin', `<span class="badge-s ${+st.pending_approval ? 'blue' : 'gray'}">${UI.num(+st.pending_approval || 0)}</span>`),
      UI.wsEntry('purchase_orders?tab=receive', '<b>Awaiting delivery</b>', `Approved, not fully received${+st.partially_received ? ' · ' + UI.num(st.partially_received) + ' partially received' : ''}`, `<span class="badge-s ${+st.awaiting_delivery ? 'blue' : 'gray'}">${UI.num(+st.awaiting_delivery || 0)}</span>`),
      UI.wsEntry('purchase_orders?tab=payment', '<b>Awaiting payment</b>', 'Finance records the payment', `<span class="badge-s ${+st.awaiting_payment ? 'amber' : 'gray'}">${UI.num(+st.awaiting_payment || 0)}</span>`),
      budgetRej ? UI.wsEntry('purchase_orders?tab=rejected', '<b>Rejected for budget</b>', 'In the last 30 days', `<span class="badge-s red">${UI.num(budgetRej)}</span>`) : '',
    ].filter(Boolean);
    const budgetFoot = b.id ? `Purchasing budget: <b>${UI.money(b.available)}</b> available of ${UI.money(b.amount)} (${UI.date(b.period_start)} – ${UI.date(b.period_end)})`
        + (expired ? '<div class="text-danger">The budget period has ended: Finance must set a new budget before POs can be approved.</div>'
          : +b.amount && (+b.spent_amount + +b.reserved_amount) / b.amount >= 0.8 ? `<div class="text-warning">${Math.round((+b.spent_amount + +b.reserved_amount) / b.amount * 100)}% of the budget is paid or reserved.</div>` : '')
      : 'No active purchasing budget: POs cannot be approved until Finance sets one.';
    const alerts = [
      ...(d.lowStock || []).map(p => UI.wsEntry('inventory?open=' + p.id, `<b>${UI.esc(p.part_number)}</b> <span class="mo-ref">${UI.esc(p.description)}</span>`,
        `On hand ${UI.num(p.quantity_on_hand)} ${UI.esc(p.unit)} · reorder level ${UI.num(p.reorder_level)}`, '<span class="badge-s red">Low stock</span>')),
      ...reqs.filter(r => r.status !== 'Requested').map(r => UI.wsEntry('parts_requests?open=' + r.id, `<b>${UI.esc(r.request_ref)}</b> <span class="mo-ref">${UI.esc(r.plate_number)}</span>`,
        'Outstanding shortage · ' + UI.esc(r.items || ''), UI.badge(r.status))),
    ];
    ctx.el.innerHTML = `<div class="maint-overview mp-ws">${UI.kpiTiles(tiles, 'ws-kpi4')}
      ${UI.quickBar([can.stock ? { label: 'New purchase order', icon: 'bi-cart-plus', act: 'newpo', primary: true } : null,
        { label: 'Parts requests', icon: 'bi-box-arrow-up', go: 'parts_requests' }, { label: 'Purchase orders', icon: 'bi-cart-check', go: 'purchase_orders' },
        { label: 'Inventory', icon: 'bi-boxes', go: 'inventory' }, { label: 'Reports', icon: 'bi-graph-up', go: 'purchasing_reports' }])}
      <div class="ws-grid2">
        ${UI.wsPanel('Needs attention', 'bi-flag', UI.wsList(attention, 'Nothing is waiting for Purchasing right now.'), { cls: 'ws-l1', count: attention.length, go: 'parts_requests?tab=todo' })}
        ${UI.wsPanel('Purchase order status', 'bi-cart-check', UI.wsList(poRows, ''), { cls: 'ws-r1', go: 'purchase_orders?tab=all', foot: budgetFoot })}
        ${UI.wsPanel('Recent purchasing activity', 'bi-activity', UI.wsList(activity, 'No stock movement recorded yet.'), { cls: 'ws-l2', go: 'stock_movements' })}
        ${UI.wsPanel('Inventory alerts', 'bi-exclamation-triangle', UI.wsList(alerts, 'No low stock and no outstanding shortage.'), { cls: 'ws-r2', count: alerts.length, go: 'inventory?stock=low' })}
      </div></div>`;
    ctx.el.querySelector('[data-qa="newpo"]')?.addEventListener('click', () => App.go('purchase_orders', { new: 1 }));
  },
});

// ---------------------------------------------------------------- Parts Requests (stock-out)
App.page('parts_requests', {
  title: 'Parts Requests',
  subtitle: 'Review requests and track parts availability.',
  render(ctx) {
    const { d, k } = PurUI.prep(ctx), q = ctx.query, all = d.requests || [];
    const TABS = { todo: ['To process', r => r.status === 'Requested'], followup: ['Follow up', r => ['Partially Released', 'Not Available'].includes(r.status)],
      released: ['Released', r => r.status === 'Released'], cancelled: ['Cancelled', r => r.status === 'Cancelled'], all: ['All', () => true] };
    const tab = TABS[q.tab] ? q.tab : 'todo';
    const cnt = (t) => all.filter(TABS[t][1]).length;
    const trucks = [...new Set(all.map(r => r.plate_number))].sort();
    const rows = all.filter(TABS[tab][1]).filter(r => (!q.truck || r.plate_number === q.truck) && (!q.po || (q.po === 'linked' ? !!r.linked_pos : !r.linked_pos)));
    // UI_REFERENCE_V1: status tabs with counts, compact filters with Reset, then the list (no separate KPI row).
    ctx.el.innerHTML = `${UI.statusTabs('parts_requests', q, 'tab', Object.entries(TABS).map(([key, [label]]) => [key, label, cnt(key)]), tab)}
      ${UI.generalFilters(ctx, [{ name: 'truck', label: 'Trucks', options: trucks }, { name: 'po', label: 'PO link', all: 'With or without PO', options: [['linked', 'Has a linked PO'], ['none', 'No linked PO']] }])}`;
    const box = document.createElement('div'); ctx.el.append(box);
    const open = (r) => PurUI.openRequest(r, k);
    box.innerHTML = UI.table([
      ['Request', 'request_ref', (v, r) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.date(r.created_at)}</div>`],
      ['Truck / repair', 'plate_number', (v, r) => `${UI.esc(v)}${r.job_ref ? `<div class="cell-sub">${UI.esc(r.job_ref)}</div>` : ''}`],
      ['Parts', 'items', (v) => `<span class="cell-clamp" title="${UI.esc(v)}">${UI.esc(v)}</span>`],
      ['Released', 'released_units', (v, r) => `<span class="cell-nowrap">${PurUI.bar(+v, +r.requested_units)}</span>`],
      ['Status', 'status', 'badge'],
      ['Waiting for', 'status', (v, r) => { const n = PurUI.reqNext(r); return PurUI.chip(n) + `<div class="cell-sub">${UI.esc(n[2])}</div>`; }],
      ['Linked PO', 'linked_pos', (v) => v ? `<span class="cell-clamp">${UI.esc(v)}</span>` : '<span class="text-muted">None</span>']], rows,
      { title: TABS[tab][0] + ' (' + rows.length + ')', showAllColumns: true, directActions: true, actions: (r) => k.reqActs(r), onAction: k.reqAct, rowClick: open,
        empty: 'No parts request in this list.' });
    PurUI.openFromQuery(ctx, all, open);
  },
});

// ---------------------------------------------------------------- Purchase Orders (stock-in)
App.page('purchase_orders', {
  title: 'Purchase Orders',
  subtitle: 'Follow approvals and received deliveries.',
  render(ctx) {
    const { d, can, k } = PurUI.prep(ctx), q = ctx.query, all = d.orders || [];
    if (can.stock) PurUI.tool(ctx, 'New purchase order', 'bi-cart-plus', () => PurUI.newPO());
    const TABS = { approval: ['Awaiting approval', o => o.status === 'Pending Approval'], receive: ['To receive', o => ['Approved', 'Partially Received'].includes(o.status)],
      payment: ['Awaiting payment', o => ['Pending Payment', 'Partially Paid'].includes(o.payment_status) && (+o.received_value || 0) > (+o.paid_amount || 0)],
      done: ['Completed', o => ['Received', 'Closed'].includes(o.status) && !(['Pending Payment', 'Partially Paid'].includes(o.payment_status))],
      rejected: ['Rejected', o => /^Rejected/.test(o.status)], all: ['All', () => true] };
    const mine = PurUI.waitingForMe(d, can);
    const cnt = (t) => all.filter(TABS[t][1]).length;
    // Default tab: what waits for this user; an empty 'To receive' list falls back to All.
    const tab = TABS[q.tab] ? q.tab : (mine.length ? 'approval' : (can.stock && cnt('receive') ? 'receive' : 'all'));
    const suppliers = [...new Set(all.map(o => o.supplier).filter(Boolean))].sort();
    const rows = all.filter(TABS[tab][1]).filter(o => (!q.supplier || o.supplier === q.supplier) && (!q.payment || o.payment_status === q.payment));
    // UI_REFERENCE_V1: status tabs with counts, compact filters with Reset, then the list (no separate KPI row).
    ctx.el.innerHTML = `${UI.statusTabs('purchase_orders', q, 'tab', Object.entries(TABS).map(([key, [label]]) => [key, key === 'approval' && mine.length ? label + ' (' + mine.length + ' for you)' : label, cnt(key)]), tab)}
      ${UI.generalFilters(ctx, [{ name: 'supplier', label: 'Suppliers', options: suppliers }, { name: 'payment', label: 'Payment statuses', options: [...new Set(all.map(o => o.payment_status).filter(Boolean))].sort() }])}`;
    const box = document.createElement('div'); ctx.el.append(box);
    const open = (o) => PurUI.openPO(o, k);
    box.innerHTML = UI.table([
      ['PO', 'po_ref', (v, o) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.date(o.created_at)}</div>`],
      ['Supplier', 'supplier', (v) => `<span class="cell-clamp">${UI.esc(v)}</span>`],
      ['Total', 'total_amount', 'money'],
      ['Received', 'received_value', 'money'],
      ['Status', 'status', 'badge'], ['Payment', 'payment_status', 'badge'],
      ['Waiting for', 'status', (v, o) => { const n = PurUI.poNext(o); return PurUI.chip(n) + `<div class="cell-sub">${UI.esc(n[2])}</div>`; }]], rows,
      { title: TABS[tab][0] + ' (' + rows.length + ')', showAllColumns: true, directActions: true, actions: (o) => k.poActs(o), onAction: k.poAct, rowClick: open,
        empty: 'No purchase order in this list.' });
    const once = UI.takeQuery(ctx, 'new');
    if (once.new && can.stock) PurUI.newPO();
    PurUI.openFromQuery(ctx, all, open);
  },
});

// ---------------------------------------------------------------- Inventory
// What is on hand and what it is worth, plus physical stock counts (STOCK_COUNT_RECONCILIATION_V1).
// Stock changes only through PO receiving (stock in), parts-request release (stock out) or a posted stock count.
App.page('inventory', {
  title: 'Inventory',
  subtitle: 'See current stock and item availability.',
  render(ctx) {
    const { d, can, k } = PurUI.prep(ctx), q = ctx.query;
    if (can.stock) PurUI.tool(ctx, 'Add part', 'bi-plus-lg', () => k.partForm(), 'btn-outline-secondary');
    if (can.count && d.countsReady) PurUI.tool(ctx, 'Record stock count', 'bi-clipboard-check', () => PurUI.countForm(d));
    const parts = d.parts || [], active = parts.filter(p => +p.active), low = active.filter(p => +p.low_stock), out = active.filter(p => +p.quantity_on_hand <= 0);
    const counts = d.counts || [], pending = counts.filter(c => c.status === 'Submitted');
    const view = q.view === 'counts' ? 'counts' : 'parts';
    const cats = [...new Set(parts.map(p => p.category).filter(Boolean))].sort();
    const link = (extra) => 'inventory?' + new URLSearchParams(Object.fromEntries(Object.entries({ ...q, ...extra }).filter(([, v]) => v)));
    // UI_REFERENCE_V1: stock tabs (?stock=) and the Stock counts tab (?view=counts) instead of a KPI row.
    const stockKey = view === 'counts' ? '' : (['low', 'out', 'inactive', 'all'].includes(q.stock) ? q.stock : 'active');
    const tabBtn = (spec, label, n, on) => `<button type="button" role="tab" aria-selected="${on}" class="tab ${on ? 'active' : ''}" data-go="${UI.esc(spec)}">${UI.esc(label)} <span class="tab-count">${UI.num(n)}</span></button>`;
    ctx.el.innerHTML = `<div class="line-tabs ws-status-tabs no-print" role="tablist">
        ${tabBtn(link({ view: '', stock: '' }), 'Active parts', active.length, stockKey === 'active')}${tabBtn(link({ view: '', stock: 'low' }), 'Low stock', low.length, stockKey === 'low')}
        ${tabBtn(link({ view: '', stock: 'out' }), 'Out of stock', out.length, stockKey === 'out')}${tabBtn(link({ view: '', stock: 'inactive' }), 'Inactive', parts.length - active.length, stockKey === 'inactive')}
        ${d.countsReady ? tabBtn(link({ view: 'counts', category: '', stock: '' }), 'Stock counts', pending.length, view === 'counts') : ''}</div>
      <div class="small text-muted mb-2">Inventory value of active parts: <b>${UI.money(active.reduce((a, p) => a + +p.stock_value, 0))}</b> (on hand × recorded unit cost).
        ${d.countsReady && pending.length ? `${UI.num(pending.length)} stock count(s) wait for the Admin.` : ''}</div>
      ${d.countsReady ? '' : `<div class="alert alert-secondary small py-2"><i class="bi bi-lock me-1"></i> Direct stock adjustments are disabled. Stock changes only when a purchase order is received or parts are released.
        Physical count corrections need the stock count and review step (database update 2026-11-12_13, not applied yet).</div>`}`;
    const box = document.createElement('div'); ctx.el.append(box);
    if (view === 'counts') {
      const st = ['Submitted', 'Posted', 'Rejected'].includes(q.count) ? q.count : '';
      const rows = counts.filter(c => !st || c.status === st);
      box.innerHTML = UI.generalFilters(ctx, [{ name: 'count', label: 'Count statuses', options: [['Submitted', 'Waiting for review'], ['Posted', 'Posted'], ['Rejected', 'Rejected']] }])
        + UI.table([
          ['Count', 'count_ref', (v, c) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.datetime(c.counted_at)}</div>`],
          ['Part', 'part_number', (v, c) => `<b>${UI.esc(v)}</b><div class="cell-sub cell-clamp">${UI.esc(c.description)}</div>`],
          ['System', 'system_qty', 'num'], ['Counted', 'counted_qty', 'num'],
          ['Discrepancy', 'variance', (v, c) => `<span class="cell-nowrap ${+v < 0 ? 'text-danger' : ''}"><b>${+v > 0 ? '+' : ''}${UI.num(v)}</b> ${UI.esc(c.unit)}</span><div class="cell-sub">${UI.money(Math.abs(+c.variance_value))}</div>`],
          ['Status', 'status', 'badge'],
          ['Waiting for', 'status', (v) => PurUI.chip(v === 'Submitted' ? ['admin', 'Admin'] : [null, 'Completed'])],
          ['Counted by', 'counted_by_name']], rows,
          { title: 'Stock counts (' + rows.length + ')', showAllColumns: true, directActions: true, actions: (c) => PurUI.countActs(c, can) + UI.ibtn('view', 'Open count', 'bi-eye'),
            onAction: (a, c) => a === 'view' ? PurUI.openCount(c, can) : PurUI.countReview(c, a), rowClick: (c) => PurUI.openCount(c, can),
            empty: 'No stock count recorded yet.' });
      const once = UI.takeQuery(ctx, 'open');
      if (once.open) { const c = counts.find(x => String(x.id) === String(once.open)); if (c) PurUI.openCount(c, can); }
      return;
    }
    const rows = parts.filter(p => (!q.category || p.category === q.category)
      && (stockKey === 'all' || (stockKey === 'low' ? +p.active && +p.low_stock : stockKey === 'out' ? +p.active && +p.quantity_on_hand <= 0 : stockKey === 'inactive' ? !+p.active : +p.active)));
    const openCount = new Set(pending.map(c => +c.part_id));
    box.innerHTML = UI.generalFilters(ctx, [{ name: 'category', label: 'Categories', options: cats }])
      + UI.table([
        ['Part no.', 'part_number', (v, p) => `<b>${UI.esc(v)}</b>${+p.active ? '' : ' <span class="badge-s">Inactive</span>'}`],
        ['Description', 'description', (v, p) => `<span class="cell-clamp">${UI.esc(v)}</span>${p.category ? `<div class="cell-sub">${UI.esc(p.category)}</div>` : ''}`],
        ['On hand', 'quantity_on_hand', (v, p) => `<span class="cell-nowrap"><b>${UI.num(v)}</b> ${UI.esc(p.unit)}${+p.low_stock ? ' <span class="badge-s red">Low</span>' : ''}${openCount.has(+p.id) ? ' <span class="badge-s amber" title="A stock count is waiting for review">Count pending</span>' : ''}</span>`],
        ['Reorder level', 'reorder_level', 'num'], ['Unit cost', 'unit_cost', 'money'], ['Stock value', 'stock_value', 'money']], rows,
        { title: ({ active: 'Active parts', low: 'Low stock', out: 'Out of stock', inactive: 'Inactive parts', all: 'All parts' }[stockKey]) + ' (' + rows.length + ')', showAllColumns: true, directActions: true, actions: (p) => k.partActs(p) + UI.ibtn('view', 'Open part', 'bi-eye'),
          onAction: (a, p) => a === 'view' ? PurUI.openPart(p, k) : k.partAct(a, p), rowClick: (p) => PurUI.openPart(p, k), empty: 'No part matches.' });
    PurUI.openFromQuery(ctx, parts, (p) => PurUI.openPart(p, k));
  },
});

// ---------------------------------------------------------------- Stock Movements
App.page('stock_movements', {
  title: 'Stock Movements',
  subtitle: 'Review stock changes and their source transactions.',
  render(ctx) {
    const { d } = PurUI.prep(ctx), q = ctx.query, all = d.movements || [];
    const parts = [...new Set(all.map(m => m.part_number))].sort();
    const rows = all.filter(m => (!q.type || m.movement_type === q.type) && (!q.part || m.part_number === q.part)
      && (!q.from || String(m.created_at).slice(0, 10) >= q.from) && (!q.to || String(m.created_at).slice(0, 10) <= q.to));
    const sum = (t) => rows.filter(m => m.movement_type === t).reduce((a, m) => a + Math.abs(+m.quantity_delta), 0);
    const val = (t) => rows.filter(m => m.movement_type === t).reduce((a, m) => a + Math.abs(+m.quantity_delta) * +m.unit_cost, 0);
    // UI_REFERENCE_V1: movement-type tabs (?type=) with counts, filters with Reset, and a one-line total that follows the filters.
    const base = all.filter(m => (!q.part || m.part_number === q.part) && (!q.from || String(m.created_at).slice(0, 10) >= q.from) && (!q.to || String(m.created_at).slice(0, 10) <= q.to));
    const types = ['Stock In', 'Stock Out', 'Adjustment'];
    ctx.el.innerHTML = `${UI.statusTabs('stock_movements', q, 'type', [['', 'All', base.length], ...types.map(t => [t, t, base.filter(m => m.movement_type === t).length])], types.includes(q.type) ? q.type : '')}
      ${UI.generalFilters(ctx, [{ name: 'part', label: 'Parts', options: parts }, { name: 'from', label: 'From date', type: 'date' }, { name: 'to', label: 'To date', type: 'date' }])}
      <div class="small text-muted mb-2">Shown: stock in <b>${UI.num(sum('Stock In'))}</b> units (${UI.money(val('Stock In'))}) · stock out <b>${UI.num(sum('Stock Out'))}</b> units (${UI.money(val('Stock Out'))})
        · ${UI.num(rows.filter(m => m.movement_type === 'Adjustment').length)} adjustment(s). Values use the unit cost recorded on each movement.</div>`;
    const box = document.createElement('div'); ctx.el.append(box);
    const ref = (m) => m.reference_type === 'purchase_order' ? 'purchase_orders?open=' + m.reference_id : m.reference_type === 'part_request' ? 'parts_requests?open=' + m.reference_id
      : m.reference_type === 'stock_count' ? 'inventory?view=counts&open=' + m.reference_id : '';
    const refLabel = { purchase_order: ['Open purchase order', 'bi-cart-check'], part_request: ['Open parts request', 'bi-box-arrow-up'], stock_count: ['Open stock count', 'bi-clipboard-check'] };
    box.innerHTML = UI.table([
      ['Date and time', 'created_at', 'datetime'],
      ['Part', 'part_number', (v, m) => `<b>${UI.esc(v)}</b><div class="cell-sub cell-clamp">${UI.esc(m.description)}</div>`],
      ['Type', 'movement_type', 'badge'], ['Qty', 'quantity_delta', (v) => `<span class="cell-nowrap">${v > 0 ? '+' : ''}${UI.num(v)}</span>`],
      ['Reason', 'reason', (v) => `<span class="cell-clamp">${UI.esc(v)}</span>`], ['Truck', 'plate_number'], ['By', 'actor']], rows,
      { title: 'Movements (' + rows.length + ')', showAllColumns: true, directActions: true,
        actions: (m) => ref(m) && UI.canGo(ref(m)) ? UI.ibtn('ref', refLabel[m.reference_type][0], refLabel[m.reference_type][1]) : '',
        onAction: (a, m) => UI.goTo(ref(m)), empty: 'No stock movement matches.' });
  },
});

// ---------------------------------------------------------------- Purchasing Reports (PURCHASING_ANALYTICS_V1, PURCHASING_REPORTS_V2)
// KPIs · Stock movement value (two-line chart; a point opens a floating period popover) · Most used parts (+ replenishment
// suggestions) · Orders & stock summary (six metrics, each with a traceable details drawer that reconciles to the figure).
// Data: Analytics -> Purchasing::analytics (SQL over recorded transactions; rules in Purchasing::partRules / flowRules).
// Nothing on this page writes.
const PurReport = {
  charts: [],
  sug(items, empty) {
    return items && items.length ? `<ul class="ws-sug">${items.map(x => `<li>${UI.esc(x.text)}${x.go && UI.canGo(x.go) ? ` <button type="button" class="btn btn-link btn-sm" data-go="${UI.esc(x.go)}">${UI.esc(x.label || 'Open')}</button>` : ''}</li>`).join('')}</ul>`
      : `<div class="ws-sug-empty">${UI.esc(empty)}</div>`;
  },
  ref(m) { return m.reference_type === 'purchase_order' ? 'purchase_orders?open=' + m.reference_id : m.reference_type === 'part_request' ? 'parts_requests?open=' + m.reference_id : ''; },
  /** Details drawer: basis text, source records, and the total that equals the summary figure. */
  drawer(title, basis, tableHtml, totalLabel, total) {
    UI.drawer(UI.esc(title), `<p class="small text-muted mb-2">${basis}</p>${tableHtml}
      <div class="mr-recon"><span>${UI.esc(totalLabel)}</span><b>${total}</b></div>`, { subtitle: 'How this figure is calculated' });
  },
};

App.page('purchasing_reports', {
  title: 'Purchasing Reports',
  subtitle: 'Stock, parts consumption and purchasing activity.',
  render(ctx) {
    PurReport.charts.forEach(ch => { try { ch.destroy(); } catch { /* gone */ } }); PurReport.charts = [];
    const d = ctx.data, per = d.period || {}, b = d.buckets || [], flow = d.flow || null, det = d.detail || {};
    const unit = { day: 'day', week: 'week', month: 'month' }[d.granularity] || 'period';
    const mv = Object.fromEntries((d.movements || []).map(m => [m.movement_type, m]));
    const m = (t, k) => +(mv[t]?.[k] || 0);
    const sp = d.spend || {}, sn = d.stockNow || {}, oo = d.openOrders && !Array.isArray(d.openOrders) ? d.openOrders : {};
    const recs = d.recommendations || [], short = d.shortRequests || [], follow = d.followUp || [], counts = d.countsPending || [], ledger = d.ledger || [];
    const lowN = recs.filter(r => r.rule === 'reorder').length;
    const link = (go, label) => UI.canGo(go) ? ` <button type="button" class="btn btn-link btn-sm" data-go="${UI.esc(go)}">${UI.esc(label)}</button>` : '';
    const range = `from=${per.from}&to=${per.to}`;
    const tiles = [
      { label: 'Inventory value', value: UI.money(sn.value), note: `${UI.num(sn.units)} units in ${UI.num(sn.parts)} active parts (now)`, tone: 'gray', go: 'inventory' },
      { label: 'Low stock items', value: UI.num(lowN), note: 'at or below the reorder level (now)', tone: lowN ? 'red' : 'gray', go: 'inventory?stock=low' },
      { label: 'Parts released', value: UI.num(m('Stock Out', 'units')) + ' units', note: UI.money(m('Stock Out', 'value')) + ' issued to repairs in the period', tone: 'gray', go: 'stock_movements?type=Stock Out&' + range },
      { label: 'Pending orders', value: UI.num((+oo.pending || 0) + (+oo.receiving || 0)), note: `now · ${UI.num(+oo.pending || 0)} awaiting approval · ${UI.num(+oo.receiving || 0)} to receive`, tone: (+oo.pending || 0) + (+oo.receiving || 0) ? 'amber' : 'gray', go: 'purchase_orders?tab=' + (+oo.pending ? 'approval' : 'receive') },
    ];

    // ---- stock movement value chart (left) and Most used parts (right)
    const hasFlow = flow && (flow.in.some(Number) || flow.out.some(Number));
    const chartBody = !flow ? '<div class="mo-empty text-danger">The stock movements could not be loaded.</div>'
      : hasFlow ? `<div class="mr-chart pr-chart"><canvas id="prFlow" tabindex="0" aria-label="Stock in and stock out value by ${unit}. Use the left and right arrow keys to choose a ${unit} and Enter to open its details."></canvas></div>
          <div class="mr-note">Value = quantity × unit cost recorded on each movement (receipts from POs, releases to parts requests). Select a point to see that ${unit}'s transactions.</div>`
      : '<div class="mo-empty"><i class="bi bi-bar-chart"></i> No stock was received or issued in this period.</div>';
    const flowTips = [
      ...follow.map(o => ({ text: `${o.po_ref} (${o.supplier || ''}) was approved ${o.days} days ago and is not fully received: follow up the delivery.`, go: 'purchase_orders?open=' + o.id, label: 'Open PO' })),
      ...short.map(r => ({ text: `${r.request_ref} for ${r.plate_number} is still waiting for parts${+r.open_pos ? '; its purchase order is not fully delivered yet.' : ' and has no purchase order yet.'}`, go: 'parts_requests?open=' + r.id, label: 'Open request' })),
      ...counts.map(c => ({ text: `${c.count_ref} (${c.part_number}) is waiting for the Admin's review.`, go: 'inventory?view=counts&open=' + c.id, label: 'Open count' })),
      ...ledger.map(l => ({ text: `${l.part_number}: on hand does not match its recorded movements. Investigate before any count is posted.`, go: 'inventory?open=' + l.id, label: 'Part' })),
    ];
    const top = (d.topUsed || []).slice(0, 6);
    const rank = UI.wsRank(top.map(p => ({ label: p.part_number, sub: `${p.description} · on hand ${UI.num(p.quantity_on_hand)}`, value: +p.units, display: UI.num(p.units) + ' ' + (p.unit || ''), go: 'inventory?open=' + p.id })),
      'No part was released in this period.');

    // ---- Orders & stock summary: six figures, each with its details drawer
    const unrec = det.outstanding || [], shortLines = det.shortLines || [], sup = (d.bySupplier || [])[0];
    const sumCards = [
      ['approved', 'POs approved in the period', UI.money(sp.approved), `${UI.num(sp.approved_n)} PO(s) · period`],
      ['received', 'Received into stock', UI.money(d.received), 'period'],
      ['paid', 'Paid on those POs', UI.money(sp.paid), 'to date, POs approved in the period'],
      ['outstanding', 'Ordered, not yet received', UI.money(unrec.reduce((a, o) => a + +o.value, 0)), `now · ${UI.num(new Set(unrec.map(o => o.id)).size)} PO(s)`],
      ['short', 'Requests short of stock', UI.num(short.length), 'now'],
      ['supplier', 'Top supplier in the period', sup ? UI.esc(sup.supplier) : 'None', sup ? UI.money(sup.total) + ' approved' : 'period'],
    ];
    const summary = `<div class="pr-sum">${sumCards.map(([k, l, v, n]) => `<div class="pr-sum-card"><span>${UI.esc(l)}</span><b title="${UI.esc(String(v).replace(/<[^>]+>/g, ''))}">${v}</b><small>${UI.esc(n)}</small>
      <button type="button" class="btn btn-link btn-sm p-0" data-sum="${k}" aria-label="Details: ${UI.esc(l)}">Details</button></div>`).join('')}</div>`;

    ctx.el.innerHTML = `${UI.wsPeriod('purchasing_reports', per)}${UI.kpiTiles(tiles, 'ws-kpi4')}
      <div class="ws-grid2 ws-report-row">
        ${UI.wsPanel('Stock in vs stock out', 'bi-graph-up', chartBody, { cls: 'ws-l1', tipHtml: PurReport.sug(flowTips, 'No delivery, shortage or stock discrepancy needs follow-up.') })}
        ${UI.wsPanel('Most used parts', 'bi-trophy', rank, { cls: 'ws-r1', tipHtml: PurReport.sug(d.partTips, 'No part is low on stock right now.'), foot: 'Units released to repairs in the period.' })}
      </div>
      ${UI.wsPanel('Orders & stock summary', 'bi-clipboard-data', summary, { go: 'purchase_orders?tab=all', goLabel: 'Open records',
        foot: 'Approvals, receipts and payments describe the same orders at different stages; they are not added together.' })}`;
    UI.bindTips(ctx.el); UI.bindPeriod(ctx.el);

    // ---- summary details drawers (each total equals the figure shown)
    const T = (cols, rows, empty) => UI.table(cols, rows, { plain: true, limit: 12, empty });
    const poCol = ['PO', 'po_ref', (v, r) => `<b>${UI.esc(v)}</b>`];
    ctx.el.querySelectorAll('[data-sum]').forEach(btn => btn.addEventListener('click', () => {
      const k = btn.dataset.sum, period = UI.date(per.from) + ' – ' + UI.date(per.to);
      if (k === 'approved') PurReport.drawer('POs approved in the period', `Purchase orders approved (status Approved, Partially Received, Received or Closed) with an approval date from ${period}. Value = approved PO total.`,
        T([poCol, ['Supplier', 'supplier'], ['Approved', 'decided_at', 'date'], ['Status', 'status', 'badge'], ['Approved value', 'total_amount', 'money']], det.approved || [], 'No PO was approved in this period.'),
        `Total of ${UI.num((det.approved || []).length)} PO(s)`, UI.money((det.approved || []).reduce((a, o) => a + +o.total_amount, 0)));
      if (k === 'received') { const rows = (flow ? flow.moves.flat() : []).filter(x => x.movement_type === 'Stock In');
        PurReport.drawer('Received into stock', `Stock In movements recorded from ${period}. Value = quantity received × unit cost recorded on the movement.`,
          T([['Movement', 'id', (v, r) => '#' + v + `<div class="cell-sub">${UI.datetime(r.created_at)}</div>`], ['PO', 'ref'], ['Part', 'part_number'], ['Qty', 'qty', (v, r) => UI.num(v) + ' ' + UI.esc(r.unit)], ['Unit cost', 'unit_cost', 'money'], ['Value', 'value', 'money']], rows, 'No stock was received in this period.'),
          `Total of ${UI.num(rows.length)} receipt(s)`, UI.money(rows.reduce((a, x) => a + +x.value, 0))); }
      if (k === 'paid') {
        const pays = det.payments;
        PurReport.drawer('Paid on those POs', `Amount paid to date on the POs approved from ${period} (payments recorded by Finance).`
            + (pays ? '' : ' Payment vouchers are visible to Finance, Admin and Audit; the paid amount per PO is shown here.'),
          (pays ? T([['Voucher', 'voucher_number'], ['PO', 'po_ref'], ['Paid on', 'released_on', 'date'], ['Method', 'payment_method'], ['Amount', 'amount', 'money']], pays, 'No payment recorded.') : '')
            + T([poCol, ['Supplier', 'supplier'], ['Payment status', 'payment_status', 'badge'], ['Paid to date', 'paid_amount', 'money']], det.paidPos || [], 'No payment recorded on these POs.'),
          'Total paid', UI.money((det.paidPos || []).reduce((a, o) => a + +o.paid_amount, 0)));
      }
      if (k === 'outstanding') PurReport.drawer('Ordered, not yet received', 'Current snapshot: approved or partially received POs, per line still to be delivered. Remaining value = outstanding quantity × PO unit price.',
        T([poCol, ['Part', 'part_number'], ['Ordered', 'quantity', 'num'], ['Received', 'received_qty', 'num'], ['Outstanding', 'outstanding', (v, r) => UI.num(v) + ' ' + UI.esc(r.unit)], ['Remaining value', 'value', 'money']], unrec, 'Every approved PO is fully received.'),
        `Total of ${UI.num(unrec.length)} line(s)`, UI.money(unrec.reduce((a, o) => a + +o.value, 0)));
      if (k === 'short') PurReport.drawer('Requests short of stock', 'Current snapshot: partially released or not-available requests with a line needing more than is on hand now. Shortage = still required − available.',
        T([['Request', 'request_ref', (v, r) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.esc(r.plate_number)}</div>`], ['Part', 'part_number'], ['Required', 'required', 'num'], ['Available', 'available', 'num'], ['Shortage', 'shortage', (v, r) => UI.num(v) + ' ' + UI.esc(r.unit)], ['Linked PO', 'linked_po', (v) => v ? UI.esc(v) : '<span class="text-muted">None</span>']], shortLines, 'No request is short of stock.'),
        'Requests', UI.num(new Set(shortLines.map(x => x.id)).size));
      if (k === 'supplier') PurReport.drawer('Top supplier in the period', `Supplier with the highest approved PO value (approval date from ${period}).` + (sup ? ` ${UI.esc(sup.supplier)}: its qualifying POs are listed.` : ''),
        T([poCol, ['Approved', 'decided_at', 'date'], ['Status', 'status', 'badge'], ['Approved value', 'total_amount', 'money']], det.topSupplier || [], 'No PO was approved in this period.'),
        `Total of ${UI.num((det.topSupplier || []).length)} PO(s)`, UI.money((det.topSupplier || []).reduce((a, o) => a + +o.total_amount, 0)));
    }));

    const pr = document.createElement('button'); pr.className = 'btn btn-sm btn-outline-secondary'; pr.innerHTML = '<i class="bi bi-printer"></i> Print';
    pr.onclick = () => UI.print(`<h2>Purchasing Reports</h2><div>${UI.date(per.from)} – ${UI.date(per.to)}</div>` + ctx.el.innerHTML);
    ctx.tools.append(pr);

    // ---- chart
    const cv = ctx.el.querySelector('#prFlow');
    if (!cv || typeof Chart === 'undefined') return;
    const colors = ['#2f7d57', '#d97706'];
    const chart = new Chart(cv, { type: 'line',
      data: { labels: b.map(x => x.short), datasets: [['Stock in (received)', flow.in], ['Stock out (issued)', flow.out]].map(([label, data], j) => ({ label, data, borderColor: colors[j], backgroundColor: colors[j],
        borderWidth: 2, pointRadius: b.length > 40 ? 2 : 3, pointHoverRadius: 6, tension: 0, fill: false })) },
      options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => UI.compactMoney(v) }, title: { display: true, text: 'Value (PHP)' } }, x: { ticks: { autoSkip: true, maxRotation: 0 } } },
        plugins: { legend: { position: 'top', align: 'start', labels: { usePointStyle: true, pointStyle: 'line', boxWidth: 18, boxHeight: 3, padding: 14, font: { size: 12 } } },
          tooltip: { callbacks: { title: (it) => b[it[0].dataIndex] ? b[it[0].dataIndex].label : '', label: (it) => `${it.dataset.label}: ${UI.money(it.raw)}` } } },
        onClick: (evt, els, ch) => { const hit = ch.getElementsAtEventForMode(evt, 'index', { intersect: false }, false); if (hit.length) openPoint(hit[0].index); } } });
    PurReport.charts.push(chart);
    const pointRect = (i) => () => { const r = cv.getBoundingClientRect(), ms = [0, 1].map(j => chart.getDatasetMeta(j)).filter(mm => !mm.hidden && mm.data[i]);
      const x = ms.length ? ms[0].data[i].x : chart.chartArea.left, y = ms.length ? Math.min(...ms.map(mm => mm.data[i].y)) : chart.chartArea.top;
      return { left: r.left + x, right: r.left + x, top: r.top + y - 6, bottom: r.top + y + 6 }; };
    const list = (rows, empty) => rows.length ? `<ul class="pr-moves">${rows.slice(0, 6).map(x => { const g = PurReport.ref(x), ok = g && UI.canGo(g);
        return `<li${ok ? ` class="clickable" data-go="${UI.esc(g)}" role="link" tabindex="0"` : ''}><span><b>${UI.esc(x.ref || '#' + x.id)}</b> · ${UI.esc(x.part_number)} × ${UI.num(x.qty)} ${UI.esc(x.unit)}</span><span>${UI.money(x.value)}</span></li>`; }).join('')}</ul>`
        + (rows.length > 6 ? `<div class="cell-sub">and ${rows.length - 6} more</div>` : '') : `<div class="ws-sug-empty">${UI.esc(empty)}</div>`;
    const openPoint = (i) => {
      const bk = b[i]; if (!bk) return;
      const moves = flow.moves[i] || [], ins = moves.filter(x => x.movement_type === 'Stock In'), outs = moves.filter(x => x.movement_type === 'Stock Out');
      const r2 = 'from=' + bk.from + '&to=' + bk.to;
      const links = [['stock_movements?' + r2, 'Open stock movements']].filter(([g]) => UI.canGo(g)).map(([g, l]) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-go="${UI.esc(g)}">${l}</button>`).join('');
      UI.openPointPop(cv, pointRect(i), 'Stock movement details: ' + bk.label, `
        <div class="ws-pp-sec"><div class="ws-pp-h">Selected period</div><b>${UI.esc(bk.label)}</b> <span class="cell-sub">${UI.date(bk.from)}${bk.to !== bk.from ? ' – ' + UI.date(bk.to) : ''}</span></div>
        <div class="ws-pp-sec"><div class="ws-pp-h">Stock movement</div><ul class="ws-pp-kv"><li><i style="background:${colors[0]}"></i><span>Stock in value</span><b>${UI.money(flow.in[i])}</b></li>
          <li><i style="background:${colors[1]}"></i><span>Stock out value</span><b>${UI.money(flow.out[i])}</b></li></ul></div>
        <div class="ws-pp-sec"><div class="ws-pp-h">Received (POs)</div>${list(ins, 'No receipt in this period.')}</div>
        <div class="ws-pp-sec"><div class="ws-pp-h">Released (parts requests)</div>${list(outs, 'No release in this period.')}<div class="ws-pp-links">${links}</div></div>
        <div class="ws-pp-sec ws-pp-sug"><div class="ws-pp-h"><i class="bi bi-lightbulb" aria-hidden="true"></i> Suggested actions</div>${PurReport.sug((flow.pointTips || [])[i], 'No action is suggested for this period.')}</div>`);
      chart.setActiveElements([0, 1].map(j => ({ datasetIndex: j, index: i }))); chart.update('none');
    };
    let cur = -1;
    cv.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); cur = cur < 0 ? (e.key === 'ArrowRight' ? 0 : b.length - 1) : Math.max(0, Math.min(b.length - 1, cur + (e.key === 'ArrowRight' ? 1 : -1)));
        const act = [0, 1].map(j => ({ datasetIndex: j, index: cur })); chart.setActiveElements(act); chart.tooltip.setActiveElements(act, { x: 0, y: 0 }); chart.update('none'); }
      if ((e.key === 'Enter' || e.key === ' ') && cur >= 0) { e.preventDefault(); openPoint(cur); }
    });
  },
});
