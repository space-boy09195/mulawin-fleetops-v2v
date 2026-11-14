/* assets/js/modules/administration/oversight.js — HEAD MANAGEMENT (Admin) workspace   HEAD_MANAGEMENT_V1
   Pages: admin_overview, admin_approvals, admin_operations, admin_maintenance, admin_purchasing, admin_finance,
   admin_reports (Executive Reports: items requiring attention, by workflow group and recorded date), admin_analytics (topic-based trends; weekly finance policy).
   Users & Roles = the existing User Management page ('admin'); Audit Trail = the existing read-only Audit Logs ('audit_logs').
   Data: backend/Modules/Administration/Oversight.php (read only). Approval decisions call the EXISTING actions
   (Purchasing.po_decide, Purchasing.count_review, Billing.charge_decide), which check the Admin role, the current state
   and separation of duties again. Department records open in their own pages (read-only for the Admin there). */
'use strict';
const HM = {
  list: (items, empty) => UI.wsList(items, empty),
  entry: (go, title, sub, side) => UI.wsEntry(go, title, sub, side),
  pill: (n, tone) => `<span class="badge-s ${tone || (+n ? 'amber' : 'gray')}">${UI.esc(n)}</span>`,
  /** Floating suggestions content (same look as the Motorpool / Purchasing reports). */
  sug: (items, empty) => items && items.length ? `<ul class="ws-sug">${items.map(x => `<li>${UI.esc(x.text)}${x.go && UI.canGo(x.go) ? ` <button type="button" class="btn btn-link btn-sm" data-go="${UI.esc(x.go)}">${UI.esc(x.label || 'Open')}</button>` : ''}</li>`).join('')}</ul>`
    : `<div class="ws-sug-empty">${UI.esc(empty)}</div>`,
  exceptionEntries: (rows) => rows.map(x => HM.entry(x.go, `<b>${UI.esc(x.title)}</b>`, UI.esc(x.sub), HM.pill(UI.num(x.n), x.tone === 'red' ? 'red' : 'amber'))),
  /** Drawer listing the rows behind a figure, with the total that equals the figure. */
  breakdown(title, basis, table, totalLabel, total) {
    UI.drawer(UI.esc(title), `<p class="small text-muted mb-2">${basis}</p>${table}${totalLabel ? `<div class="mr-recon"><span>${UI.esc(totalLabel)}</span><b>${total}</b></div>` : ''}`, { subtitle: 'Source records' });
  },
  bindClicks(root, map) { root.querySelectorAll('[data-hm]').forEach(el => { const fn = map[el.dataset.hm]; if (!fn) return;
    el.classList.add('clickable'); el.setAttribute('role', 'button'); el.tabIndex = 0; el.onclick = fn; el.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(); } }; }); },
};

// ---------------------------------------------------------------- Overview
App.page('admin_overview', {
  title: 'Head Management Overview',
  subtitle: 'Cross-department visibility and the decisions that need management attention.',
  render(ctx) {
    const d = ctx.data, k = d.kpis && !Array.isArray(d.kpis) ? d.kpis : {};
    UI.tool(ctx, 'Executive reports', () => App.go('admin_reports'), 'bi-graph-up');
    UI.tool(ctx, 'Review approvals', () => App.go('admin_approvals'), 'bi-check2-square', 'primary');
    const tiles = [
      { label: 'Awaiting your approval', value: UI.num(k.approvals), note: 'decisions routed to the Admin', tone: k.approvals ? 'amber' : 'gray', go: 'admin_approvals' },
      { label: 'Active trips', value: UI.num(k.active_trips), note: 'dispatched or in transit (now)', tone: 'gray', go: 'admin_operations' },
      { label: 'Trucks under repair', value: UI.num(k.trucks_repair), note: 'truck status Under Maintenance', tone: k.trucks_repair ? 'amber' : 'gray', go: 'admin_maintenance' },
      { label: 'Open purchase orders', value: UI.num(k.open_pos), note: 'awaiting approval or delivery', tone: 'gray', go: 'admin_purchasing' },
      { label: 'Outstanding receivables', value: UI.money(k.receivables), note: 'open invoices, unpaid balance', tone: 'gray', go: 'admin_finance' },
      { label: 'Low-stock parts', value: UI.num(k.low_stock), note: 'at or below the reorder level', tone: k.low_stock ? 'red' : 'gray', go: 'admin_purchasing' },
    ];
    const att = Array.isArray(d.attention) ? d.attention : [], dec = Array.isArray(d.decisions) ? d.decisions : [], act = Array.isArray(d.activity) ? d.activity : [];
    const deps = Array.isArray(d.departments) ? d.departments : [];
    ctx.el.innerHTML = `<div class="maint-overview mp-ws">${UI.kpiTiles(tiles, 'ws-kpi6')}
      ${UI.quickBar([{ label: 'Approvals', icon: 'bi-check2-square', go: 'admin_approvals' }, { label: 'Financial oversight', icon: 'bi-cash-coin', go: 'admin_finance' },
        { label: 'Users & roles', icon: 'bi-people', go: 'admin' }, { label: 'Audit trail', icon: 'bi-journal-text', go: 'audit_logs' }, { label: 'Documents', icon: 'bi-folder2-open', go: 'documents' },
        { label: 'Company dashboard', icon: 'bi-grid-1x2', go: 'dashboard' }])}
      <div class="ws-grid2">
        ${UI.wsPanel('Needs management attention', 'bi-flag', HM.list(HM.exceptionEntries(att), 'No management exception right now.'), { cls: 'ws-l1', count: att.length })}
        ${UI.wsPanel('Department status', 'bi-diagram-3', HM.list(deps.map(x => HM.entry(x.go, `<b>${UI.esc(x.name)}</b>`,
          x.items.map(([l, n]) => `${UI.esc(l)} <b>${UI.num(n)}</b>`).join(' · '), '')), 'No department data.'), { cls: 'ws-r1' })}
        ${UI.wsPanel('Recent cross-department activity', 'bi-activity', HM.list(act.map(e => HM.entry('audit_logs', `<b>${UI.esc(e.action)}</b> <span class="mo-ref">${UI.esc(e.area)}${e.record_ref ? ' · ' + UI.esc(e.record_ref) : ''}</span>`,
          `${UI.esc(e.actor)} · ${UI.datetime(e.created_at)}`, '')), 'No recorded activity yet.'), { cls: 'ws-l2', go: 'audit_logs', goLabel: 'Audit trail' })}
        ${UI.wsPanel('Pending management decisions', 'bi-check2-square', HM.list(dec.map(r => HM.entry('admin_approvals?open=' + encodeURIComponent(r.key), `<b>${UI.esc(r.ref)}</b> <span class="mo-ref">${UI.esc(r.type)}</span>`,
          `${UI.esc(r.dept)} · ${UI.esc(r.requester || '')} · ${UI.money(r.amount)}`, HM.pill(r.actionable ? 'Your decision' : 'Own request', r.actionable ? 'amber' : 'gray'))), 'Nothing is waiting for an Admin decision.'), { cls: 'ws-r2', count: dec.length, go: 'admin_approvals' })}
      </div></div>`;
  },
});

