/* assets/js/modules/administration/audit.js — AUDIT WORKSPACE (AUDIT_WORKSPACE_V1, read only)
   audit_overview  recent activity, consequential events, activity by area (last 7 days)
   audit_logs      every recorded event with filters and the field-level changes
   audit_history   one line per record (record_ref) with its full timeline
   audit_reports   counts by area, action type and user for a date range
   Source: audit_events + audit_event_changes (backend/Modules/Administration/Admin.php auditPage). Nothing here writes. */
'use strict';
const AuditUI = {
  /** Source record page for a record_ref, only where that page can open the record and the user may open the page. */
  source(ref) {
    const [kind, id] = String(ref || '').split(' ');
    if (!/^\d+$/.test(id || '')) return null;
    const go = { po: `purchase_orders?open=${id}`, purchase: `purchase_orders?open=${id}`, request: `parts_requests?open=${id}`, part: `inventory?open=${id}`,
      job: `maintenance?open=${id}`, maintenance: `maintenance?open=${id}`, budget: 'finance_budgets', payable: 'finance?tab=ap', user: 'admin' }[kind];
    return go && UI.canGo(go) ? go : null;
  },
  refLabel(ref) {
    const [kind, id] = String(ref || '').split(' ');
    const name = { po: 'Purchase order', purchase: 'Purchase order (document)', request: 'Parts request', part: 'Inventory part', job: 'Repair record', maintenance: 'Repair record (document)',
      mechanic: 'Mechanic', vehicle: 'Truck', user: 'User account', budget: 'Purchasing budget', payable: 'Payable', expense: 'Expense', trip: 'Trip', billing: 'Billing', client: 'Client', route: 'Client route' }[kind];
    return name ? `${name} #${id}` : ref;
  },
  changes(e) { try { const c = JSON.parse(e.changes_json || 'null'); return Array.isArray(c) ? c : []; } catch { return []; } },
  open(e, d) {
    const ch = AuditUI.changes(e), src = AuditUI.source(e.record_ref);
    const html = UI.kv([['Date and time', UI.datetime(e.created_at)], ['Performed by', UI.esc(e.actor) + (e.role ? ` <span class="cell-sub">· ${UI.esc(d.roles[e.role] || e.role)}</span>` : '')],
      ['Area', UI.esc(e.area)], ['Record', UI.esc(AuditUI.refLabel(e.record_ref))], ['Action', `<b>${UI.esc(e.action)}</b> <span class="badge-s">${UI.esc(e.type)}</span>`],
      ['Details', UI.e(e.details || 'No additional description was recorded.')]])
      + (ch.length ? UI.h('Recorded changes') + UI.table([['Field', 'field'], ['Before', 'before', v => UI.esc(v === null || v === '' ? '—' : String(v))], ['After', 'after', v => UI.esc(v === null || v === '' ? '—' : String(v))]], ch, { plain: true, limit: 30 })
        : '<p class="small text-muted mt-3">No field-by-field changes were recorded for this event.</p>')
      + `<div class="d-flex flex-wrap gap-2 mt-3">${src ? '<button type="button" class="btn btn-sm btn-outline-primary" data-src><i class="bi bi-box-arrow-up-right"></i> Open source record</button>' : ''}
        ${UI.canGo('audit_history') ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-hist><i class="bi bi-clock-history"></i> Full history of this record</button>' : ''}</div>`;
    const el = UI.drawer('Audit event', html, { subtitle: 'Read-only record' });
    el.querySelector('[data-src]')?.addEventListener('click', () => { UI.closeDrawer(); UI.goTo(src); });
    el.querySelector('[data-hist]')?.addEventListener('click', () => { UI.closeDrawer(); App.go('audit_history', { record: e.record_ref, from: d.range.from, to: d.range.to }); });
  },
  rangeBar(ctx, d) {
    return `<div class="ws-filters no-print" role="group" aria-label="Date range"><span class="small text-muted">Dates</span>
      <input type="date" class="form-control form-control-sm" data-gf="from" value="${UI.esc(d.range.from)}" aria-label="Start date" title="Start date">
      <input type="date" class="form-control form-control-sm" data-gf="to" value="${UI.esc(d.range.to)}" aria-label="End date" title="End date">
      ${ctx.query.from || ctx.query.to ? '<button type="button" class="btn btn-link btn-sm ws-filter-reset" data-gf-reset="from,to"><i class="bi bi-x-circle"></i> Default range</button>' : ''}</div>`;
  },
  table(rows, d, opts = {}) {
    return UI.table([['Date and time', 'created_at', 'datetime'], ['User', 'actor', (v, e) => `${UI.esc(v)}${e.role ? `<div class="cell-sub">${UI.esc(d.roles[e.role] || e.role)}</div>` : ''}`],
      ['Area', 'area'], ['Action', 'action', (v, e) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.esc(e.type)}${e.has_changes ? ' · fields recorded' : ''}</div>`],
      ['Record', 'record_ref', (v) => UI.esc(AuditUI.refLabel(v))], ['Details', 'details', (v) => `<span class="cell-clamp" title="${UI.esc(v || '')}">${UI.esc(v || '')}</span>`]], rows,
      { title: opts.title || 'Audit events (' + rows.length + ')', showAllColumns: true, directActions: true, limit: opts.limit || 25,
        actions: (e) => UI.ibtn('open', 'Inspect event', 'bi-eye', 'outline-primary') + (AuditUI.source(e.record_ref) ? UI.ibtn('src', 'Open source record', 'bi-box-arrow-up-right') : ''),
        onAction: (a, e) => a === 'src' ? UI.goTo(AuditUI.source(e.record_ref)) : AuditUI.open(e, d), rowClick: (e) => AuditUI.open(e, d), empty: opts.empty || 'No audit event matches.' });
  },
  bars(rows, label, value, go) {
    const max = Math.max(1, ...rows.map(r => +r[value]));
    return rows.map(r => `<div class="ws-bar ${go ? 'clickable' : ''}" ${go ? `data-go="${UI.esc(go(r))}" role="link" tabindex="0"` : ''}><div class="ws-bar-top"><span>${UI.esc(r[label])}</span><b>${UI.num(r[value])}</b></div>
      <div class="ws-bar-track"><div class="ws-bar-fill tone-blue" style="width:${(+r[value] / max * 100).toFixed(1)}%"></div></div></div>`).join('');
  },
};

// ---------------------------------------------------------------- Audit Overview (Audit landing page)
App.page('audit_overview', {
  title: 'Audit Overview',
  subtitle: 'Recorded system activity: who did what, when, on which record (read only).',
  render(ctx) {
    const d = ctx.data, ev = d.events || [], t = d.totals || {};
    const count = (type) => ev.filter(e => e.type === type).length;
    const users = new Set(ev.map(e => e.actor)).size;
    const r7 = `from=${d.range.from}&to=${d.range.to}`;
    ctx.el.innerHTML = `<div class="maint-overview mp-ws">${UI.kpiTiles([
      { label: 'Events, last 7 days', value: ev.length, note: UI.num(t.today || 0) + ' today · ' + UI.num(t.last30 || 0) + ' in 30 days', icon: 'bi-journal-text', tone: 'blue', go: 'audit_logs?' + r7 },
      { label: 'Approvals', value: count('Approval'), note: 'approved / verified, last 7 days', icon: 'bi-check2-circle', tone: 'green', go: 'audit_logs?type=Approval&' + r7 },
      { label: 'Rejections', value: count('Rejection'), note: 'rejected / failed, last 7 days', icon: 'bi-x-octagon', tone: count('Rejection') ? 'amber' : 'gray', go: 'audit_logs?type=Rejection&' + r7 },
      { label: 'Payments and releases', value: count('Payment'), note: 'money events, last 7 days', icon: 'bi-cash-coin', tone: 'blue', go: 'audit_logs?type=Payment&' + r7 },
      { label: 'Cancellations', value: count('Cancellation'), note: 'cancelled, closed, deactivated', icon: 'bi-slash-circle', tone: count('Cancellation') ? 'amber' : 'gray', go: 'audit_logs?type=Cancellation&' + r7 },
      { label: 'Active users', value: users, note: 'users with recorded activity, last 7 days', icon: 'bi-people', tone: 'gray', go: 'audit_reports?' + r7 }])}
      ${UI.quickBar([{ label: 'Search audit logs', icon: 'bi-search', go: 'audit_logs' }, { label: 'Trace a transaction', icon: 'bi-clock-history', go: 'audit_history' },
        { label: 'Audit reports', icon: 'bi-clipboard-data', go: 'audit_reports' }])}
      <div class="mo-grid"><div class="mo-main" data-recent></div><div class="mo-side">
        <section class="panel"><div class="panel-head"><h2 class="panel-title"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Activity by area (last 7 days)</h2></div><div class="panel-body">
          ${ev.length ? AuditUI.bars(Object.entries(ev.reduce((a, e) => (a[e.area] = (a[e.area] || 0) + 1, a), {})).map(([area, n]) => ({ area, n })).sort((a, b) => b.n - a.n), 'area', 'n', (r) => 'audit_logs?area=' + encodeURIComponent(r.area) + '&' + r7) : '<div class="text-muted">No activity in the last 7 days.</div>'}
          <div class="ws-foot">${UI.num(t.all_time || 0)} events recorded since ${t.first_at ? UI.date(t.first_at) : '—'}; ${UI.num(d.withChanges || 0)} with field-level changes.</div></div></section>
      </div></div></div>`;
    const key = ev.filter(e => ['Approval', 'Rejection', 'Payment', 'Cancellation'].includes(e.type));
    ctx.el.querySelector('[data-recent]').innerHTML = AuditUI.table(key, d, { title: 'Approvals, rejections, payments and cancellations (' + key.length + ')', limit: 8, empty: 'No consequential event in the last 7 days.' })
      + AuditUI.table(ev, d, { title: 'All recent activity (' + ev.length + ')', limit: 8 });
  },
});

// ---------------------------------------------------------------- Audit Logs
App.page('audit_logs', {
  title: 'Audit Logs',
  subtitle: 'Every recorded event with its user, time, record and recorded field changes.',
  render(ctx) {
    const d = ctx.data, q = ctx.query;
    const rows = (d.events || []).filter(e => (!q.type || e.type === q.type) && (q.changes !== '1' || e.has_changes));
    ctx.el.innerHTML = (UI.canGo('audit_overview') ? UI.crumb('audit_overview', 'Audit Overview') : '') + AuditUI.rangeBar(ctx, d)
      + UI.generalFilters(ctx, [{ name: 'area', label: 'Areas', options: d.areas || [] }, { name: 'actor_id', label: 'Users', options: (d.actors || []).map(u => [u.id, u.full_name]) },
        { name: 'type', label: 'Action types', options: d.types || [] }, { name: 'changes', label: 'Recorded changes', all: 'With or without field changes', options: [['1', 'Only events with field changes']] }])
      + `<div class="small text-muted mb-2">${UI.date(d.range.from)} – ${UI.date(d.range.to)} (at most ${d.range.max_days} days; latest 3,000 events). Action types follow fixed keywords in the recorded action (e.g. "approved" = Approval).</div>`;
    const box = document.createElement('div'); ctx.el.append(box);
    box.innerHTML = AuditUI.table(rows, d);
  },
});

// ---------------------------------------------------------------- Transaction History
App.page('audit_history', {
  title: 'Transaction History',
  subtitle: 'One line per record (purchase order, request, repair, user …) with its complete recorded timeline.',
  render(ctx) {
    const d = ctx.data, q = ctx.query;
    const kinds = [...new Set((d.records || []).map(r => String(r.record_ref).split(' ')[0]))].sort();
    const rows = (d.records || []).filter(r => !q.kind || String(r.record_ref).startsWith(q.kind + ' '));
    ctx.el.innerHTML = (UI.canGo('audit_overview') ? UI.crumb('audit_overview', 'Audit Overview') : '') + AuditUI.rangeBar(ctx, d)
      + UI.generalFilters(ctx, [{ name: 'area', label: 'Areas', options: d.areas || [] }, { name: 'kind', label: 'Record types', options: kinds.map(k => [k, AuditUI.refLabel(k + ' 0').replace(/ #0$/, '')]) },
        ...(q.record ? [{ name: 'record', label: 'Record', options: [[q.record, AuditUI.refLabel(q.record)]], all: 'All records' }] : [])]);
    const box = document.createElement('div'); ctx.el.append(box);
    if (q.record) {
      const tl = d.timeline || [], src = AuditUI.source(q.record);
      box.innerHTML = `<section class="panel"><div class="panel-head"><h2 class="panel-title">${UI.esc(AuditUI.refLabel(q.record))} · ${tl.length} event(s)</h2><div class="panel-tools">${src ? `<button type="button" class="btn btn-sm btn-outline-primary" data-go="${UI.esc(src)}"><i class="bi bi-box-arrow-up-right"></i> Open source record</button>` : ''}</div></div>
        <div class="panel-body">${tl.length ? `<ul class="ws-timeline">${[...tl].reverse().map(e => `<li><b>${UI.esc(e.action)}</b> · ${UI.esc(e.actor)} <span class="badge-s">${UI.esc(e.type)}</span><div class="tl-when">${UI.datetime(e.created_at)} · ${UI.esc(e.area)}</div>
          ${e.details ? `<div class="cell-sub">${UI.esc(e.details)}</div>` : ''}${AuditUI.changes(e).map(c => `<div class="cell-sub">${UI.esc(c.field)}: ${UI.esc(c.before ?? '—')} → <b>${UI.esc(c.after ?? '—')}</b></div>`).join('')}</li>`).join('')}</ul>` : '<div class="text-muted">No event for this record in the chosen dates.</div>'}</div></section>`;
      return;
    }
    box.innerHTML = UI.table([['Record', 'record_ref', (v) => `<b>${UI.esc(AuditUI.refLabel(v))}</b>`], ['Area', 'area'], ['Events', 'events', 'num'], ['First event', 'first_at', 'datetime'],
      ['Last action', 'last_action', (v, r) => `${UI.esc(v)}<div class="cell-sub">${UI.esc(r.last_actor)} · ${UI.datetime(r.last_at)}</div>`]], rows,
      { title: 'Records with activity (' + rows.length + ')', showAllColumns: true, directActions: true,
        actions: (r) => UI.ibtn('hist', 'Show timeline', 'bi-clock-history', 'outline-primary') + (AuditUI.source(r.record_ref) ? UI.ibtn('src', 'Open source record', 'bi-box-arrow-up-right') : ''),
        onAction: (a, r) => a === 'src' ? UI.goTo(AuditUI.source(r.record_ref)) : App.go('audit_history', { ...q, record: r.record_ref }),
        rowClick: (r) => App.go('audit_history', { ...q, record: r.record_ref }), empty: 'No record has activity in the chosen dates.' });
  },
});

// ---------------------------------------------------------------- Audit Reports
App.page('audit_reports', {
  title: 'Audit Reports',
  subtitle: 'Recorded activity counted by area, action type and user for the chosen dates.',
  render(ctx) {
    const d = ctx.data, s = d.summary || { total: 0, byType: {}, byUser: [], byArea: [] };
    const r = `from=${d.range.from}&to=${d.range.to}`;
    const types = Object.entries(s.byType).map(([type, n]) => ({ type, n })).sort((a, b) => b.n - a.n);
    const byUser = [...s.byUser].sort((a, b) => b.n - a.n), byArea = [...s.byArea].sort((a, b) => b.n - a.n);
    const months = [...new Set((d.byMonth || []).map(x => x.m))], areas = [...new Set((d.byMonth || []).map(x => x.area))];
    ctx.el.innerHTML = (UI.canGo('audit_overview') ? UI.crumb('audit_overview', 'Audit Overview') : '') + AuditUI.rangeBar(ctx, d)
      + UI.generalFilters(ctx, [{ name: 'area', label: 'Areas', options: d.areas || [] }, { name: 'actor_id', label: 'Users', options: (d.actors || []).map(u => [u.id, u.full_name]) }])
      + `<div class="small text-muted mb-2">${UI.num(s.total)} event(s), ${UI.date(d.range.from)} – ${UI.date(d.range.to)}.${s.truncated ? ' <b>Only the latest 20,000 events are counted: narrow the dates.</b>' : ''} Counts of recorded events only; they describe activity and are not a performance score.</div>`
      + `<div class="mo-grid maint-overview"><div class="mo-main">`
      + UI.table([['Area', 'area'], ['Events', 'n', 'num'], ['With field changes', 'changes', 'num']], byArea, { title: 'Events by area', showAllColumns: true, directActions: true,
        actions: () => UI.ibtn('logs', 'Show these events', 'bi-journal-text', 'outline-primary'), onAction: (a, x) => App.go('audit_logs', { ...ctx.query, area: x.area }), empty: 'No events.' })
      + UI.table([['User', 'actor'], ['Role', 'role', (v) => UI.esc(d.roles[v] || v || 'System')], ['Events', 'n', 'num']], byUser, { title: 'Events by user', showAllColumns: true, empty: 'No events.' })
      + `</div><div class="mo-side"><section class="panel"><div class="panel-head"><h2 class="panel-title">Events by action type</h2></div><div class="panel-body">
        ${types.length ? AuditUI.bars(types, 'type', 'n', (x) => 'audit_logs?type=' + encodeURIComponent(x.type) + '&' + r) : '<div class="text-muted">No events.</div>'}
        <div class="ws-foot">Type rule: keywords in the recorded action, e.g. approved/verified = Approval, rejected/failed = Rejection, payment/paid/released = Payment, signed in/login = Sign-in, time in/out = Attendance.</div></div></section></div></div>`
      + (months.length ? UI.blocks({ charts: [{ type: 'bar', title: 'Events per month by area', labels: months,
          datasets: areas.map(a => ({ label: a, data: months.map(m => +((d.byMonth.find(x => x.m === m && x.area === a) || {}).n || 0)) })) }] }) : '');
  },
});
