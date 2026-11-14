/* assets/js/modules/maintenance/history.js — MAINTENANCE HISTORY
   Finished and cancelled maintenance records with cost and attachment count. Opening a record
   shows the full detail (incident, photos, parts, costs) from Maintenance.details, which checks the role again.
   Backend: backend/Modules/Maintenance/Maintenance.php (read, page maint_history) */
'use strict';
App.page('maint_history', {
  title: 'Maintenance History',
  subtitle: 'Review completed repairs.',
  render(ctx) {
    const d = ctx.data, q = ctx.query;
    const rows = (d.records || []).map(r => ({ ...r, total_cost: (+r.parts_cost || 0) + (+r.other_cost || 0),
      display_status: r.status === 'Cancelled' ? 'Cancelled' : (r.verified_at ? 'Returned to service' : 'Awaiting verification') }));
    // REF_FIDELITY_V1: outcome tabs (?status=) + truck / source filters, then the reference list (search + Reset + table).
    const base = rows.filter(r => (!q.truck || r.plate_number === q.truck) && (!q.source || r.source === q.source));
    const OUT = ['Returned to service', 'Awaiting verification', 'Cancelled'];
    const tab = OUT.includes(q.status) ? q.status : '';
    const shown = base.filter(r => !tab || r.display_status === tab);
    const total = shown.reduce((a, r) => a + r.total_cost, 0);
    ctx.el.innerHTML = UI.statusTabs('maint_history', q, 'status', [['', 'All', base.length], ...OUT.map(o => [o, o, base.filter(r => r.display_status === o).length])], tab)
      + UI.filterModal(ctx, [
        { name: 'truck', label: 'Truck', type: 'select', options: (d.trucks || []).map(t => [t.plate_number, t.plate_number]), col: 6 },
        { name: 'source', label: 'Source', type: 'select', options: [['Incident', 'Trip incident'], ['On-Premise', 'Garage report']], col: 6 }],
        { hint: 'All trucks and sources.' });
    const box = document.createElement('div'); ctx.el.append(box);
    const open = (r) => App.go('maintenance', { open: r.id });
    box.innerHTML = UI.table([
      ['Record', 'job_ref', (v, r) => `<b>${UI.esc(v)}</b><div class="cell-sub">${r.source === 'Incident' ? 'Incident ' + UI.esc(r.incident_ref || '') : 'Garage report'}</div>`],
      ['Truck', 'plate_number'], ['Problem', 'problem', (v) => `<span class="cell-clip">${UI.esc(v)}</span>`], ['Mechanic', 'mechanic'],
      ['Completed', 'completed_on', 'date'], ['Outcome', 'display_status', 'badge'], ['Total cost', 'total_cost', 'money']], shown,
      { showAllColumns: true, directActions: true, actions: () => UI.ibtn('view', 'Open record', 'bi-eye'), onAction: (a, r) => open(r), rowClick: open,
        empty: 'No finished maintenance records match.' })
      + `<p class="small text-muted mt-2">Total cost of the records shown: <b>${UI.money(total)}</b> (released parts + labor and outside services).</p>`;
  },
});