// ---------------------------------------------------------------- Approvals
const HMApproval = {
  /** Details drawer of one queue row; decision buttons only when the backend's own rules allow the Admin to decide now. */
  async open(r) {
    const seq = App.navSeq;
    try {
      if (r.kind === 'po') {
        const x = await App.api('Purchasing.po_details', { po_id: r.id }); if (seq !== App.navSeq) return; const o = x.po;
        const el = UI.drawer(UI.esc(o.po_ref), `${HMApproval.next(r)}
          ${UI.kv([['Request', 'Purchase order above the approval limit (' + UI.esc(o.approver_role === 'admin' ? 'routed to the Admin' : 'routed to Finance') + ')'], ['Status', UI.badge(o.status) + (o.payment_status ? ' ' + UI.badge(o.payment_status) : '')],
            ['Supplier', `<b>${UI.esc(o.supplier)}</b>`], ['Purpose', UI.e(o.purpose)], ['Parts request', o.request_ref ? UI.esc(o.request_ref) : 'Not linked'], ['Requested by', UI.esc(o.requested_by_name) + ' · ' + UI.datetime(o.created_at)],
            ['Amount', `<b>${UI.money(o.total_amount)}</b>`], o.decided_at ? ['Decision', UI.esc(o.decided_by_name || 'System') + ' · ' + UI.datetime(o.decided_at) + (o.decision_notes ? `<div class="cell-sub">${UI.esc(o.decision_notes)}</div>` : '')] : null])}
          ${UI.h('Items')}${UI.table([['Part', 'part_number', (v, l) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.esc(l.description)}</div>`], ['Qty', 'quantity', (v, l) => UI.num(v) + ' ' + UI.esc(l.unit)], ['Unit price', 'unit_price', 'money'], ['Amount', 'line_total', 'money']], x.lines, { plain: true })}
          ${UI.h('Supporting documents')}${UI.docs(x.documents)}${UI.h('Approval history')}${PurUI.timeline(x.history)}
          <div class="d-flex flex-wrap gap-2 mt-3" data-acts>${r.actionable && o.status === 'Pending Approval' ? UI.btn('approve', 'Approve', 'success', 'bi-check-lg') + UI.btn('reject', 'Reject', 'outline-danger', 'bi-x-lg') : ''}
            ${UI.canGo(r.go) ? UI.btn('src', 'Open purchase order', 'outline-secondary', 'bi-box-arrow-up-right') : ''}</div>`, { subtitle: 'Purchasing · ' + UI.esc(r.type) });
        HMApproval.bind(el, r, {
          approve: () => UI.confirmAction('Approve ' + UI.esc(o.po_ref), `Approve ${UI.money(o.total_amount)} to ${UI.esc(o.supplier)}? The system checks the purchasing budget and reserves the amount; if the budget is not enough the PO is rejected automatically.`,
            'Purchasing.po_decide', { po_id: o.id, decision: 'approve' }, { submit: 'Approve' }),
          reject: () => UI.confirmAction('Reject ' + UI.esc(o.po_ref), 'The Purchasing Officer will be notified.', 'Purchasing.po_decide', { po_id: o.id, decision: 'reject' },
            { danger: true, submit: 'Reject', fields: [{ name: 'notes', label: 'Reason', required: true, col: 12 }] }) });
      } else if (r.kind === 'count') {
        const x = await App.api('Purchasing.count_details', { count_id: r.id }); if (seq !== App.navSeq) return; const c = x.count;
        const el = UI.drawer(UI.esc(c.count_ref), `${HMApproval.next(r)}
          ${UI.kv([['Request', 'Stock count adjustment (posting changes the stock)'], ['Status', UI.badge(c.status)], ['Part', `<b>${UI.esc(c.part_number)}</b> · ${UI.esc(c.description)}`],
            ['System / counted', UI.num(c.system_qty) + ' → ' + UI.num(c.counted_qty) + ' ' + UI.esc(c.unit)], ['Difference', `<b>${+c.variance > 0 ? '+' : ''}${UI.num(c.variance)}</b> · ${UI.money(Math.abs(+c.variance_value))}`],
            ['Reason', UI.e(c.reason)], ['Counted by', UI.esc(c.counted_by_name) + ' · ' + UI.datetime(c.counted_at)],
            c.reviewed_at ? ['Review', UI.esc(c.reviewed_by_name) + ' · ' + UI.datetime(c.reviewed_at) + (c.review_notes ? `<div class="cell-sub">${UI.esc(c.review_notes)}</div>` : '')] : null])}
          ${r.own ? '<div class="alert alert-secondary small py-2">You recorded this count, so another Admin must review it.</div>' : ''}
          ${UI.h('Evidence')}${UI.docs(x.documents, 'No evidence file attached.')}
          <div class="d-flex flex-wrap gap-2 mt-3" data-acts>${r.actionable && c.status === 'Submitted' ? UI.btn('post', 'Post adjustment', 'success', 'bi-check-lg') + UI.btn('reject', 'Reject count', 'outline-danger', 'bi-x-lg') : ''}
            ${UI.canGo(r.go) ? UI.btn('src', 'Open in Inventory', 'outline-secondary', 'bi-box-arrow-up-right') : ''}</div>`, { subtitle: 'Purchasing · stock count' });
        HMApproval.bind(el, r, { post: () => PurUI.countReview(c, 'post'), reject: () => PurUI.countReview(c, 'reject') });
      } else {
        const x = await App.api('Billing.details', { billing_id: r.billing_id }); if (seq !== App.navSeq) return; const b = x.billing;
        const req = [...(x.requests || []), ...(x.charges || [])].find(ch => +ch.id === r.id) || {};
        const el = UI.drawer(UI.esc(r.ref), `${HMApproval.next(r)}
          ${UI.kv([['Request', UI.esc(r.type)], ['Status', UI.badge(r.raw_status)], ['Description', UI.e(req.description || r.summary)], ['Amount', `<b>${UI.money(r.amount)}</b>`],
            ['Reason', UI.e(req.reason)], ['Requested by', UI.esc(r.requester) + ' · ' + UI.datetime(r.submitted)], ['Billing total now', UI.money(b.total_amount)],
            r.decided_at ? ['Decision', UI.esc(r.decider || '') + ' · ' + UI.datetime(r.decided_at) + (r.notes ? `<div class="cell-sub">${UI.esc(r.notes)}</div>` : '')] : null])}
          ${r.own ? '<div class="alert alert-secondary small py-2">You requested this change, so another Admin must decide it.</div>' : ''}
          ${UI.h('Supporting documents')}${UI.docs(x.documents)}
          <div class="d-flex flex-wrap gap-2 mt-3" data-acts>${r.actionable ? UI.btn('approve', 'Approve', 'success', 'bi-check-lg') + UI.btn('reject', 'Reject', 'outline-danger', 'bi-x-lg') : ''}
            ${UI.canGo(r.go) ? UI.btn('src', 'Open billing record', 'outline-secondary', 'bi-box-arrow-up-right') : ''}</div>`, { subtitle: 'Billing · ' + UI.esc(r.type) });
        HMApproval.bind(el, r, {
          approve: () => UI.confirmAction('Approve request on ' + UI.esc(r.ref), 'The billing total changes when this request is approved.', 'Billing.charge_decide', { charge_id: r.id, decision: 'approve' },
            { submit: 'Approve', fields: [{ name: 'notes', label: 'Note (optional)', col: 12 }] }),
          reject: () => UI.confirmAction('Reject request on ' + UI.esc(r.ref), 'The billing total does not change. Billing is notified with your reason.', 'Billing.charge_decide', { charge_id: r.id, decision: 'reject' },
            { danger: true, submit: 'Reject', fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] }) });
      }
    } catch (e) { UI.toast(e.message, 'error'); }
  },
  next: (r) => `<div class="ws-next ${r.status === 'Pending' ? '' : 'is-done'}"><i class="bi ${r.status === 'Pending' ? 'bi-arrow-right-circle' : 'bi-check-circle'}" aria-hidden="true"></i><div>
    <b>${r.status === 'Pending' ? (r.actionable ? 'Waiting for your decision' : 'Waiting for another Admin') : UI.esc(r.raw_status)}</b><div>${UI.esc(r.summary || '')}</div></div></div>`,
  bind(el, r, acts) {
    el.querySelectorAll('[data-acts] [data-act]').forEach(b => b.addEventListener('click', () => {
      if (b.dataset.act === 'src') { UI.closeDrawer(); return UI.goTo(r.go); }
      UI.closeDrawer(); acts[b.dataset.act]?.();
    }));
  },
};

App.page('admin_approvals', {
  title: 'Approvals',
  subtitle: 'Requests the existing workflow routes to Head Management for a decision.',
  render(ctx) {
    const d = ctx.data, q = ctx.query, all = Array.isArray(d.queue) ? d.queue : [];
    const types = [...new Set(all.map(r => r.type))].sort();
    const base = all.filter(r => !q.type || r.type === q.type);
    const TABS = [['pending', 'Pending', r => r.status === 'Pending'], ['decided', 'Decided (90 days)', r => r.status !== 'Pending'], ['all', 'All', () => true]];
    const tab = TABS.some(t => t[0] === q.tab) ? q.tab : 'pending';
    const rows = base.filter(TABS.find(t => t[0] === tab)[2]);
    ctx.el.innerHTML = UI.statusTabs('admin_approvals', q, 'tab', TABS.map(([k, l, f]) => [k, l, base.filter(f).length]), tab)
      + UI.generalFilters(ctx, [{ name: 'type', label: 'Request types', options: types }])
      + `<div class="small text-muted mb-2">Only decisions the system assigns to the Admin appear here: purchase orders above the approval limit, stock count adjustments and billing charge requests.
         Purchase orders up to the limit are approved by Finance and trip funds by the Operations Head.</div>`;
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([
      ['Reference', 'ref', (v, r) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.esc(r.summary || '')}</div>`],
      ['Request type', 'type'], ['Department', 'dept'], ['Requester', 'requester'], ['Submitted', 'submitted', 'date'],
      ['Amount', 'amount', 'money'], ['Status', 'status', 'badge'], ['Next', 'next', (v, r) => r.status === 'Pending' && r.actionable ? '<span class="badge-s amber">You (Admin)</span>' : UI.esc(v)]], rows,
      { showAllColumns: true, directActions: true, actions: (r) => UI.ibtn('view', r.actionable ? 'Review and decide' : 'View details', r.actionable ? 'bi-check2-square' : 'bi-eye', r.actionable ? 'outline-primary' : 'outline-secondary'),
        onAction: (a, r) => HMApproval.open(r), rowClick: (r) => HMApproval.open(r),
        empty: tab === 'pending' ? 'Nothing is waiting for an Admin decision.' : 'No request in this list.' });
    const once = UI.takeQuery(ctx, 'open');
    if (once.open) { const r = all.find(x => x.key === once.open); if (r) HMApproval.open(r); else UI.toast('That request is no longer in the queue.', 'warn'); }
  },
});

// ---------------------------------------------------------------- shared chart helper (Chart.js; one unit per chart)
HM.charts = [];
HM.destroyCharts = () => { HM.charts.forEach(ch => { try { ch.destroy(); } catch { /* gone */ } }); HM.charts = []; };
HM.DIV = { car_carrier_dispatcher: 'Car Carrier', container_wingvan_dispatcher: 'Container / Wing Van' };
/** Chart with click-to-popover. kind: line | bar | hbar | doughnut | stack. detail(i, dsIndex) -> popover html. */
HM.chart = (cv, kind, labels, datasets, opt = {}) => {
  if (!cv || typeof Chart === 'undefined') return null;
  const money = opt.money, fmt = money ? UI.money : (v) => UI.num(v);
  const isLine = kind === 'line', isDonut = kind === 'doughnut', horiz = kind === 'hbar' || kind === 'stack';
  const cfg = { type: isLine ? 'line' : isDonut ? 'doughnut' : 'bar',
    data: { labels, datasets: datasets.map((d, j) => ({ label: d.label, data: d.data, borderColor: d.color, backgroundColor: isDonut ? d.colors : d.color, borderWidth: isDonut ? 2 : (isLine ? 2 : 0),
      pointRadius: isLine ? (labels.length > 40 ? 2 : 3) : undefined, pointHoverRadius: isLine ? 6 : undefined, tension: 0, fill: false, borderRadius: isLine || isDonut ? 0 : 4, maxBarThickness: 34 })) },
    options: { responsive: true, maintainAspectRatio: false, indexAxis: horiz ? 'y' : 'x', interaction: { mode: isLine ? 'index' : 'nearest', intersect: !isLine },
      scales: isDonut ? {} : { [horiz ? 'x' : 'y']: { beginAtZero: true, stacked: kind === 'stack', ticks: money ? { callback: (v) => UI.compactMoney(v) } : { precision: 0 }, title: { display: !!opt.axis, text: opt.axis } },
        [horiz ? 'y' : 'x']: { stacked: kind === 'stack', ticks: { autoSkip: true, maxRotation: 0 } } },
      plugins: { legend: { display: isDonut ? true : datasets.length > 1, position: isDonut ? 'right' : 'top', align: 'start', labels: { usePointStyle: true, pointStyle: isLine ? 'line' : 'rect', boxWidth: 14, boxHeight: isLine ? 3 : 10, padding: 12, font: { size: 12 } } },
        tooltip: { callbacks: { title: (it) => opt.titles ? opt.titles[it[0].dataIndex] : it[0].label, label: (it) => `${it.dataset.label || it.label}: ${fmt(it.raw)}` } } },
      onClick: (evt, els, ch) => { if (!opt.detail) return; const hit = isLine ? ch.getElementsAtEventForMode(evt, 'index', { intersect: false }, false) : els; if (hit.length) open(hit[0].index, hit[0].datasetIndex); } } };
  if (isDonut) cfg.options.cutout = '66%';
  const ch = new Chart(cv, cfg); HM.charts.push(ch);
  const rect = (i, j) => () => { const r = cv.getBoundingClientRect(), el = ch.getDatasetMeta(j || 0).data[i]; const x = el ? el.x : ch.chartArea.left, y = el ? el.y : ch.chartArea.top;
    return { left: r.left + x, right: r.left + x, top: r.top + y - 6, bottom: r.top + y + 6 }; };
  const open = (i, j) => { const html = opt.detail(i, j); if (html) UI.openPointPop(cv, rect(i, j), (opt.title || 'Details') + ': ' + labels[i], html); };
  let cur = -1;
  cv.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); cur = cur < 0 ? (e.key === 'ArrowRight' ? 0 : labels.length - 1) : Math.max(0, Math.min(labels.length - 1, cur + (e.key === 'ArrowRight' ? 1 : -1)));
      const act = datasets.map((_, j) => ({ datasetIndex: j, index: cur })); ch.setActiveElements(act); ch.tooltip.setActiveElements(act, { x: 0, y: 0 }); ch.update('none'); }
    if ((e.key === 'Enter' || e.key === ' ') && cur >= 0 && opt.detail) { e.preventDefault(); open(cur, 0); }
  });
  return ch;
};
HM.canvas = (id, label, h = 240) => `<div class="mr-chart" style="height:${h}px"><canvas id="${id}" tabindex="0" aria-label="${UI.esc(label)}. Arrow keys choose a value, Enter opens its details."></canvas></div>`;
HM.empty = (text) => `<div class="mo-empty hm-empty"><i class="bi bi-info-circle" aria-hidden="true"></i> ${UI.esc(text)}</div>`;
HM.pp = (title, rows, links) => `<div class="ws-pp-sec"><div class="ws-pp-h">${UI.esc(title)}</div><ul class="ws-pp-kv">${rows.map(([l, v]) => `<li><i style="background:transparent"></i><span>${UI.esc(l)}</span><b>${v}</b></li>`).join('')}</ul>
  ${links && links.length ? `<div class="ws-pp-links">${links.filter(([g]) => UI.canGo(g)).map(([g, l]) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-go="${UI.esc(g)}">${UI.esc(l)}</button>`).join('')}</div>` : ''}</div>`;

// ---------------------------------------------------------------- Operations monitoring (read only)
App.page('admin_operations', {
  title: 'Operations Monitoring',
  subtitle: 'Trips, trips needing attention and fleet availability. Dispatch work stays in the Operations workspace.',
  render(ctx) {
    const d = ctx.data, q = ctx.query, g = d.groups || {};
    const trips = Array.isArray(d.trips) ? d.trips : [], fleet = Array.isArray(d.fleet) ? d.fleet : [], funds = d.funds || {}, att = d.attention || [];
    const groupOf = (t) => (g.active || []).includes(t.status) ? 'active' : (g.scheduled || []).includes(t.status) ? 'scheduled' : (g.exceptions || []).includes(t.status) ? 'exceptions' : t.status === 'Completed' ? 'completed' : 'other';
    const TABS = [['active', 'Active'], ['scheduled', 'Scheduled'], ['exceptions', 'On hold / reschedule'], ['completed', 'Completed (30 days)'], ['all', 'All']];
    const tab = TABS.some(t => t[0] === q.group) ? q.group : 'all';
    const cnt = (k) => k === 'all' ? trips.length : trips.filter(t => groupOf(t) === k).length;
    const tiles = [
      { label: 'Active trips', value: UI.num(cnt('active')), note: 'dispatched or in transit (now)', tone: 'gray', go: 'admin_operations?group=active' },
      { label: 'Scheduled / being prepared', value: UI.num(cnt('scheduled')), note: 'review to ready for dispatch', tone: 'gray', go: 'admin_operations?group=scheduled' },
      { label: 'On hold / reschedule', value: UI.num(cnt('exceptions')), note: 'trip exceptions (now)', tone: cnt('exceptions') ? 'red' : 'gray', go: 'admin_operations?group=exceptions' },
      { label: 'Completed (30 days)', value: UI.num(cnt('completed')), note: 'trips completed in the last 30 days', tone: 'gray', go: 'admin_operations?group=completed' },
    ];
    const total = fleet.reduce((a, r) => a + +r.n, 0);
    ctx.el.innerHTML = `${UI.kpiTiles(tiles, 'ws-kpi4')}
      <div class="ws-grid2 hm-fit">
        ${UI.wsPanel('Trips needing attention', 'bi-exclamation-octagon', att.length ? HM.list(att.map(t => HM.entry('trips?open=' + t.id, `<b>${UI.esc(t.trip_ref)}</b> <span class="mo-ref">${UI.esc(HM.DIV[t.dispatcher_role] || t.truck_type || '')}${t.dispatcher ? ' · ' + UI.esc(t.dispatcher) + (t.dispatcher_shift ? ' (' + UI.esc(t.dispatcher_shift) + ')' : '') : ''}</span>`,
          t.open_incident ? `Unresolved incident ${UI.esc(t.open_incident)}${t.incident_type ? ' · ' + UI.esc(t.incident_type) : ''}` : 'Status: ' + UI.esc(t.status), UI.badge(t.status))), '')
          : HM.empty('No trip is on hold, waiting for reschedule or resources, or linked to an unresolved incident.'), { cls: 'ws-l1', count: att.length })}
        ${UI.wsPanel('Fleet availability', 'bi-truck', HM.list(fleet.map(r => HM.entry('trucks?status=' + encodeURIComponent(r.status), `<b>${UI.esc(r.status)}</b>`,
          total ? Math.round(r.n / total * 100) + '% of ' + UI.num(total) + ' active trucks' : '', HM.pill(UI.num(r.n), 'gray'))), 'No active truck.'),
          { cls: 'ws-r1', foot: `Trip funds: ${UI.num(+funds.pending || 0)} awaiting the Operations Head · ${UI.num(+funds.to_release || 0)} ready for Finance to release.` })}
      </div>
      <h2 class="ref-h mt-3">Trips</h2>${UI.statusTabs('admin_operations', q, 'group', TABS.map(([k, l]) => [k, l, cnt(k)]), tab)}`;
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([['Trip', 'trip_ref', (v, t) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.esc(t.truck_type || '')}</div>`], ['Status', 'status', 'badge'], ['Client', 'client'], ['Truck', 'plate_number'],
      ['Dispatcher', 'dispatcher', (v, t) => v ? `${UI.esc(v)}<div class="cell-sub">${UI.esc(HM.DIV[t.dispatcher_role] || '')}${t.dispatcher_shift ? ' · ' + UI.esc(t.dispatcher_shift) : ''}</div>` : '<span class="text-muted">—</span>'],
      ['Route', 'pickup_point', (v, t) => `<span class="cell-clamp">${UI.esc(v || '')} → ${UI.esc(t.delivery_point || '')}</span>`], ['Scheduled', 'scheduled_at', 'datetime']],
      trips.filter(t => tab === 'all' || groupOf(t) === tab),
      { showAllColumns: true, directActions: true, actions: () => UI.ibtn('view', 'Trip details (read only)', 'bi-eye'), onAction: (a, t) => App.go('trips', { open: t.id }), rowClick: (t) => App.go('trips', { open: t.id }),
        empty: 'No trip in this list.' });
  },
});

// ---------------------------------------------------------------- Maintenance monitoring (read only)
App.page('admin_maintenance', {
  title: 'Maintenance Monitoring',
  subtitle: 'Repairs needing attention, truck maintenance status and repair records. Repair work stays with the Motorpool.',
  render(ctx) {
    const d = ctx.data, q = ctx.query, s = d.summary && !Array.isArray(d.summary) ? d.summary : {}, n = (k) => +s[k] || 0;
    const fleet = d.fleet && !Array.isArray(d.fleet) ? d.fleet : {}, inRepair = fleet.in_repair || [], jobs = d.jobs || [], att = d.attention || [];
    const st = (j) => j.status === 'Cancelled' ? 'cancelled' : j.verified_at ? 'returned' : j.status === 'Completed' ? 'verify' : 'open';
    const TABS = [['open', 'Open'], ['verify', 'Awaiting verification'], ['returned', 'Returned to service'], ['cancelled', 'Cancelled'], ['all', 'All (90 days)']];
    const tab = TABS.some(t => t[0] === q.state) ? q.state : 'open';
    const cnt = (x) => x === 'all' ? jobs.length : jobs.filter(j => st(j) === x).length;
    const tiles = [
      { label: 'Open repairs', value: UI.num(n('new_reports') + n('under_inspection') + n('under_repair') + n('for_verification')), note: `${n('new_reports')} new · ${n('under_inspection') + n('under_repair')} in progress (now)`, tone: 'gray', go: 'admin_maintenance?state=open' },
      { label: 'Awaiting verification', value: UI.num(n('for_verification')), note: 'repaired, still out of service', tone: n('for_verification') ? 'amber' : 'gray', go: 'admin_maintenance?state=verify' },
      { label: 'Waiting for parts', value: UI.num(att.filter(a => a.reason === 'parts').length), note: 'active repairs with an open parts request', tone: att.some(a => a.reason === 'parts') ? 'amber' : 'gray' },
      { label: 'Trucks under maintenance', value: UI.num(inRepair.length), note: fleet.active_trucks ? `of ${fleet.active_trucks} active trucks` : 'now', tone: inRepair.length ? 'amber' : 'gray', go: 'trucks?status=Under Maintenance' },
    ];
    ctx.el.innerHTML = `${UI.kpiTiles(tiles, 'ws-kpi4')}
      <div class="ws-grid2 hm-fit">
        ${UI.wsPanel('Repairs needing attention', 'bi-flag', att.length ? HM.list(att.map(a => HM.entry('maintenance?open=' + a.id, `<b>${UI.esc(a.job_ref)}</b> <span class="mo-ref">${UI.esc(a.plate_number)}</span>`,
          a.reason === 'verify' ? `Repaired ${UI.num(a.days)} day(s) ago, not yet verified: truck still out of service` : `Waiting for parts: ${UI.esc(a.requests || '')}`, HM.pill(a.reason === 'verify' ? 'Verification' : 'Parts', 'amber'))), '')
          : HM.empty('No repair is waiting for verification or for parts.'), { cls: 'ws-l1', count: att.length })}
        ${UI.wsPanel('Truck maintenance status', 'bi-truck', inRepair.length ? HM.list(inRepair.map(t => HM.entry('maintenance?open=' + t.job_id, `<b>${UI.esc(t.plate_number)}</b> <span class="mo-ref">${UI.esc(t.job_ref)} · ${UI.esc(t.truck_type || '')}</span>`,
          '', UI.badge(+t.awaiting_verification ? 'Awaiting verification' : t.status))), '') : HM.empty('Every active truck is in service.'), { cls: 'ws-r1', count: inRepair.length })}
      </div>
      <h2 class="ref-h mt-3">Repair records</h2>${UI.statusTabs('admin_maintenance', q, 'state', TABS.map(([x, l]) => [x, l, cnt(x)]), tab)}`;
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([['Repair', 'job_ref', (v, j) => `<b>${UI.esc(v)}</b><div class="cell-sub">${j.source === 'Incident' ? 'Trip incident' : 'Garage report'}</div>`], ['Truck', 'plate_number', (v, j) => `${UI.esc(v)}<div class="cell-sub">${UI.esc(j.truck_type)}</div>`],
      ['Problem', 'problem', (v) => `<span class="cell-clip">${UI.esc(v)}</span>`], ['Mechanic', 'mechanic', (v) => v ? UI.esc(v) : '<span class="text-muted">Not assigned</span>'],
      ['Status', 'status', (v, j) => UI.badge(j.verified_at ? 'Returned to service' : v === 'Completed' ? 'Awaiting verification' : v) + (+j.open_parts ? `<div class="cell-sub">${j.open_parts} parts request(s) open</div>` : '')],
      ['Reported', 'reported_on', 'date'], ['Completed', 'completed_on', 'date']], jobs.filter(j => tab === 'all' || st(j) === tab),
      { showAllColumns: true, directActions: true, actions: () => UI.ibtn('view', 'Repair details (read only)', 'bi-eye') + UI.ibtn('hist', 'Truck maintenance history', 'bi-clock-history'),
        onAction: (a, j) => a === 'hist' ? App.go('maint_history', { truck: j.plate_number }) : App.go('maintenance', { open: j.id }), rowClick: (j) => App.go('maintenance', { open: j.id }), empty: 'No repair in this list.' });
  },
});

// ---------------------------------------------------------------- Purchasing monitoring (read only)
App.page('admin_purchasing', {
  title: 'Purchasing Monitoring',
  subtitle: 'Purchase orders, purchasing issues and stock alerts. Stock changes stay in the Purchasing workflow.',
  render(ctx) {
    const d = ctx.data, q = ctx.query, orders = d.orders || [], req = d.requests || [], low = d.lowStock || [], iss = d.issues || [];
    const grp = (o) => o.status === 'Pending Approval' ? 'approval' : ['Approved', 'Partially Received'].includes(o.status) ? 'receive' : /^Rejected/.test(o.status) ? 'rejected' : 'done';
    const TABS = [['approval', 'Awaiting approval'], ['receive', 'To receive'], ['done', 'Received / closed'], ['rejected', 'Rejected'], ['all', 'All']];
    const tab = TABS.some(t => t[0] === q.po) ? q.po : 'all';
    const cnt = (x) => x === 'all' ? orders.length : orders.filter(o => grp(o) === x).length;
    const tiles = [
      { label: 'Requests to process', value: UI.num(req.filter(r => r.status === 'Requested').length), note: `${UI.num(req.filter(r => r.status !== 'Requested').length)} short of stock`, tone: 'gray', go: 'parts_requests?tab=todo' },
      { label: 'POs awaiting approval', value: UI.num(cnt('approval')), note: 'Finance (up to the limit) or Admin', tone: cnt('approval') ? 'amber' : 'gray', go: 'admin_purchasing?po=approval' },
      { label: 'POs to receive', value: UI.num(cnt('receive')), note: 'approved, delivery not complete', tone: 'gray', go: 'admin_purchasing?po=receive' },
      { label: 'Low-stock parts', value: UI.num(low.length), note: 'at or below the reorder level', tone: low.length ? 'red' : 'gray', go: 'inventory?stock=low' },
    ];
    ctx.el.innerHTML = `${UI.kpiTiles(tiles, 'ws-kpi4')}
      <div class="ws-grid2 hm-fit">
        ${UI.wsPanel('Purchasing issues requiring attention', 'bi-flag', iss.length ? HM.list(iss.map(x => HM.entry(x.go, `<b>${UI.esc(x.ref)}</b> <span class="mo-ref">${UI.esc(x.reason)}</span>`,
          `Next: ${UI.esc(x.next)}${x.since ? ' · since ' + UI.date(x.since) : ''}`, UI.badge(x.status))), '') : HM.empty('No budget-rejected order, stock shortage or ledger mismatch.'),
          { cls: 'ws-l1', count: iss.length, foot: 'Delivery delays are not listed: purchase orders record no expected delivery date.' })}
        ${UI.wsPanel('Low-stock alerts', 'bi-exclamation-triangle', low.length ? HM.list(low.map(p => HM.entry('inventory?open=' + p.id, `<b>${UI.esc(p.part_number)}</b> <span class="mo-ref">${UI.esc(p.description)}</span>`,
          `On hand ${UI.num(p.quantity_on_hand)} ${UI.esc(p.unit)} · reorder level ${UI.num(p.reorder_level)}`, HM.pill('Low', 'red'))), '') : HM.empty('No part is at or below its reorder level.'),
          { cls: 'ws-r1', count: low.length, tipHtml: HM.sug(d.partTips, 'No part is low on stock right now.') })}
      </div>
      <h2 class="ref-h mt-3">Purchase orders</h2>${UI.statusTabs('admin_purchasing', q, 'po', TABS.map(([x, l]) => [x, l, cnt(x)]), tab)}`;
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = UI.table([['PO', 'po_ref', (v, o) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.date(o.created_at)}</div>`], ['Supplier', 'supplier', (v) => `<span class="cell-clamp">${UI.esc(v || '')}</span>`],
      ['Total', 'total_amount', 'money'], ['Received', 'received_units', (v, o) => `${UI.num(v)} / ${UI.num(o.ordered_units)} units`], ['Status', 'status', 'badge'], ['Payment', 'payment_status', 'badge'],
      ['Approver', 'approver_role', (v) => v === 'admin' ? 'Admin' : 'Finance']], orders.filter(o => tab === 'all' || grp(o) === tab),
      { showAllColumns: true, directActions: true, actions: (o) => UI.ibtn('view', 'Purchase order details', 'bi-eye') + (+o.documents ? UI.ibtn('docs', o.documents + ' supporting document(s)', 'bi-paperclip') : '')
          + (o.status === 'Pending Approval' && o.approver_role === 'admin' ? UI.ibtn('decide', 'Review in Approvals', 'bi-check2-square', 'outline-primary') : ''),
        onAction: (a, o) => a === 'decide' ? App.go('admin_approvals', { open: 'po:' + o.id }) : App.go('purchase_orders', { open: o.id }), rowClick: (o) => App.go('purchase_orders', { open: o.id }),
        empty: 'No purchase order in this list.' });
    UI.bindTips(ctx.el);
  },
});

// ---------------------------------------------------------------- Financial oversight: weekly collection-funded budget
App.page('admin_finance', {
  title: 'Financial Oversight',
  subtitle: 'Weekly budget (previous week collections + unused budget carried forward), payables, commitments, receivables and collections.',
  render(ctx) {
    const d = ctx.data, wk = d.week || {}, pw = d.prevWeek || {}, w = d.weekly || {}, sg = d.suggestions || {}, mb = d.monthlyBudget && !Array.isArray(d.monthlyBudget) ? d.monthlyBudget : {};
    const prevCol = d.prevCollections || [], wkCol = d.weekCollections || [], pays = d.payments || [], com = d.commitments || [], pay = d.payables || [], poPay = d.poPayables || [], rec = d.receivables || [], dec = d.decisions || [];
    const sum = (rows, f) => rows.reduce((a, x) => a + +(typeof f === 'function' ? f(x) : x[f]), 0);
    const range = (r) => UI.date(r.from) + ' – ' + UI.date(r.to);
    const NA = '<span class="hm-na">Not established</span>';
    const shift = (n) => { const t = new Date(wk.from + 'T00:00:00'); t.setDate(t.getDate() + n * 7); return t.toISOString().slice(0, 10); };
    UI.tool(ctx, 'Next week', () => App.go('admin_finance', { week: shift(1) }), 'bi-chevron-right'); UI.tool(ctx, 'Previous week', () => App.go('admin_finance', { week: shift(-1) }), 'bi-chevron-left');
    const tiles = [
      { label: 'Previous week collections', value: UI.money(w.prev_collections), note: `Period total · ${range(pw)}`, tone: 'gray' },
      { label: 'Current week starting budget', value: w.starting_budget === null ? '—' : UI.money(w.starting_budget), note: w.starting_budget === null ? 'Not established: carry-forward not recorded' : 'collections + carry-forward', tone: w.starting_budget === null ? 'amber' : 'gray' },
      { label: 'Paid & committed', value: UI.money(w.paid_committed), note: `paid this week ${UI.money(w.payments)} · committed now ${UI.money(w.commitments)}`, tone: 'gray' },
      { label: 'Available budget', value: w.available === null ? '—' : UI.money(w.available), note: w.available === null ? 'Not established without a starting budget' : 'starting − paid − committed', tone: w.available === null ? 'amber' : (w.available < 0 ? 'red' : 'gray') },
    ];
    const totalKnown = (+w.payments || 0) + (+w.commitments || 0);
    const pct = (v) => totalKnown ? (v / totalKnown * 100).toFixed(1) : 0;
    const row = (key, label, value, note) => `<li class="hm-brow" data-hm="${key}" role="button" tabindex="0"><span>${UI.esc(label)}<small>${note}</small></span><b>${value}</b></li>`;
    const breakdown = `<ul class="hm-brk">
        ${row('carry', 'Previous week unused balance', NA, 'carry-forward (no recorded closing balance)')}
        ${row('prevcol', 'Previous week collections', UI.money(w.prev_collections), `${UI.num(prevCol.length)} client payment(s) · ${range(pw)}`)}
        ${row('alloc', 'Current week allocation', w.starting_budget === null ? NA : UI.money(w.starting_budget), '= unused balance + collections')}
        ${row('paid', 'Payments this week', UI.money(w.payments), `${UI.num(pays.length)} voucher(s) · ${range(wk)}`)}
        ${row('commit', 'Outstanding commitments', UI.money(w.commitments), `${UI.num(com.length)} approved PO(s) not yet paid (now)`)}
        ${row('avail', 'Available balance', w.available === null ? NA : UI.money(w.available), '= allocation − payments − commitments')}</ul>
      <div class="hm-stack" role="img" aria-label="Paid ${pct(w.payments)} percent, committed ${pct(w.commitments)} percent of the known amounts"><span class="m-spent" style="width:${pct(w.payments)}%"></span><span class="m-res" style="width:${pct(w.commitments)}%"></span></div>
      <div class="ws-legend"><span><i style="background:var(--blue)"></i>Paid ${UI.money(w.payments)}</span><span><i style="background:var(--amber)"></i>Committed ${UI.money(w.commitments)}</span>
        <span>${w.starting_budget === null ? 'Share of an allocation that is not recorded yet' : 'of ' + UI.money(w.starting_budget)}</span></div>`;
    const payTotal = sum(poPay, o => Math.max(0, +o.received_value - +o.paid_amount)) + sum(pay, 'balance');
    const recBal = sum(rec, 'balance');
    ctx.el.innerHTML = `<div class="ws-filters ws-period no-print" role="group" aria-label="Week"><label class="small" for="hmWeek">Week</label>
        <input id="hmWeek" type="date" class="form-control form-control-sm" value="${UI.esc(wk.from)}" aria-label="Any date in the week"><span class="small text-muted">${range(wk)} · Monday to Sunday, Philippine time</span></div>
      ${UI.kpiTiles(tiles, 'ws-kpi4')}
      <div class="ws-grid2 hm-fit">
        ${UI.wsPanel('Weekly budget breakdown', 'bi-piggy-bank', breakdown, { cls: 'ws-l1 hm-span2', tipHtml: HM.sug(sg.weekly || [], 'Weekly budget suggestions need a recorded starting budget; none is recorded yet.'),
          foot: 'A paid PO moves from committed to paid, so it is never counted twice. ' + (mb.id ? `Finance's separate monthly Purchasing budget (${UI.money(mb.amount)}, ${UI.date(mb.period_start)} – ${UI.date(mb.period_end)}, ${UI.money(mb.available)} available) is not the weekly budget.` : '') })}
        ${UI.wsPanel('Payables & commitments', 'bi-wallet2', HM.list([
            ...poPay.map(o => HM.entry('purchase_orders?open=' + o.id, `<b>${UI.esc(o.po_ref)}</b> <span class="mo-ref">${UI.esc(o.supplier || '')}</span>`, `Payable: received ${UI.money(o.received_value)}, paid ${UI.money(o.paid_amount)} · no due date recorded`, `<b>${UI.money(Math.max(0, +o.received_value - +o.paid_amount))}</b>`)),
            ...pay.map(x => HM.entry('finance', `<b>${UI.esc(x.payable_ref)}</b> <span class="mo-ref">${UI.esc(x.payee || '')}</span>`, `Payable: due ${UI.date(x.due_on)}${+x.days_overdue > 0 ? ' · ' + x.days_overdue + ' day(s) overdue' : ''}`, `<b>${UI.money(x.balance)}</b>`)),
            ...com.filter(c => !poPay.some(o => o.id === c.id)).map(c => HM.entry('purchase_orders?open=' + c.id, `<b>${UI.esc(c.po_ref)}</b> <span class="mo-ref">${UI.esc(c.supplier || '')}</span>`, 'Commitment: approved, not yet received', `<b>${UI.money(c.reserved_amount)}</b>`)),
          ], 'No payable or commitment.'), { cls: 'ws-r1', tipHtml: HM.sug(sg.payables || [], 'No payable is past or near its recorded due date.'),
          foot: `<button type="button" class="btn btn-link btn-sm p-0" data-hm="payables">Payables due ${UI.money(payTotal)}</button> · current · ${dec.length ? `<button type="button" class="btn btn-link btn-sm p-0" data-hm="decisions">${dec.length} waiting for Finance approval or release (not payables)</button>` : 'nothing waiting for Finance'}${UI.canGo('finance') ? ' · <a href="#finance">Payables & payments</a>' : ''}` })}
        ${UI.wsPanel('Receivables & collections', 'bi-hourglass-split', HM.list([
            ...rec.map(x => HM.entry('billing_receivables', `<b>${UI.esc(x.invoice_number || x.trip_ref || '')}</b> <span class="mo-ref">${UI.esc(x.client)}</span>`, `Receivable: due ${UI.date(x.due_date)}${+x.days_overdue > 0 ? ' · ' + x.days_overdue + ' day(s) overdue' : ''}`, `<b>${UI.money(x.balance)}</b>`)),
            ...wkCol.map(c => HM.entry('billing_collections', `<b>${UI.esc(c.invoice_number || '')}</b> <span class="mo-ref">${UI.esc(c.client)}</span>`, `Collected ${UI.date(c.payment_date)}${c.ar_number ? ' · AR ' + UI.esc(c.ar_number) : ''}`, `<b>${UI.money(c.amount)}</b>`)),
          ], 'No open invoice and no collection this week.'), { cls: 'ws-r2', tipHtml: HM.sug(sg.receivables || [], 'Receivables need no follow-up under the current rules.'),
          foot: `<button type="button" class="btn btn-link btn-sm p-0" data-hm="receivables">Receivables ${UI.money(recBal)}</button> · current · collected this week ${UI.money(sum(wkCol, 'amount'))}${UI.canGo('billing_receivables') ? ' · <a href="#billing_receivables">Receivables</a>' : ''}` })}
      </div>`;
    UI.bindTips(ctx.el);
    ctx.el.querySelector('#hmWeek').addEventListener('change', (e) => { if (e.target.value) App.go('admin_finance', { week: e.target.value }); });
    const T = (cols, rows, empty) => UI.table(cols, rows, { plain: true, limit: 15, empty });
    const colCols = [['Date', 'payment_date', 'date'], ['Invoice', 'invoice_number'], ['Client', 'client'], ['Method', 'payment_method'], ['AR / reference', 'ar_number', (v, x) => UI.esc(v || x.reference_number || '—')], ['Amount', 'amount', 'money']];
    const open = {
      carry: () => HM.breakdown('Previous week unused balance', 'The weekly policy carries the unused budget of the previous week forward. No table or Finance step records a weekly allocation or its closing balance yet, so this amount cannot be established from the records. It is not estimated.', '', '', ''),
      prevcol: () => HM.breakdown('Previous week collections', `Client payments recorded with a payment date from ${range(pw)} (collections.payment_date).`, T(colCols, prevCol, 'No collection was recorded in the previous week.'), `Total of ${prevCol.length} collection(s)`, UI.money(w.prev_collections)),
      alloc: () => HM.breakdown('Current week allocation', 'Starting budget = previous week collections + previous week unused balance. The collections are known (' + UI.money(w.prev_collections) + '); the unused balance is not recorded, so the allocation is not established.', '', 'Allocation', 'Not established'),
      paid: () => HM.breakdown('Payments this week', `Cash paid out (disbursement vouchers) released from ${range(wk)}: purchase orders, trip funds and other payables.`,
        T([['Voucher', 'voucher_number'], ['Released', 'released_on', 'date'], ['For', 'source_ref', (v, x) => UI.esc((v || '') + ' (' + String(x.source_type).replace('_', ' ') + ')')], ['Payee', 'payee'], ['Method', 'payment_method'], ['Amount', 'amount', 'money']], pays, 'No payment this week.'), `Total of ${pays.length} voucher(s)`, UI.money(w.payments)),
      commit: () => HM.breakdown('Outstanding commitments', 'Current: approved purchase orders whose reserved amount is not yet paid. Paying a PO moves the paid amount out of this list into payments.',
        T([['PO', 'po_ref'], ['Supplier', 'supplier'], ['Approved', 'decided_at', 'date'], ['Status', 'status', 'badge'], ['Received', 'received_value', 'money'], ['Committed', 'reserved_amount', 'money']], com, 'Nothing is committed.'), `Total of ${com.length} PO(s)`, UI.money(w.commitments)),
      avail: () => HM.breakdown('Available balance', 'Available = allocation − payments this week − outstanding commitments. Payments ' + UI.money(w.payments) + ' and commitments ' + UI.money(w.commitments) + ' are known; the allocation is not established, so the available balance is not shown.', '', 'Available', 'Not established'),
      payables: () => HM.breakdown('Payables due', 'Current: received value of POs not yet paid (no due date is recorded on POs) and other payables (amount − paid). Requests still waiting for Finance approval are not payables.',
        UI.h('PO payables') + T([['PO', 'po_ref'], ['Supplier', 'supplier'], ['Received', 'received_value', 'money'], ['Paid', 'paid_amount', 'money'], ['Due', 'received_value', (v, o) => UI.money(Math.max(0, +v - +o.paid_amount))]], poPay, 'No PO payable.')
        + UI.h('Other payables') + T([['Payable', 'payable_ref'], ['Payee', 'payee'], ['Due on', 'due_on', 'date'], ['Status', 'status', 'badge'], ['Balance', 'balance', 'money']], pay, 'No other payable.'), 'Total due', UI.money(payTotal)),
      decisions: () => HM.breakdown('Waiting for Finance', 'Purchase orders waiting for Finance approval and approved trip funds waiting for release. These are decisions, not payable obligations; they are not included in any total above.',
        T([['Request', 'ref'], ['Type', 'kind'], ['Since', 'since', 'date'], ['Amount', 'amount', 'money']], dec, 'Nothing is waiting.'), 'Requests', UI.num(dec.length)),
      receivables: () => HM.breakdown('Receivables', 'Current: open invoices (Invoiced / Partially Paid), invoice total − paid.',
        T([['Invoice', 'invoice_number'], ['Client', 'client'], ['Due', 'due_date', 'date'], ['Status', 'status', 'badge'], ['Balance', 'balance', 'money']], rec, 'No open invoice.')
        + UI.h('Collected this week') + T(colCols, wkCol, 'No collection this week.'), 'Total receivable', UI.money(recBal)),
    };
    ['prevcol', 'alloc', 'paid', 'avail'].forEach((key, i) => { const el = ctx.el.querySelectorAll('.kpi-tile')[i]; if (el) el.dataset.hm = key; });
    HM.bindClicks(ctx.el, open);
  },
});

// ---------------------------------------------------------------- Executive reports: items requiring attention
// UNSUPPORTED_CLASSIFICATION_CLEANUP: no High / Medium priority, no "critical" count, no risk list. Items are grouped by
// workflow (waiting for Head Management, open department issues) and ordered by their recorded date.
App.page('admin_reports', {
  title: 'Executive Reports',
  subtitle: 'Items requiring attention, department performance and recent management decisions, from recorded statuses and dates.',
  render(ctx) {
    const d = ctx.data, items = d.items || [], perf = d.performance || [], recent = d.recent || [], w = d.weekly || {};
    const tiles = [
      { label: 'Waiting for your decision', value: UI.num(d.decisions), note: `${UI.num(d.waiting)} pending Head Management approval(s)`, tone: d.decisions ? 'amber' : 'gray', go: 'admin_approvals' },
      { label: 'Open issues', value: UI.num(d.open), note: 'open department issues by recorded status', tone: 'gray' },
      { label: 'Weekly financial position', value: w.available === null || w.available === undefined ? '—' : UI.money(w.available), note: w.available === null || w.available === undefined ? 'Not established: weekly allocation not recorded' : 'available this week', tone: 'gray', go: 'admin_finance' },
      { label: 'Past-due balances', value: UI.money(d.overdue), note: 'invoices and payables past their recorded due date', tone: 'gray', go: 'admin_finance' },
    ];
    const GROUPS = [['waiting', 'Waiting for Head Management'], ['issues', 'Open department issues']];
    const fmt = (p, v) => p.unit === 'money' ? UI.money(v) : UI.num(v);
    const finding = (p) => +p.now === 0 && +p.prev === 0 ? 'No recorded activity in either window' : +p.prev === 0 ? 'No activity in the previous window' : (+p.now >= +p.prev ? 'Up' : 'Down') + ' ' + Math.abs(Math.round((+p.now - +p.prev) / +p.prev * 100)) + '%';
    const row = (x) => `<tr><td><b>${UI.esc(x.ref)}</b></td><td>${UI.esc(x.issue)}</td><td>${UI.badge(x.status)}</td><td>${UI.esc(x.dept)}</td><td class="col-grow"><span class="cell-clamp">${UI.esc(x.reason)}</span></td><td>${UI.esc(x.next)}</td><td>${x.since ? UI.date(x.since) : '—'}</td>
      <td class="actions-col"><div class="row-icons">${UI.canGo(x.go) ? `<button type="button" class="btn btn-sm btn-${x.decide ? 'outline-primary' : 'outline-secondary'} icon-act" data-go="${UI.esc(x.go)}" title="${x.decide ? 'Review and decide' : 'Open source record'}" aria-label="${x.decide ? 'Review and decide ' : 'Open '}${UI.esc(x.ref)}"><i class="bi ${x.decide ? 'bi-check2-square' : 'bi-box-arrow-up-right'}"></i></button>` : ''}</div></td></tr>`;
    const body = GROUPS.map(([g, label]) => { const rows = items.filter(x => x.group === g);
      return `<tr class="hm-group"><th colspan="8">${UI.esc(label)} <span class="text-muted fw-normal">${UI.num(rows.length)}</span></th></tr>`
        + (rows.length ? rows.map(row).join('') : `<tr><td colspan="8" class="text-muted">${g === 'waiting' ? 'No approval is waiting for Head Management.' : 'No open department issue under the recorded statuses.'}</td></tr>`); }).join('');
    const waiting = items.filter(x => x.group === 'waiting' && x.decide);
    ctx.el.innerHTML = `${UI.kpiTiles(tiles, 'ws-kpi4')}
      <section class="panel ws-panel mb-3"><div class="panel-head"><h2 class="panel-title">Items requiring attention <span class="text-muted fw-normal">${UI.num(items.length)}</span></h2></div>
        <div class="panel-body flush"><div class="ref-tablebox"><table class="data-table hm-pri"><thead><tr><th>Reference</th><th>Item</th><th>Status</th><th>Department</th><th class="col-grow">Recorded reason</th><th>Next step</th><th>Date</th><th class="actions-col">Actions</th></tr></thead>
        <tbody>${body}</tbody></table></div></div><div class="ws-panel-foot">Grouped by workflow and ordered by the recorded date (oldest first). "Past due" uses each invoice's or payable's recorded due date.</div></section>
      <div class="ws-grid2 hm-fit">
        ${UI.wsPanel('Department performance', 'bi-bar-chart', `<ul class="hm-perf">${perf.map(p => `<li class="clickable" data-go="${UI.esc(p.go)}" role="link" tabindex="0"><span><b>${UI.esc(p.dept)}</b><small>${UI.esc(p.measure)}</small></span>
          <span class="hm-perf-v"><b>${fmt(p, p.now)}</b><small>last 30 days · previous ${fmt(p, p.prev)}</small></span><span class="hm-perf-f">${UI.esc(finding(p))}</span></li>`).join('')}</ul>`,
          { cls: 'ws-l1', foot: 'Each department in its own unit; values are never compared across departments.' })}
        ${UI.wsPanel('Recent management decisions', 'bi-clock-history', recent.length ? HM.list(recent.map(x => HM.entry(x.go, `<b>${UI.esc(x.ref)}</b> <span class="mo-ref">${UI.esc(x.type)}</span>`,
            `${UI.esc(x.decider || '')}${x.decider ? ' · ' : ''}${UI.datetime(x.decided_at)}`, UI.badge(x.status))), '')
          : HM.empty('No management decision was recorded in the last 90 days.'),
          { cls: 'ws-r1', count: recent.length, tipHtml: HM.sug(waiting.map(x => ({ text: `Review ${x.ref} (${x.issue}), waiting since ${UI.date(x.since)}.`, go: x.go, label: 'Review' })), 'No approval is waiting for you.') })}
      </div>`;
    UI.bindTips(ctx.el);
    const pr = document.createElement('button'); pr.className = 'btn btn-sm btn-outline-secondary'; pr.innerHTML = '<i class="bi bi-printer"></i> Print';
    pr.onclick = () => UI.print(`<h2>Executive Report</h2><div>${UI.datetime(new Date().toISOString())}</div>` + ctx.el.innerHTML); ctx.tools.append(pr);
  },
});

// ---------------------------------------------------------------- Analytics: topic-based, mixed visualizations
App.page('admin_analytics', {
  title: 'Analytics',
  subtitle: 'What happened, what contributed, and which areas need investigation — by topic.',
  render(ctx) {
    HM.destroyCharts();
    const d = ctx.data, q = ctx.query, per = d.period || {}, b = d.buckets || [], topic = d.topic || 'operations', sg = d.suggestions || {};
    const unit = { day: 'day', week: 'week', month: 'month' }[per.granularity] || 'period';
    const tag = (k) => `<span class="hm-kind hm-kind-${k}">${{ d: 'Descriptive', g: 'Diagnostic' }[k]}</span>`;
    const panel = (cls, title, kind, body, o = {}) => UI.wsPanel(title, 'bi-graph-up', `${tag(kind)}${body}`, { cls, ...o });
    const tabs = UI.statusTabs('admin_analytics', q, 'topic', [['operations', 'Operations'], ['maintenance', 'Maintenance'], ['purchasing', 'Purchasing'], ['finance', 'Finance']], topic);
    const sugg = (list, empty) => ({ tipHtml: HM.sug(list || [], empty || 'No supported recommendation for this view.') });
    const has = (arr) => (arr || []).some(x => +x);
    const C = { g: '#2f7d57', a: '#d97706', b: '#2563eb', p: '#9333ea', r: '#b91c1c', s: '#64748b' }, PAL = ['#2f7d57', '#d97706', '#2563eb', '#9333ea', '#64748b', '#0891b2', '#b91c1c', '#a16207'];
    let html = '', draw = [];
    if (topic === 'operations') {
      const a = d.activity || {}, st = d.status || [], inc = d.incidents || [], dp = d.dispatch || [];
      html = `<div class="ws-grid2 ws-report-row">
        ${panel('ws-l1', 'Trip activity over time', 'd', has(a.created) || has(a.completed) ? HM.canvas('hmA', 'Trips created and completed per ' + unit) : HM.empty('No trip was created or completed in this period.'), sugg(sg.main, 'No trip is on hold, waiting for reschedule or resources.'))}
        ${panel('ws-r1', 'Trip status distribution', 'g', st.length ? HM.canvas('hmB', 'Trips created in the period by current status') : HM.empty('No trip was created in this period.'))}
        ${panel('ws-l2', 'Incidents by type', 'g', inc.length ? HM.canvas('hmC', 'Incidents by recorded type') : HM.empty('No incident was recorded in this period.'))}
        ${panel('ws-r2', 'Completed trips by dispatcher division and shift', 'g', dp.length ? HM.canvas('hmD', 'Completed trips by division and shift') : HM.empty('No completed trip in this period, so dispatcher divisions cannot be compared.'))}</div>`;
      draw = [() => HM.chart(ctx.el.querySelector('#hmA'), 'line', b.map(x => x.short), [{ label: 'Trips created', data: a.created, color: C.b }, { label: 'Trips completed', data: a.completed, color: C.g }], { axis: 'Trips', titles: b.map(x => x.label) }),
        () => HM.chart(ctx.el.querySelector('#hmB'), 'bar', st.map(x => x.label), [{ label: 'Trips', data: st.map(x => +x.n), color: C.b }], { axis: 'Trips' }),
        () => HM.chart(ctx.el.querySelector('#hmC'), 'hbar', inc.map(x => x.label), [{ label: 'Incidents', data: inc.map(x => +x.n), color: C.r }], { axis: 'Incidents' }),
        () => HM.chart(ctx.el.querySelector('#hmD'), 'bar', dp.map(x => x.label), [{ label: 'Completed trips', data: dp.map(x => +x.n), color: C.g }], { axis: 'Trips' })];
    } else if (topic === 'maintenance') {
      const r = d.report || {}, tl = r.timeline || {}, mb = r.buckets || [], fq = (r.frequency || []).filter(x => +x.repairs > 0), cost = r.cost || {};
      const cats = [{ label: 'Parts used', value: +cost.parts || 0 }, ...(cost.types || []).map(t => ({ label: t.type, value: +t.total }))].filter(x => x.value > 0);
      html = `<div class="ws-grid2 ws-report-row">
        ${panel('ws-l1', 'Maintenance activity timeline', 'd', has(tl.reported) || has(tl.completed) ? HM.canvas('hmA', 'Repairs reported and completed') : HM.empty('No repair was reported or completed in this period.'), sugg(sg.main, 'No repair is waiting for verification or for parts.'))}
        ${panel('ws-r1', 'Repair frequency by truck', 'g', fq.length ? HM.canvas('hmB', 'Repairs per truck', 240) : HM.empty('No repair was reported in this period.'), sugg(sg.trucks, 'Repair counts per truck are shown as recorded; no rule applies.'))}
        ${panel('ws-l2', 'Maintenance cost composition', 'g', cats.length ? HM.canvas('hmC', 'Maintenance cost by component') + `<div class="mr-note">Total ${UI.money(cost.total)}: parts used (release date) and recorded repair costs (date incurred).</div>` : HM.empty('No maintenance cost was recorded in this period.'))}</div>`;
      draw = [() => HM.chart(ctx.el.querySelector('#hmA'), 'line', mb.map(x => x.short), [{ label: 'Reported', data: tl.reported, color: C.a }, { label: 'Completed', data: tl.completed, color: C.g }, ...(tl.under_repair ? [{ label: 'Under repair (end of period)', data: tl.under_repair, color: C.b }] : [])], { axis: 'Repairs', titles: mb.map(x => x.label) }),
        () => HM.chart(ctx.el.querySelector('#hmB'), 'hbar', fq.map(x => x.plate_number), [{ label: 'Repairs', data: fq.map(x => +x.repairs), color: C.g }], { axis: 'Repairs', title: 'Repairs',
          detail: (i) => HM.pp(fq[i].plate_number, [['Repairs in the period', UI.num(fq[i].repairs)], ['Truck type', UI.esc(fq[i].truck_type || '')]], [['maintenance?view=all&cancelled=0&vehicle_id=' + fq[i].id + '&from=' + per.from + '&to=' + per.to, 'Open repairs']]) }),
        () => HM.chart(ctx.el.querySelector('#hmC'), 'doughnut', cats.map(x => x.label), [{ label: 'Cost', data: cats.map(x => x.value), colors: PAL }], { money: true })];
    } else if (topic === 'purchasing') {
      const r = d.report || {}, fl = r.flow || {}, pb = r.buckets || [], top = (r.topUsed || []).slice(0, 8), ps = r.poStatus || [], inv = d.inventory || [];
      html = `<div class="ws-grid2 ws-report-row">
        ${panel('ws-l1', 'Stock in vs stock out (value)', 'd', has(fl.in) || has(fl.out) ? HM.canvas('hmA', 'Stock value received and issued') : HM.empty('No stock was received or issued in this period.'))}
        ${panel('ws-r1', 'Most used parts (value issued)', 'g', top.length ? HM.canvas('hmB', 'Parts issued, ranked by value') + '<div class="mr-note">Ranked by value (quantity × recorded unit cost); quantities keep their own units.</div>' : HM.empty('No part was issued in this period.'), sugg(sg.main, 'No part is low on stock right now.'))}
        ${panel('ws-l2', 'Purchase order status distribution', 'g', ps.length ? HM.canvas('hmC', 'Purchase orders created in the period by status') : HM.empty('No purchase order was created in this period.'))}
        ${panel('ws-r2', 'Inventory value by category (now)', 'd', inv.length ? HM.canvas('hmD', 'Inventory value by category') : HM.empty('No active part has stock value.'))}</div>`;
      draw = [() => HM.chart(ctx.el.querySelector('#hmA'), 'line', pb.map(x => x.short), [{ label: 'Stock in', data: fl.in, color: C.g }, { label: 'Stock out', data: fl.out, color: C.a }], { money: true, axis: 'Value (PHP)', titles: pb.map(x => x.label) }),
        () => HM.chart(ctx.el.querySelector('#hmB'), 'hbar', top.map(x => x.part_number), [{ label: 'Value issued', data: top.map(x => +x.value), color: C.g }], { money: true, axis: 'Value (PHP)', title: 'Part',
          detail: (i) => HM.pp(top[i].part_number, [['Issued', UI.num(top[i].units) + ' ' + UI.esc(top[i].unit || '')], ['Value', UI.money(top[i].value)], ['Releases', UI.num(top[i].releases)], ['On hand now', UI.num(top[i].quantity_on_hand)]], [['inventory?open=' + top[i].id, 'Open part']]) }),
        () => HM.chart(ctx.el.querySelector('#hmC'), 'bar', ps.map(x => x.status), [{ label: 'Purchase orders', data: ps.map(x => +x.n), color: C.b }], { axis: 'POs' }),
        () => HM.chart(ctx.el.querySelector('#hmD'), 'doughnut', inv.map(x => x.label), [{ label: 'Stock value', data: inv.map(x => +x.value), colors: PAL }], { money: true })];
    } else {
      const ex = d.expenses || [], wks = d.weeks || [], mb = d.monthlyBudget && !Array.isArray(d.monthlyBudget) ? d.monthlyBudget : {}, ag = d.aging || [], pdue = d.payDue || [];
      html = `<div class="ws-grid2 ws-report-row">
        ${panel('ws-l1', 'Recorded expense distribution', 'g', ex.length ? HM.canvas('hmA', 'Recorded expenses by category') + `<div class="mr-note">Period total ${UI.money(ex.reduce((a, x) => a + +x.value, 0))}; each expense recorded once.</div>` : HM.empty('No expense was recorded in this period.'), sugg(sg.main, 'No spending concentration or receivable issue under the current rules.'))}
        ${panel('ws-r1', 'Weekly client collections', 'd', wks.some(x => +x.amount) ? HM.canvas('hmB', 'Collections per week') : HM.empty('No client collection was recorded in this period.'))}
        ${panel('ws-l2', 'Budget allocation', 'd', mb.id ? HM.canvas('hmC', 'Monthly Purchasing budget allocation', 120) + `<div class="mr-note">Finance's monthly Purchasing budget (${UI.date(mb.period_start)} – ${UI.date(mb.period_end)}). The weekly collection-funded allocation is not recorded yet.</div>` : HM.empty('No active budget is recorded.'))}
        ${panel('ws-r2', 'Receivables aging and payables by due date', 'g', ag.some(x => +x.value) || pdue.some(x => +x.value) ? HM.canvas('hmD', 'Receivables aging and payables by due category') : HM.empty('No open receivable or payable.'))}</div>`;
      draw = [() => HM.chart(ctx.el.querySelector('#hmA'), 'doughnut', ex.map(x => x.label), [{ label: 'Expenses', data: ex.map(x => +x.value), colors: PAL }], { money: true }),
        () => HM.chart(ctx.el.querySelector('#hmB'), 'bar', wks.map(x => x.label.replace('Week of ', '')), [{ label: 'Collections', data: wks.map(x => +x.amount), color: C.g }], { money: true, axis: 'Collected (PHP)', titles: wks.map(x => x.label) }),
        () => HM.chart(ctx.el.querySelector('#hmC'), 'stack', ['Budget'], [{ label: 'Spent', data: [+mb.spent_amount], color: C.b }, { label: 'Committed', data: [+mb.reserved_amount], color: C.a }, { label: 'Available', data: [+mb.available], color: '#cbd5e1' }], { money: true }),
        () => HM.chart(ctx.el.querySelector('#hmD'), 'hbar', [...ag.map(x => 'Receivable: ' + x.label), ...pdue.map(x => 'Payable: ' + x.label)], [{ label: 'Balance', data: [...ag.map(x => +x.value), ...pdue.map(x => +x.value)], color: C.b }], { money: true, axis: 'Balance (PHP)' })];
    }
    ctx.el.innerHTML = `${tabs}${UI.wsPeriod('admin_analytics', per).replaceAll('admin_analytics?', 'admin_analytics?topic=' + encodeURIComponent(topic) + '&amp;')}<div class="small text-muted mb-2">Descriptive: what happened · Diagnostic: what contributed · Prescriptive: the lightbulb suggestions, from documented rules only.</div>${html}`;
    UI.bindPeriod(ctx.el); UI.bindTips(ctx.el);
    draw.forEach(fn => { try { fn(); } catch (e) { console.error(e); } });
  },
});
