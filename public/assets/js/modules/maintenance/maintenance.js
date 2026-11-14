/* MAINTENANCE_CLEANUP_41_43_V1: no priority/ETA controls; preserve safety repair plan. */
/* assets/js/modules/maintenance/maintenance.js — CORRECTIVE MAINTENANCE
   Unexpected truck problems: report -> assign mechanic (Under Inspection) -> findings and
   diagnosis (Under Repair) -> corrective action + evidence (Completed) -> verify and return the
   truck to service. Trip incidents arrive here automatically and are assessed Minor / Major.
   Backend: backend/Modules/Maintenance/Maintenance.php */
'use strict';
const MaintUI = {
  data: null,
  STAGES: ['Reported', 'Under Inspection', 'Under Repair', 'Completed', 'Returned to service'],
  /** Filters of the Open records list (Overview cards link here). Same rules as the backend overview counts. */
  VIEWS: {
    reported: ['New reports', (j) => j.status === 'Reported'],
    in_progress: ['Under inspection or repair', (j) => ['Under Inspection', 'Under Repair'].includes(j.status)],
    inspection: ['Under inspection', (j) => j.status === 'Under Inspection'],
    repair: ['Under repair', (j) => j.status === 'Under Repair'],
    verify: ['Awaiting verification', (j) => j.status === 'Completed' && !j.verified_at],
  },
  stageOf(j) {
    if (j.status === 'Cancelled') return [0, true];
    if (j.verified_at) return [5, false];
    return [Math.max(0, MaintUI.STAGES.indexOf(j.status)), false];
  },
  /** Plain-language next step shown on the record. */
  nextStep(j) {
    if (j.status === 'Cancelled') return 'Cancelled.';
    if (j.verified_at) return j.source === 'Incident' && j.severity === 'Minor' && j.incident_status !== 'Resolved' && j.incident_status !== 'Repaired - Cleared to Continue'
      ? 'Repair verified. The trip stays On Hold until Motorpool records the vehicle clearance.'
      : (j.repair_performed ? 'Repair verified. The truck is back in service.' : 'Inspection only: no repair was needed. The truck is back in service.');
    if (j.status === 'Reported') return j.source === 'Incident' ? 'Assess the incident (Minor or Major) and choose the mechanic.' : 'Assign an available mechanic to start the inspection.';
    if (j.status === 'Under Inspection') return 'Record the inspection findings and the diagnosis.';
    if (j.status === 'Under Repair') return 'Record the corrective action, the completion date and the evidence.';
    return 'Check the truck, then verify it to return it to service.';
  },
  ACTIVE: ['Reported', 'Under Inspection', 'Under Repair'],
  /** JOB_HELPER_MECHANICS_V1 — assisting mechanics of one repair: active ones (removable) and past ones (history). */
  helperList(helpers, canEdit) {
    const now = (helpers || []).filter(h => !h.released_at), past = (helpers || []).filter(h => h.released_at);
    return (now.length ? now.map(h => `<div class="d-flex align-items-center gap-2 flex-wrap"><span>${UI.esc(h.full_name)} · ${UI.esc(h.mechanic_code)}</span><span class="cell-sub">since ${UI.datetime(h.assigned_at)} · ${UI.esc(h.assigned_by_name)}</span>
        ${canEdit ? `<button type="button" class="btn btn-sm btn-outline-danger icon-act ms-auto" data-rmh="${+h.mechanic_id}" data-name="${UI.esc(h.full_name)}" title="Remove ${UI.esc(h.full_name)}" aria-label="Remove ${UI.esc(h.full_name)}"><i class="bi bi-person-dash"></i></button>` : ''}</div>`).join('') : '<span class="text-muted">None</span>')
      + (past.length ? `<details class="mt-1"><summary class="small text-muted">${past.length} earlier assisting assignment(s)</summary>${past.map(h => `<div class="cell-sub">${UI.esc(h.full_name)}: ${UI.datetime(h.assigned_at)} – ${UI.datetime(h.released_at)}${h.release_reason ? ' · ' + UI.esc(h.release_reason) : ''}${h.released_by_name ? ' · ' + UI.esc(h.released_by_name) : ''}</div>`).join('')}</details>` : '');
  },
  /** Searchable multi-select of Available mechanics (not the lead, not already assisting). One request, all-or-nothing. */
  addHelpers(j, helpers, after) {
    const taken = new Set([+j.mechanic_id, ...(helpers || []).filter(h => !h.released_at).map(h => +h.mechanic_id)]);
    const list = (MaintUI.data.mechanics || []).filter(m => m.availability === 'Available' && !taken.has(+m.id));
    const wrap = UI.modal('Add mechanics to ' + UI.esc(j.job_ref), `<p class="modal-intro">Lead mechanic: <b>${UI.esc(j.mechanic || '')}</b>. Choose the mechanics who will assist. Only timed-in, Available mechanics are listed; each can work on one repair at a time.</p>
      ${list.length ? `<input type="search" class="form-control form-control-sm mb-2" placeholder="Search name, code or specialization" aria-label="Search mechanics" data-msearch>
      <div class="mech-pick" role="group" aria-label="Available mechanics">${list.map(m => `<label class="mech-pick-row" data-text="${UI.esc((m.full_name + ' ' + m.mechanic_code + ' ' + (m.specialization || '')).toLowerCase())}">
        <input type="checkbox" class="form-check-input" value="${+m.id}"> <span><b>${UI.esc(m.full_name)}</b> · ${UI.esc(m.mechanic_code)}${m.specialization ? `<span class="cell-sub"> · ${UI.esc(m.specialization)}</span>` : ''}</span></label>`).join('')}</div>
      <div class="small text-muted mt-1" data-mcount>0 selected</div>` : '<div class="alert alert-warning mb-0">No other mechanic is Available. Time In a mechanic on the Mechanic Attendance page first.</div>'}`,
      list.length ? async (x, form) => {
        const ids = [...form.querySelectorAll('.mech-pick input:checked')].map(c => c.value);
        if (!ids.length) throw new Error('Choose at least one mechanic.');
        const res = await App.api('Maintenance.helpers_add', { job_id: j.id, mechanic_ids: ids }); UI.result(res); after();
      } : null, { size: '', submit: 'Add mechanics', confirmBeforeSubmit: false });
    const s = wrap.querySelector('[data-msearch]');
    if (s) {
      s.oninput = () => wrap.querySelectorAll('.mech-pick-row').forEach(r => { r.hidden = !r.dataset.text.includes(s.value.trim().toLowerCase()); });
      wrap.querySelector('.mech-pick').onchange = () => { wrap.querySelector('[data-mcount]').textContent = wrap.querySelectorAll('.mech-pick input:checked').length + ' selected'; };
    }
  },
  mechanicOptions(currentId) {
    return (MaintUI.data.mechanics || []).filter(m => m.availability === 'Available' || +m.id === +currentId)
      .map(m => [m.id, `${m.full_name} · ${m.mechanic_code}${m.specialization ? ' · ' + m.specialization : ''}`]);
  },
  /** Report a truck problem found at the garage. */
  report(vehicleId) {
    const d = MaintUI.data;
    const ok = d.trucks.filter(v => !v.open_job && !v.on_road);
    const mech = MaintUI.mechanicOptions();
    UI.formModalFiles('Report a truck problem', [
      { name: 'vehicle_id', label: 'Affected truck', type: 'select', required: true, col: 6, value: vehicleId,
        options: ok.map(v => [v.id, `${v.plate_number}${v.truck_number ? ' · #' + v.truck_number : ''} · ${v.truck_type} (${v.status})`]),
        help: d.trucks.length > ok.length ? 'Trucks on the road or already under repair are not listed.' : '' },
      { name: 'reported_on', label: 'Date reported', type: 'date', value: UI.today(), required: true, col: 6 },
      { name: 'problem', label: 'Problem or defect', type: 'textarea', required: true, col: 12, placeholder: 'What is wrong with the truck? Where is the damage?' },
      { name: 'mechanic_id', label: 'Assign mechanic (optional now)', type: 'select', col: 6, options: mech, placeholder: mech.length ? 'Assign later' : 'No mechanic is available',
        help: 'Only Available mechanics are listed.' },
      { name: 'remarks', label: 'Remarks', col: 6 },
      { name: 'photos', label: 'Photos of the damaged part or truck condition', type: 'file', multiple: true, required: true, col: 12, attrs: 'accept="image/png,image/jpeg"' }],
      'Maintenance.report', { submit: 'Report problem', size: 'modal-lg',
        intro: 'The truck is taken out of service at once (Under Maintenance) and cannot be assigned to trips until the repair is completed and verified. If it was reserved for a trip, the trip is re-assigned automatically.' });
  },
  /** Parts request form (Request Form). Motorpool sees part identity and availability only: no costs, no stock counts. */
  async requestParts(vehicleId, jobId) {
    const d = MaintUI.data;
    let parts; try { parts = (await App.api('Purchasing.request_catalog')).parts; } catch (e) { return UI.toast(e.message, 'error'); }
    const openJobs = (d.jobs || []).filter(j => ['Under Inspection', 'Under Repair'].includes(j.status));
    const row = () => `<div class="row g-2 mb-1 pl-row"><div class="col-9"><select class="form-select form-select-sm" data-part><option value="">Choose part…</option>
        ${parts.map(p => `<option value="${p.id}">${UI.esc(p.part_number)} · ${UI.esc(p.description)} (${UI.esc(p.availability)})</option>`).join('')}</select></div>
      <div class="col-3"><input class="form-control form-control-sm" type="number" min="1" max="100000" placeholder="Qty" data-qty></div></div>`;
    UI.modal('Request parts', UI.fields([
      { name: 'vehicle_id', label: 'Truck', type: 'select', required: true, col: 6, value: vehicleId, options: d.trucks.map(v => [v.id, v.plate_number + (v.truck_number ? ' #' + v.truck_number : '')]) },
      { name: 'maintenance_job_id', label: 'For repair record', type: 'select', col: 6, value: jobId, options: openJobs.map(j => [j.id, j.job_ref + ' · ' + j.plate_number + ' · ' + j.problem.slice(0, 30)]),
        help: 'Choose the open repair these parts are for.' },
      { name: 'purpose', label: 'Reason for the request', required: true, col: 12 }])
      + `<div class="mt-2"><div class="small text-muted mb-1">Part · Quantity</div><div data-lines>${row()}${row()}${row()}</div>
         <button type="button" class="btn btn-sm btn-link p-0" data-addrow>+ Add item</button></div>`,
      async (x, form, w) => {
        const lines = [...w.querySelectorAll('.pl-row')].map(r => ({ part_id: r.querySelector('[data-part]').value, quantity: r.querySelector('[data-qty]').value })).filter(l => l.part_id || l.quantity);
        const r = await App.api('Purchasing.request_create', { ...x, lines }); UI.result(r); App.route();
      },
      { submit: 'Send to Purchasing', onShow: (w) => { w.querySelector('[data-addrow]').onclick = () => w.querySelector('[data-lines]').insertAdjacentHTML('beforeend', row()); } });
  },
  /** Fulfilment progress of one parts request: requested vs released units and current availability. */
  async openRequest(rq) {
    let r; try { r = await App.api('Purchasing.request_details', { request_id: rq.id }); } catch (e) { return UI.toast(e.message, 'error'); }
    UI.modal('Parts request ' + UI.esc(r.request.request_ref), `<p class="small text-muted">Truck ${UI.esc(r.request.plate_number)}${r.request.job_ref ? ' · repair ' + UI.esc(r.request.job_ref) : ''} · ${UI.esc(r.request.purpose)}<br>Status: ${UI.badge(r.request.status)}${r.request.notes ? ' · ' + UI.esc(r.request.notes) : ''}</p>
      ${UI.table([['Part', 'part_number'], ['Description', 'description'], ['Requested', 'quantity', 'num'], ['Released', 'released_qty', 'num'], ['Availability now', 'availability', 'badge']], r.lines, { plain: true })}`, null, { submit: '', size: 'modal-lg' });
  },
  /** Opens the repair record; autoAct = key of a drawer action to start at once (row shortcut, same form and checks). */
  async open(id, autoAct) {
    const d = MaintUI.data;
    const seq = App.navSeq;
    let r; try { r = await App.api('Maintenance.details', { job_id: id }); } catch (e) { return UI.toast(e.message, 'error'); }
    if (seq !== App.navSeq) return;   // WS_NAV_STATE_V1: the user moved on while loading
    const j = r.job, i = r.incident, act = d.can.act;
    if (i && j.incident_status === undefined) j.incident_status = i.status; // stage text needs the incident state
    const [stage, warn] = MaintUI.stageOf(j);
    const acts = [];
    if (act) {
      if (j.source === 'Incident' && j.status === 'Reported') acts.push(['assess', 'Select repair plan', 'primary', 'bi-clipboard-pulse']);
      if (j.source !== 'Incident' && j.status === 'Reported') acts.push(['mech', 'Assign mechanic', 'primary', 'bi-person-gear']);
      if (j.status === 'Under Inspection') acts.push(['inspect', 'Record findings', 'primary', 'bi-search']);
      if (j.status === 'Under Repair') acts.push(['complete', 'Complete repair', 'primary', 'bi-check2-circle']);
      if (j.status === 'Completed' && !j.verified_at) acts.push(['verify', 'Verify & return to service', 'primary', 'bi-shield-check']);
      // INCIDENT_CLEARANCE_V1: Motorpool's Fit / Not Fit to Continue decision for a Minor trip incident.
      if (i && i.severity === 'Minor' && !['Repaired - Cleared to Continue', 'Resolved'].includes(i.status)) acts.push(['clearance', 'Vehicle clearance', j.verified_at ? 'primary' : 'outline-primary', 'bi-sign-stop']);
      // JOB_HELPER_MECHANICS_V1: assisting mechanics once a lead is assigned. Shown (disabled, with the reason) instead of
      // hidden when the database update is missing, so the custodian can see why it cannot be used.
      if (j.mechanic_id && MaintUI.ACTIVE.includes(j.status)) acts.push(r.helpersReady ? ['helpers', 'Add mechanics', 'outline-primary', 'bi-people']
        : ['helpers-off', 'Add mechanics (needs database update 12)', 'outline-secondary disabled', 'bi-people']);
      if (['Under Inspection', 'Under Repair'].includes(j.status)) acts.push(['mech', 'Change lead mechanic', 'outline-secondary', 'bi-arrow-left-right'],
        ['cost', 'Add cost', 'outline-secondary', 'bi-cash'], ['parts', 'Request parts', 'outline-secondary', 'bi-box-seam']);
      if (j.source !== 'Incident' && ['Reported', 'Under Inspection', 'Under Repair'].includes(j.status)) acts.push(['cancel', 'Cancel record', 'outline-danger', '']);
    }
    const partsCost = r.parts.reduce((a, p) => a + (+p.released_qty) * (+p.unit_cost || 0), 0), other = r.costs.reduce((a, c) => a + +c.amount, 0);
    const truckLine = `<b>${UI.esc(j.plate_number)}</b>${j.truck_number ? ' · #' + UI.esc(j.truck_number) : ''} · ${UI.esc(j.truck_type)} · ${UI.badge(j.truck_status)}`;
    const progress = UI.workflow(MaintUI.STAGES.map((label,index)=>{
      const status=index===4?'Completed':label,event=(r.events||[]).find(e=>e.to_status===status);
      const at=index===4?j.verified_at:event?.created_at;
      const recorded=index===4?!!j.verified_at:!!event;
      return {label,at,state:j.status==='Cancelled'&&!recorded?'exception':index<4&&j.status===label&&!j.verified_at?'current':recorded?'complete':'pending'};
    }),{status:j.verified_at?'Returned to service':j.status,label:'Recorded corrective repair progress'});
    const readiness=UI.readiness([
      {label:'Inspection findings and diagnosis',state:j.findings&&j.diagnosis?'complete':'unknown'},
      {label:'Corrective repair recorded',state:j.repair_performed&&j.completed_on?'complete':'unknown'},
      {label:'Return-to-service verification',state:j.verified_at?'complete':'unknown',detail:j.verified_at?'Recorded verification':'The existing verification action remains required before return to service.'}
    ],{title:'Return-to-service record'});
    const html = `${progress}${readiness}
      <div class="next-step ${j.verified_at ? 'done' : ''}"><i class="bi ${j.verified_at ? 'bi-check-circle' : 'bi-arrow-right-circle'}"></i> ${UI.esc(MaintUI.nextStep(j))}</div>
      ${acts.length ? `<div class="drawer-actions">${acts.map(([a, l, c, ic]) => `<button class="btn btn-sm btn-${c}" data-a="${a}">${ic ? `<i class="bi ${ic}"></i> ` : ''}${UI.esc(l)}</button>`).join('')}</div>` : ''}
      ${UI.h('Problem')}${UI.kv([['Truck', truckLine], ['Source', j.source === 'Incident' ? 'Trip incident' + (j.trip_ref ? ' · ' + UI.esc(j.trip_ref) : '') : (r.arrival ? 'Arrival checklist · ' + UI.esc(j.trip_ref || '') + ' · ' + UI.esc(r.arrival.actor) + ' · ' + UI.datetime(r.arrival.completed_at) : 'Reported at the garage')],
        r.arrival && r.arrival.damage_notes ? ['Damage / missing (arrival)', UI.esc(r.arrival.damage_notes)] : null,
        ['Problem', UI.e(j.problem)], ['Date reported', UI.date(j.reported_on) + (j.reported_by_name ? ' · ' + UI.esc(j.reported_by_name) : '')],
        ])}
      ${UI.h('Mechanics and repair')}${UI.kv([['Lead mechanic', j.mechanic ? `${UI.esc(j.mechanic)} · ${UI.esc(j.mechanic_code)}${j.mechanic_contact ? ' · ' + UI.esc(j.mechanic_contact) : ''}` : 'Not assigned'],
        ['Assisting mechanics', r.helpersReady ? MaintUI.helperList(r.helpers, act && MaintUI.ACTIVE.includes(j.status))
          : '<span class="text-muted">Not available until database update 2026-11-12_12 is applied.</span>'],
        ['Inspection findings', UI.e(j.findings)], ['Diagnosis', UI.e(j.diagnosis)],
        ['Corrective action', j.status === 'Completed' && !j.repair_performed ? '<span class="text-muted">Inspection only — no repair performed</span>' : UI.e(j.repair_performed)], ['Repair completion date', j.completed_on ? UI.date(j.completed_on) + ' · ' + UI.e(j.completed_by_name) : '—'],
        ['Verification', j.verified_at ? `Passed · ${UI.e(j.verified_by_name)} · ${UI.datetime(j.verified_at)}<div class="small text-muted">${UI.e(j.verification_notes)}</div>` : (j.status === 'Completed' ? '<span class="badge-s amber">Waiting for verification</span>' : '—')],
        ['Remarks', UI.e(j.remarks)]])}
      ${i ? UI.h('Trip incident', `<button class="btn btn-link btn-sm p-0" data-a="printinc"><i class="bi bi-printer"></i> Incident report form</button>`) + UI.kv([['Reference', UI.esc(i.incident_ref) + ' ' + UI.badge(i.status)], ['Type', UI.e(i.incident_type)],
        ['Incident date and time / location', UI.datetime(i.incident_at) + ' · ' + UI.esc(i.location)], ['Details', UI.e(i.details)], ['Persons involved', UI.e(i.persons_involved)], ['Reported by', UI.e(i.reported_by_name)], ['Recommendation', UI.e(i.recommendation)],
        ['Damage classification', i.severity ? UI.esc(i.severity) + (i.assessed_by_name ? ' · ' + UI.esc(i.assessed_by_name) + ' · ' + UI.datetime(i.assessed_at) : '') : '<span class="text-muted">Not assessed yet</span>'],
        ['Vehicle clearance', i.clearance ? `${UI.badge(i.clearance)} · ${UI.e(i.cleared_by_name)} · ${UI.datetime(i.cleared_at)}<div class="small text-muted">${UI.e(i.clearance_findings)}</div>` : '<span class="text-muted">Not recorded</span>'],
        ['Trip resumed', i.resumed_at ? UI.e(i.resumed_by_name) + ' · ' + UI.datetime(i.resumed_at) : '—']]) : ''}
      ${UI.h('Parts and costs', UI.canGo('mp_parts_requests') ? `<button type="button" class="btn btn-link btn-sm p-0" data-partspage="${+j.vehicle_id}"><i class="bi bi-box-seam"></i> Open in Parts Requests</button>` : '')}
      ${r.parts.length ? UI.table([['Request', 'request_ref'], ['Part', 'part_number'], ['Description', 'description'], ['Released', 'released_qty', 'num'], ['Unit cost', 'unit_cost', 'money']], r.parts, { plain: true }) : '<div class="text-muted small mb-2">No parts requested.</div>'}
      ${r.costs.length ? UI.table([['Type', 'cost_type'], ['Description', 'description'], ['Provider', 'provider'], ['Date', 'incurred_on', 'date'], ['Amount', 'amount', 'money']], r.costs, { plain: true }) : '<div class="text-muted small mb-2">No labor or outside costs.</div>'}
      <div class="text-end small">Parts ${UI.money(partsCost)} + other ${UI.money(other)} = <b>${UI.money(partsCost + other)}</b></div>
      ${UI.h('Photos and documents')}${UI.docs(r.documents)}
      ${UI.h('Status history')}${UI.activity(r.events)}
      ${UI.h('Maintenance history of this truck')}${UI.table([['Record', 'job_ref'], ['Source', 'source'], ['Problem', 'problem'], ['Mechanic', 'mechanic'], ['Status', 'status', 'badge'], ['Date reported', 'reported_on', 'date'], ['Completed', 'completed_on', 'date']], r.history, { plain: true })}`;
    const el = UI.drawer(UI.esc(j.job_ref) + ' · ' + UI.esc(j.plate_number), html, { subtitle: 'Corrective maintenance · ' + UI.esc(j.verified_at ? 'Returned to service' : j.status) });
    UI.drawerSections(el,[['overview','Overview',['Problem','Trip incident']],['repair','Inspection & Repairs',['Mechanics and repair']],['parts','Parts & Costs',['Parts and costs']],['docs','Documents',['Photos and documents']],['history','Status History',['Status history','Maintenance history of this truck']]],{key:'maintenance-details'});
    el.querySelector('[data-partspage]')?.addEventListener('click', (e) => { UI.closeDrawer(); App.go('mp_parts_requests', { vehicle_id: e.currentTarget.dataset.partspage }); });
    const reopen = () => { MaintUI.open(j.id); App.route(); };
    el.querySelectorAll('[data-rmh]').forEach(b => b.onclick = () => UI.confirmAction('Remove ' + UI.esc(b.dataset.name) + ' from ' + UI.esc(j.job_ref),
      'The mechanic becomes available for other repairs. The assignment stays in the record history.', 'Maintenance.helper_remove', { job_id: j.id, mechanic_id: b.dataset.rmh },
      { danger: true, submit: 'Remove mechanic', fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }], after: reopen }));
    const autoBtn = autoAct && el.querySelector(`[data-a="${autoAct}"]`);
    el.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
      const a = b.dataset.a;
      if (a === 'helpers') return MaintUI.addHelpers(j, r.helpers, reopen);
      const mech = MaintUI.mechanicOptions(j.mechanic_id);
      if (a === 'assess') UI.formModal('Repair plan for ' + UI.esc(i.incident_ref), [
          { name: 'severity', label: 'Repair plan', type: 'select', required: true, col: 12, options: [['Minor', 'Repair at the incident location; continue the trip after clearance'], ['Major', 'Return the truck for repair; reschedule the trip']] },
          { name: 'mechanic_id', label: 'Mechanic sent / assigned', type: 'select', required: true, col: 6, options: mech, placeholder: mech.length ? 'Choose…' : 'No mechanic is available' },
          { name: 'recommendation', label: 'Repair recommendation', type: 'textarea', required: true, col: 12 }],
        'Maintenance.assess', { extra: { job_id: j.id }, intro: `<b>${UI.esc(i.problem)}</b> at ${UI.esc(i.location)}.<br>Select the actual repair approach: repair at the incident location and continue after clearance, or return the truck for repair and reschedule the trip.`, after: reopen });
      if (a === 'mech') UI.formModal((j.mechanic_id ? 'Change mechanic for ' : 'Assign mechanic to ') + UI.esc(j.job_ref), [
          { name: 'mechanic_id', label: 'Mechanic', type: 'select', required: true, col: 12, options: mech.filter(o => +o[0] !== +j.mechanic_id), placeholder: mech.length ? 'Choose…' : 'No mechanic is available' },
          ...(j.mechanic_id ? [{ name: 'reason', label: 'Reason for the change', required: true, col: 12 }] : [])],
        'Maintenance.assign_mechanic', { extra: { job_id: j.id }, size: '', intro: 'Only Available mechanics are listed. A mechanic can work on one repair at a time.', after: reopen });
      if (a === 'inspect') UI.formModalFiles('Inspection findings for ' + UI.esc(j.job_ref), [
          { name: 'findings', label: 'Inspection findings', type: 'textarea', required: true, col: 12, placeholder: 'What the mechanic found when checking the truck' },
          { name: 'diagnosis', label: 'Diagnosis', type: 'textarea', required: true, col: 12, placeholder: 'Cause of the problem and what must be repaired or replaced' },
          { name: 'photos', label: 'More photos (optional)', type: 'file', multiple: true, col: 6, attrs: 'accept="image/png,image/jpeg"' }],
        'Maintenance.inspect', { extra: { job_id: j.id }, submit: 'Save and start repair', after: reopen });
      if (a === 'complete') UI.formModalFiles('Complete repair ' + UI.esc(j.job_ref), [
          { name: 'repair_performed', label: 'Corrective action performed', type: 'textarea', required: true, col: 12 },
          { name: 'completed_on', label: 'Completion date', type: 'date', value: UI.today(), required: true, col: 6 }, { name: 'remarks', label: 'Remarks', col: 6 },
          { name: 'evidence', label: 'Evidence of the repair (photos or documents, required)', type: 'file', multiple: true, required: true, col: 12, attrs: 'accept="application/pdf,image/png,image/jpeg"' }],
        'Maintenance.complete', { extra: { job_id: j.id }, submit: 'Complete repair', intro: 'Add parts and labor costs before completing. The mechanic becomes available again. The truck stays out of service until you verify it.', after: reopen });
      if (a === 'clearance') UI.formModalFiles('Vehicle clearance · ' + UI.esc(i.incident_ref), [
          { name: 'decision', label: 'Decision', type: 'select', required: true, col: 12, placeholder: false, options: [['Fit to Continue', 'Fit to Continue: the truck may continue this trip'], ['Not Fit to Continue', 'Not Fit to Continue: the trip stays On Hold']] },
          { name: 'findings', label: 'Findings (what was checked and the truck\'s actual condition)', type: 'textarea', required: true, col: 12 },
          { name: 'inspection_only', label: 'Inspection only: no repair was needed (Fit to Continue; no parts, costs or labor recorded)', type: 'checkbox', col: 12 },
          { name: 'evidence', label: 'Supporting evidence (optional)', type: 'file', multiple: true, col: 12, attrs: 'accept="application/pdf,image/png,image/jpeg"' }],
        'Maintenance.clearance', { extra: { job_id: j.id }, submit: 'Record clearance', after: reopen,
          intro: `Damage classification: <b>${UI.esc(i.severity)}</b>. Fit to Continue needs the on-site repair record completed and verified (${UI.esc(j.job_ref)}); the dispatcher then resumes the trip. Not Fit to Continue keeps the trip On Hold.` });
      if (a === 'verify') {
        const wrap = UI.formModal('Verify & return to service · ' + UI.esc(j.plate_number), [
          { name: 'result', label: 'Operational check result', type: 'select', required: true, col: 12, placeholder: false, options: [['Passed', 'Passed: the truck is safe and operational'], ['Failed', 'Failed: the problem is not fixed']] },
          { name: 'verification_notes', label: 'What was checked (road test, engine, brakes, lights…)', type: 'textarea', required: true, col: 12 },
          { name: 'mechanic_id', label: 'Mechanic to continue the repair (if failed)', type: 'select', col: 12, options: MaintUI.mechanicOptions(j.mechanic_id), value: j.mechanic_id, placeholder: 'Same mechanic' }],
          'Maintenance.verify', { extra: { job_id: j.id }, size: '', submit: 'Save verification', after: reopen,
            intro: 'Passed returns the truck to service (Available, or Assigned if a trip is waiting). Failed sends the record back to Under Repair.' });
        const res = wrap.querySelector('[name=result]'), mc = wrap.querySelector('[name=mechanic_id]').closest('[class*=col-]');
        const t = () => { mc.style.display = res.value === 'Failed' ? '' : 'none'; }; res.onchange = t; t();
      }
      if (a === 'cost') UI.formModal('Repair cost for ' + UI.esc(j.job_ref), [
          { name: 'cost_type', label: 'Type', type: 'select', options: UI.list(d.costTypes), required: true, col: 6 }, { name: 'amount', label: 'Amount (₱)', type: 'money', required: true, col: 6 },
          { name: 'description', label: 'Description', required: true, col: 12 }, { name: 'provider', label: 'Supplier / service provider', col: 6 }, { name: 'incurred_on', label: 'Date', type: 'date', value: UI.today(), required: true, col: 6 }],
        'Maintenance.add_cost', { extra: { job_id: j.id }, after: () => MaintUI.open(j.id) });
      if (a === 'parts') { UI.closeDrawer(); MaintUI.requestParts(j.vehicle_id, j.id); }
      if (a === 'cancel') UI.confirmAction('Cancel ' + UI.esc(j.job_ref), 'Use this only for a report made by mistake. The truck becomes available again if nothing else blocks it.', 'Maintenance.cancel', { job_id: j.id },
        { danger: true, submit: 'Cancel record', fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
      if (a === 'printinc') TripUI.printIncident({ ...i }, { trip_ref: j.trip_ref, plate_number: j.plate_number });
    });
    if (autoBtn) autoBtn.click();
  },
  /** WS_DESIGN_V1: the one next workflow step offered directly on a Repair Management row (Motorpool only). */
  rowStep(j) {
    if (!MaintUI.data?.can?.act || j.status === 'Cancelled' || j.verified_at) return null;
    if (j.status === 'Reported') return j.source === 'Incident' ? ['assess', 'Select repair plan', 'bi-clipboard-pulse'] : ['mech', 'Assign mechanic', 'bi-person-plus'];
    if (j.status === 'Under Inspection') return ['inspect', 'Record findings', 'bi-search'];
    if (j.status === 'Under Repair') return ['complete', 'Complete repair', 'bi-check2-circle'];
    if (j.status === 'Completed') return ['verify', 'Verify & return to service', 'bi-shield-check'];
    return null;
  },
};


/* Head Management: read-only monitoring UI; Motorpool retains all repair actions. */
const HeadMaintenanceUI = {
  categories: [
    {id:'open',label:'Open records',empty:'No open maintenance records.'},
    {id:'completed',label:'Completed repairs',empty:'No completed repairs recorded.'},
    {id:'all',label:'All records',empty:'No maintenance records available.'}
  ],
  columns: [
    ['Record / Truck','job_ref',(value,row)=>'<b>'+UI.esc(value||'—')+'</b><div class="cell-sub">'+UI.esc(row.plate_number||'—')+'</div>'],
    ['Problem','problem',value=>UI.esc(value||'—')],
    ['Mechanic','mechanic',value=>UI.esc(value||'Not assigned')],
    ['Status','display_status','badge'],
    ['Reported','reported_on','date'],
    ['Completed','completed_on','date']
  ],
  exportRows(rows) {
    const cols=[['Record','job_ref'],['Truck','plate_number'],['Type','truck_type'],
      ['Problem','problem'],['Mechanic','mechanic'],['Status','display_status'],
      ['Reported','reported_on'],['Completed','completed_on']];
    const quote=value=>'"'+String(value??'').replace(/"/g,'""').replace(/^[\s\uFEFF]*[=+\-@\t\r]/,match=>"'"+match)+'"';
    const csv='\uFEFF'+[cols.map(([label])=>quote(label)).join(','),
      ...rows.map(row=>cols.map(([,key])=>quote(row[key])).join(','))].join('\r\n');
    const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    const link=document.createElement('a');link.href=url;
    link.download='mulawin_maintenance_monitoring_'+new Date().toISOString().slice(0,10)+'.csv';
    document.body.append(link);link.click();link.remove();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  },
  render(ctx,data,jobs) {
    const open=jobs.filter(job=>job.status!=='Cancelled'&&!job.verified_at);
    const completed=jobs.filter(job=>Boolean(job.verified_at));
    const dataByCategory={open,completed,all:jobs};
    const metrics=[
      ['Reported',open.filter(job=>job.status==='Reported').length,'bi-file-earmark-text','blue','open'],
      ['Under inspection',open.filter(job=>job.status==='Under Inspection').length,'bi-search','green','open'],
      ['Under repair',open.filter(job=>job.status==='Under Repair').length,'bi-tools','amber','open'],
      ['Awaiting verification',open.filter(job=>job.status==='Completed').length,'bi-calendar-check','purple','open']
    ];
    ctx.el.innerHTML='<section class="head-maintenance-workspace">'
      +'<div class="head-trip-intro"><span><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> Company-wide maintenance overview</span>'
      +'<span class="small text-muted"><i class="bi bi-eye" aria-hidden="true"></i> Read-only monitoring</span></div>'
      +'<div class="head-trip-kpis">'+metrics.map(([label,value,icon,tone,tab])=>
        '<button type="button" class="head-kpi head-fleet-kpi '+tone+'" data-head-maintenance-kpi="'+tab+'">'
        +'<span class="head-kpi-icon"><i class="bi '+icon+'" aria-hidden="true"></i></span>'
        +'<span class="head-fleet-kpi-value"><span>'+UI.esc(label)+'</span><strong>'+value+'</strong></span></button>').join('')+'</div>'
      +'<div data-head-maintenance-workspace></div></section>';
    const root=ctx.el.querySelector('[data-head-maintenance-workspace]');
    const state={category:'open',status:'',sort:'default'};
    const renderCategory=()=>{
      const category=this.categories.find(c=>c.id===state.category)||this.categories[0];
      const rows=dataByCategory[category.id];
      const statuses=[...new Set(rows.map(job=>job.display_status).filter(Boolean))].sort();
      const filterId='head-maintenance-filter',sortId='head-maintenance-sort';
      const toolbar='<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger" aria-controls="'+filterId+'" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>'
        +'<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger" aria-controls="'+sortId+'" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>'
        +'<div class="record-workspace-popovers">'
        +'<div class="record-workspace-popover" id="'+filterId+'" hidden><strong>Filter maintenance records</strong>'
        +'<label>Status<select class="form-select form-select-sm" data-head-maintenance-status>'
        +'<option value="">All statuses</option>'+statuses.map(status=>'<option value="'+UI.esc(status)+'">'+UI.esc(status)+'</option>').join('')
        +'</select></label><div class="record-workspace-popover-actions">'
        +'<button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button>'
        +'<button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button></div></div>'
        +'<div class="record-workspace-popover record-workspace-sort-panel" id="'+sortId+'" hidden>'
        +'<strong>Sort maintenance records</strong>'
        +'<label class="record-workspace-sort-option"><input type="radio" name="head_maintenance_sort" value="default" data-record-sort checked> Default order</label>'
        +'<label class="record-workspace-sort-option"><input type="radio" name="head_maintenance_sort" value="asc" data-record-sort> Record A–Z</label>'
        +'<label class="record-workspace-sort-option"><input type="radio" name="head_maintenance_sort" value="desc" data-record-sort> Record Z–A</label>'
        +'</div></div>'
        +'<button type="button" class="btn btn-sm btn-outline-success record-workspace-add" data-head-maintenance-export><i class="bi bi-download" aria-hidden="true"></i> Export</button>';
      const table=UI.table(this.columns,rows,{
        tools:toolbar,showAllColumns:true,viewSwitch:false,empty:category.empty,
        actions:()=>UI.btn('view','View details','outline-primary'),
        onAction:(_,job)=>MaintUI.open(job.id),
        rowClick:job=>MaintUI.open(job.id)
      });
      root.innerHTML=RecordComponents.panel(table,RecordComponents.categories(
        this.categories.map(item=>({value:item.id,label:item.label})),category.id,'data-head-maintenance-category'));
      UI.activate(root);
      RecordComponents.mountPanel(root,{title:'Maintenance Records',addSelector:'.record-workspace-add'});
      const tbody=root.querySelector('[data-tbody]'),id=tbody?.dataset.tbody;
      const tableState=id?UI._tables[id]:null;
      if(!tableState)return;
      const statusSelect=root.querySelector('[data-head-maintenance-status]');
      const search=root.querySelector('[data-tsearch]');
      const original=rows.slice();
      let visible=rows.slice();
      statusSelect.value=state.status;
      const selectedSort=root.querySelector('[data-record-sort][value="'+state.sort+'"]');
      if(selectedSort)selectedSort.checked=true;
      const draw=()=>{
        const term=(search?.value||'').trim().toLocaleLowerCase();
        visible=original.filter(job=>(!state.status||job.display_status===state.status)
          &&(!term||['job_ref','plate_number','truck_type','problem','mechanic','display_status','reported_on','completed_on']
            .some(key=>String(job[key]??'').toLocaleLowerCase().includes(term))));
        if(state.sort!=='default')visible.sort((a,b)=>(state.sort==='asc'?1:-1)
          *String(a.job_ref??'').localeCompare(String(b.job_ref??''),undefined,{numeric:true}));
        tableState.rows=visible;
        tableState.filter='';
        tableState.page=0;
        UI.drawTable(id);
        // Only the Head Management view needs headers and pager for an empty table.
        if(typeof HeadOversight!=='undefined')HeadOversight.finishTable(root);
      };
      search?.addEventListener('input',draw);
      RecordComponents.bindDropdowns(root,{
        apply:panel=>{
          state.status=panel.querySelector('[data-head-maintenance-status]').value;
          draw();
          root.querySelector('.record-workspace-filter-trigger')?.click();
        },
        reset:()=>{
          state.status='';
          statusSelect.value='';
          draw();
          root.querySelector('.record-workspace-filter-trigger')?.click();
        },
        sort:value=>{state.sort=value;draw();}
      },{filter:filterId,sort:sortId});
      root.querySelectorAll('[data-head-maintenance-category]').forEach(button=>button.addEventListener('click',()=>{
        if(state.category===button.dataset.headMaintenanceCategory)return;
        state.category=button.dataset.headMaintenanceCategory;
        state.status='';
        state.sort='default';
        renderCategory();
      }));
      root.querySelector('[data-head-maintenance-export]')?.addEventListener('click',()=>this.exportRows(visible));
      draw();
    };
    ctx.el.querySelectorAll('[data-head-maintenance-kpi]').forEach(button=>button.addEventListener('click',()=>{
      state.category=button.dataset.headMaintenanceKpi;
      state.status='';
      state.sort='default';
      renderCategory();
    }));
    renderCategory();
    if(Object.keys(data._errors||{}).length)
      UI.toast('Some maintenance records could not be loaded. Check server logs.','error');
  }
};

App.page('maintenance', {
  title: 'Repair Management',
  subtitle: 'Track repairs, assign mechanics, and verify completed work.',
  render(ctx) {
    const d = MaintUI.data = ctx.data;
    if (App.me?.user?.role === 'admin') {
      const jobs=(d.jobs||[]).map(j=>({...j,display_status:j.verified_at?'Returned to service':j.status==='Completed'?'Awaiting verification':j.status}));
      HeadMaintenanceUI.render(ctx,d,jobs);
      return;
    }
    // MOTORPOOL_WORKSPACE_PHASE1: the former "Parts requests" tab is now its own page. Old links / remembered tab go there.
    try {
      if (d.can.act && sessionStorage.getItem('tab:maintenance') === 'parts') {
        sessionStorage.removeItem('tab:maintenance');
        if (UI.canGo('mp_parts_requests')) return App.go('mp_parts_requests');
      }
    } catch { /* storage unavailable: stay on Repair Management */ }
    if (d.can.act) UI.tool(ctx, 'Report truck problem', () => MaintUI.report(), 'bi-plus-lg', 'primary');
    if (d.can.act) UI.tool(ctx, 'Request parts', () => MaintUI.requestParts(), 'bi-box-seam');
    if (d.can.act && UI.canGo('mp_parts_requests')) UI.tool(ctx, 'Parts requests', () => App.go('mp_parts_requests'), 'bi-list-check');
    const q = ctx.query;
    const jobs = d.jobs.map(j => ({ ...j, display_status: j.verified_at ? 'Returned to service' : (j.status === 'Completed' ? 'Awaiting verification' : j.status) }));
    // STAGE_A_MOTORPOOL_UI: filters (truck, source, mechanic, stage). Field names = URL keys, so a filter survives a reload and can be linked.
    const view = MaintUI.VIEWS[q.view];
    // MOTORPOOL_REPORTS_V2: ?from= / ?to= (date reported) so report drill-downs open exactly the period's repairs.
    const match = (j) => (!q.vehicle_id || String(j.vehicle_id) === String(q.vehicle_id)) && (!q.source || j.source === q.source)
      && (!q.mechanic_id || String(j.mechanic_id) === String(q.mechanic_id))
      && (!q.from || String(j.reported_on).slice(0, 10) >= q.from) && (!q.to || String(j.reported_on).slice(0, 10) <= q.to)
      && (q.cancelled !== '0' || j.status !== 'Cancelled');   // ?cancelled=0: same population as the report counts
    const shownJobs = jobs.filter(match);
    const open = shownJobs.filter(j => !['Cancelled'].includes(j.status) && !j.verified_at);
    const by = (s) => open.filter(j => j.status === s);
    const reported = by('Reported'), inspection = by('Under Inspection'), repair = by('Under Repair'), verify = by('Completed');
    const done = shownJobs.filter(j => j.verified_at);
    const trucks = (d.trucks || []).map(v => [v.id, v.plate_number + (v.truck_number ? ' #' + v.truck_number : '')]);
    const mechs = (d.mechanics || []).map(m => [m.id, m.full_name + ' · ' + m.mechanic_code]);
    // UI_REFERENCE_V1: the stage is chosen with status tabs (?view=); the filter modal keeps truck, mechanic and source.
    const filters = [
      { name: 'vehicle_id', label: 'Truck', type: 'select', col: 6, options: trucks },
      { name: 'mechanic_id', label: 'Mechanic', type: 'select', col: 6, options: mechs },
      { name: 'source', label: 'Source', type: 'select', col: 12, options: [['On-Premise', 'Garage report / arrival checklist'], ['Incident', 'Trip incident']] },
      { name: 'from', label: 'Reported from', type: 'date', col: 6 }, { name: 'to', label: 'Reported to', type: 'date', col: 6 },
    ];
    const active = ['vehicle_id', 'mechanic_id', 'source', 'from', 'to', 'cancelled'].filter(k => q[k]);
    const TABS = [['', 'Open', open.length], ['reported', 'New reports', reported.length], ['inspection', 'Under inspection', inspection.length],
      ['repair', 'Under repair', repair.length], ['verify', 'Awaiting verification', verify.length], ['done', 'Completed', done.length], ['all', 'All records', shownJobs.length]];
    if (q.view === 'in_progress') TABS.splice(1, 0, ['in_progress', 'Inspection or repair', inspection.length + repair.length]);
    const tab = TABS.some(t => t[0] === (q.view || '')) ? (q.view || '') : '';
    ctx.el.innerHTML = UI.statusTabs('maintenance', q, 'view', TABS, tab) + UI.filterModal(ctx, filters, { hint: 'All trucks, mechanics and sources.' });
    const waited = (j) => { const days = Math.max(0, Math.floor((Date.now() - new Date(String(j.reported_on) + 'T00:00:00').getTime()) / 86400000));
      return days === 0 ? '<span class="text-muted">today</span>' : `<span class="${days >= 7 ? 'text-danger fw-semibold' : ''}">${days} day${days === 1 ? '' : 's'}</span>`; };
    const cols = [['Record', 'job_ref', (v, j) => `<b>${UI.esc(v)}</b><div class="cell-sub">${j.source === 'Incident' ? 'Incident ' + UI.esc(j.incident_ref || '') : (j.trip_ref ? 'Arrival checklist ' + UI.esc(j.trip_ref) : 'Garage report')}</div>`],
      ['Truck', 'plate_number', (v, j) => `${UI.esc(v)}<div class="cell-sub">${UI.esc(j.truck_type)}</div>`],
      ['Problem', 'problem', (v) => `<span class="cell-clip">${UI.esc(v)}</span>`],
      ['Mechanic', 'mechanic', (v, j) => { const n = (d.helpers || []).filter(h => +h.job_id === +j.id).length; return v ? UI.esc(v) + (n ? ` <span class="badge-s blue" title="${n} assisting mechanic(s)">+${n}</span>` : '') : '<span class="text-muted">Not assigned</span>'; }],
      ['Status', 'display_status', 'badge'], ['Date reported', 'reported_on', 'date']];
    // Open records: "Waiting" (days since reported) instead of the date column; all six columns stay visible.
    const openCols = [...cols.slice(0, 5), ['Waiting', 'reported_on', (v, j) => waited(j)]];
    // WS_DESIGN_V1: direct row actions: the next workflow step, Request parts while the repair is active, and Open.
    const opt = { directActions: true, showAllColumns: true,
      actions: (j) => { const st = MaintUI.rowStep(j);
        return (st ? UI.ibtn('step:' + st[0], st[1], st[2], 'outline-primary') : '')
          + (d.can.act && d.helpersReady && j.mechanic_id && MaintUI.ACTIVE.includes(j.status) ? UI.ibtn('helpers', 'Add mechanics', 'bi-people') : '')
          + (d.can.act && ['Under Inspection', 'Under Repair'].includes(j.status) ? UI.ibtn('parts', 'Request parts', 'bi-box-seam') : '')
          + UI.ibtn('view', 'Open record', 'bi-eye'); },
      onAction: (a, j) => a === 'parts' ? MaintUI.requestParts(j.vehicle_id, j.id) : MaintUI.open(j.id, a === 'helpers' ? 'helpers' : a.startsWith('step:') ? a.slice(5) : undefined),
      rowClick: (j) => MaintUI.open(j.id) };
    const box = document.createElement('div'); ctx.el.append(box);
    const label = TABS.find(t => t[0] === tab)[1];
    if (tab === 'done') box.innerHTML = UI.table([...cols.slice(0, 4), ['Completion date', 'completed_on', 'date'], ['Returned to service', 'verified_at', 'datetime']], done, { ...opt, title: label + ' (' + done.length + ')', empty: 'No completed repairs yet.' });
    else if (tab === 'all') box.innerHTML = UI.table(cols, shownJobs, { ...opt, title: label + ' (' + shownJobs.length + ')', empty: 'No record matches these filters.' });
    else { const rows = view ? open.filter(view[1]) : open;
      box.innerHTML = UI.table(openCols, rows, { ...opt, title: label + ' (' + rows.length + ')', empty: active.length || view ? 'No open record matches.' : 'No truck has an open repair. Report a problem when a truck needs inspection or repair.' }); }
    const once = UI.takeQuery(ctx, 'report', 'open');
    if (once.report) MaintUI.report(once.report === 'new' ? undefined : once.report);
    if (once.open) MaintUI.open(once.open);
  },
});

/* MOTORPOOL_WORKSPACE_PHASE1 — Parts Requests (Motorpool Custodian).
   Data: Purchasing::read('mp_parts_requests') — the department's requests, status counts, and what the
   Request parts form needs (trucks, open repairs). No costs, stock counts, budget, supplier or purchase-order data.
   Actions (existing, server-checked): Purchasing.request_create (form), Purchasing.request_details (Details),
   Purchasing.request_cancel (only while Requested). Releasing stock stays with the Purchasing Officer.
   URL filter: ?status=<key> with key = open | requested | partially_released | not_available | released | cancelled,
   ?vehicle_id=<truck id>. Every card, link and the filter modal use these same keys. */
const MpParts = {
  /** URL key -> database status. 'open' = still waiting for parts. */
  STATUS: { requested: 'Requested', partially_released: 'Partially Released', not_available: 'Not Available', released: 'Released', cancelled: 'Cancelled' },
  OPEN: ['Requested', 'Partially Released', 'Not Available'],
  match(r, key) {
    if (!key) return true;
    return key === 'open' ? MpParts.OPEN.includes(r.status) : r.status === MpParts.STATUS[key];
  },
  nextStep(r) {
    return {
      'Requested': 'Waiting for the Purchasing Officer to check the stock and release.',
      'Partially Released': 'Part of the items were released; the rest waits for stock.',
      'Not Available': 'No stock. Follow up with the Purchasing Officer.',
      'Released': 'All items released.',
      'Cancelled': 'Cancelled; it will not be processed.',
    }[r.status] || '';
  },
};

App.page('mp_parts_requests', {
  title: 'Parts Requests',
  subtitle: 'Request parts for repairs and track their availability.',
  render(ctx) {
    const d = MaintUI.data = ctx.data, q = ctx.query, can = d.can || {};
    if (can.act) UI.tool(ctx, 'Request parts', () => MaintUI.requestParts(), 'bi-plus-lg', 'primary');
    const trucks = Array.isArray(d.trucks) ? d.trucks : [];
    const rows = (Array.isArray(d.requests) ? d.requests : []).map(r => ({ ...r,
      progress: `${UI.num(+r.released_units)} of ${UI.num(+r.requested_units)} released`, next: MpParts.nextStep(r) }));
    // UI_REFERENCE_V1: status tabs (?status=) + truck filter; no KPI row on the list page (the tabs carry the counts).
    // Keys: '' / 'open' = still waiting for parts, 'all', or one MpParts.STATUS key.
    const key = q.status === 'all' || MpParts.STATUS[q.status] ? q.status : 'open';
    const truck = q.vehicle_id ? String(q.vehicle_id) : '';
    // MOTORPOOL_REPORTS_V2: ?from= / ?to= (request date) for drill-downs from Maintenance Reports.
    const byTruck = rows.filter(r => (!truck || String(r.vehicle_id) === truck)
      && (!q.from || String(r.created_at).slice(0, 10) >= q.from) && (!q.to || String(r.created_at).slice(0, 10) <= q.to));
    const inTab = (k) => byTruck.filter(r => k === 'all' || MpParts.match(r, k));
    const shown = inTab(key);
    const TABS = [['open', 'Open'], ...Object.entries(MpParts.STATUS).map(([k, v]) => [k, v]), ['all', 'All']].map(([k, l]) => [k, l, inTab(k).length]);
    const truckOptions = trucks.map(v => [v.id, v.plate_number + (v.truck_number ? ' #' + v.truck_number : '')]);
    ctx.el.innerHTML = UI.statusTabs('mp_parts_requests', { ...q, status: key }, 'status', TABS, key)
      + UI.filterModal(ctx, [{ name: 'vehicle_id', label: 'Truck', type: 'select', col: 12, options: truckOptions },
        { name: 'from', label: 'Requested from', type: 'date', col: 6 }, { name: 'to', label: 'Requested to', type: 'date', col: 6 }], { hint: 'All trucks and dates.' });

    const cols = [
      ['Request', 'request_ref', (v, r) => `<b>${UI.esc(v)}</b><div class="cell-sub">${UI.date(r.created_at)}</div>`],
      ['Truck / repair', 'plate_number', (v, r) => `${UI.esc(v)}${r.job_ref ? `<div class="cell-sub">${UI.esc(r.job_ref)}</div>` : ''}`],
      ['Items', 'items', (v) => `<span class="cell-clip">${UI.esc(v || '')}</span>`],
      ['Progress', 'progress'],
      ['Status', 'status', 'badge'],
      ['Next step', 'next', (v) => `<span class="cell-clamp">${UI.esc(v)}</span>`],
    ];
    const cancel = (r) => UI.confirmAction('Cancel ' + UI.esc(r.request_ref), 'The request will not be processed by Purchasing.',
      'Purchasing.request_cancel', { request_id: r.id }, { danger: true, submit: 'Cancel request' });
    const opt = { directActions: true, showAllColumns: true,
      actions: (r) => (r.status === 'Requested' && can.act ? UI.ibtn('cancel', 'Cancel request', 'bi-x-lg', 'outline-danger') : '') + UI.ibtn('view', 'Details', 'bi-eye'),
      onAction: (a, r) => (a === 'cancel' ? cancel(r) : MaintUI.openRequest(r)),
      rowClick: (r) => MaintUI.openRequest(r),
    };
    const box = document.createElement('div');
    ctx.el.append(box);
    box.innerHTML = UI.table(cols, shown, { ...opt, title: TABS.find(t => t[0] === key)[1] + ' (' + shown.length + ')',
      empty: rows.length ? 'No parts request in this list.' : 'No parts requests yet. Use Request parts on a repair record.' });
    const once = UI.takeQuery(ctx, 'open');
    if (once.open) {
      const r = rows.find(x => String(x.id) === String(once.open));
      if (r) MaintUI.openRequest(r);
    }
  },
});

/* MAINTENANCE_OVERVIEW_PHASE1_V1 — Maintenance Overview (landing page for the Motorpool Custodian).
   Read-only: every number and row comes from Maintenance::read('maintenance_overview').
   Actions reuse the existing screens: Report a truck problem (MaintUI.report), Repair Management
   (#maintenance, with ?view= filters and ?open=<id>), Parts requests (Repair Management > Parts requests; Parts Requests page for Admin / Audit). */
const MaintOverview = {
  ACTIONS: {
    assess: 'Assess the incident (Minor or Major)',
    assign: 'Assign a mechanic',
    verify: 'Verify and return to service',
    parts_follow_up: 'Parts not available: follow up with Purchasing',
  },
  statusOf(r) {
    if (r.kind === 'parts') return r.status;
    return r.status === 'Completed' ? 'Awaiting verification' : r.status;
  },
  truck(r) { return `<b>${UI.esc(r.plate_number)}</b>${r.truck_number ? ` <span class="text-muted">#${UI.esc(r.truck_number)}</span>` : ''}`; },
  attentionItem(r, can) {
    const ref = r.kind === 'parts' ? [r.request_ref, r.job_ref].filter(Boolean).join(' · ') : r.job_ref;
    const tag = r.source === 'Incident' && r.kind === 'job' ? `<span class="mo-tag">Trip incident${r.incident_ref ? ' ' + UI.esc(r.incident_ref) : ''}</span>` : '';
    const viewBtn = r.job_id
      ? `<button type="button" class="btn btn-sm btn-outline-primary" data-open-job="${+r.job_id}">View repair</button>`
      : (can.parts ? `<button type="button" class="btn btn-sm btn-outline-primary" data-go="${can.requestParts ? 'mp_parts_requests?status=not_available' : 'parts_requests'}">View request</button>` : '');
    return `<li class="mo-item">
      <div class="mo-item-main">
        <div class="mo-item-top">${MaintOverview.truck(r)} <span class="mo-ref">${UI.esc(ref)}</span> ${tag}</div>
        <div class="mo-item-issue" title="${UI.esc(r.issue)}">${UI.esc(r.issue)}</div>
        <div class="mo-item-next"><i class="bi bi-arrow-right-circle"></i> ${UI.esc(MaintOverview.ACTIONS[r.next_action] || 'Open the record')}
          <span class="mo-since">· waiting since ${UI.datetime(r.since)}</span></div>
      </div>
      <div class="mo-item-side">${UI.badge(MaintOverview.statusOf(r))}${viewBtn}</div></li>`;
  },
  activityItem(e) {
    const what = [e.job_ref || e.request_ref, e.plate_number].filter(Boolean).map(UI.esc).join(' · ');
    const extra = e.kind === 'parts_released' || e.kind === 'parts_requested' ? (e.units ? `${UI.num(+e.units)} unit(s)` : '') : (e.mechanic ? 'Mechanic: ' + UI.esc(e.mechanic) : '');
    const open = e.job_id ? ` data-open-job="${+e.job_id}" role="button" tabindex="0"` : '';
    return `<li class="mo-act${e.job_id ? ' clickable' : ''}"${open}>
      <div class="mo-act-label">${UI.esc(e.label)}</div>
      <div class="mo-act-what">${what}${extra ? ' · ' + extra : ''}</div>
      ${e.details && e.kind === 'job' ? `<div class="mo-act-details">${UI.esc(e.details)}</div>` : ''}
      <div class="mo-act-when">${UI.datetime(e.at)} · ${UI.esc(e.actor || 'System')}</div></li>`;
  },
};

App.page('maintenance_overview', {
  title: 'Maintenance Overview',
  subtitle: 'Monitor repairs, mechanics and trucks in service.',
  render(ctx) {
    const d = MaintUI.data = ctx.data, can = d.can || {};
    // REF_FIDELITY_V1: the Motorpool's own workspace title (other roles keep 'Maintenance Overview').
    if (UI.role('motorpool')) { const h = document.querySelector('#pageContent .page-title'); if (h) h.textContent = 'Motorpool Overview'; }
    const s = Array.isArray(d.summary) ? null : d.summary;   // a failed read section comes back as []

    const n = (k) => s ? +s[k] || 0 : '—';
    // STAGE_A_MOTORPOOL_UI: KPI tiles. Every value comes from Maintenance::read('maintenance_overview'); every tile opens a filtered, authorized page.
    const crew = d.crew && !Array.isArray(d.crew) ? d.crew : null;
    const fleet = d.fleet && !Array.isArray(d.fleet) ? d.fleet : null;
    const inRepair = fleet && Array.isArray(fleet.in_repair) ? fleet.in_repair : [];
    // UI_REFERENCE_V1: four KPI cards (each opens the related, filtered page), Quick actions, then
    // left: Needs attention + Recent maintenance activity; right: Mechanic status + Trucks in repair.
    const open = s ? n('new_reports') + n('under_inspection') + n('under_repair') + n('for_verification') : '—';
    const tiles = [
      { label: 'Open repairs', value: open, note: s ? `${n('new_reports')} new · ${n('under_inspection') + n('under_repair')} in progress` : '', icon: 'bi-wrench-adjustable', tone: s && open ? 'blue' : 'gray', go: 'maintenance' },
      can.parts ? { label: 'Waiting for parts', value: n('pending_parts'), note: s ? `${n('parts_requested')} requested · ${n('parts_partially_released')} partial` : '', icon: 'bi-box-seam', tone: s && s.pending_parts ? 'amber' : 'gray', go: can.requestParts ? 'mp_parts_requests?status=open' : 'parts_requests' }
        : { label: 'New reports', value: n('new_reports'), note: s ? `${n('new_incidents')} from trip incidents` : '', icon: 'bi-exclamation-octagon', tone: s && s.new_reports ? 'amber' : 'gray', go: 'maintenance?view=reported' },
      { label: 'For verification', value: n('for_verification'), note: 'repaired, still out of service', icon: 'bi-clipboard-check', tone: s && s.for_verification ? 'amber' : 'gray', go: 'maintenance?view=verify' },
      crew ? { label: 'Mechanics on duty', value: crew.on_duty, note: `${crew.available} available · ${crew.assigned} on a repair`, icon: 'bi-person-gear', tone: crew.on_duty ? 'green' : 'gray', go: 'mechanic_attendance?duty=in' }
        : { label: 'Trucks under maintenance', value: inRepair.length, note: fleet ? `of ${fleet.active_trucks} active trucks` : '', icon: 'bi-truck', tone: inRepair.length ? 'red' : 'gray', go: 'trucks?status=Under Maintenance' },
    ];
    // Mechanic status: the Mechanic Masterlist values, each row opens the filtered masterlist.
    const crewBody = !crew ? '' : crew.active ? UI.wsList([
        UI.wsEntry('mechanics?availability=Available', '<b>Available</b>', 'Timed in, no current repair', `<span class="badge-s green">${UI.num(crew.available)}</span>`),
        UI.wsEntry('mechanics?availability=Assigned', '<b>On a repair</b>', 'Assigned to an open repair record', `<span class="badge-s blue">${UI.num(crew.assigned)}</span>`),
        UI.wsEntry('mechanics?availability=Unavailable', '<b>Not timed in</b>', 'Cannot take a repair until timed in', `<span class="badge-s ${crew.unavailable ? 'amber' : 'gray'}">${UI.num(crew.unavailable)}</span>`)], '')
      : '<div class="mo-empty">No active mechanics. Add them in the Mechanic Masterlist.</div>';
    const crewPanel = crew ? UI.wsPanel('Mechanic status', 'bi-person-gear', crewBody, { cls: 'ws-r1', go: 'mechanics', foot: `${crew.on_duty} of ${crew.active} active mechanics are timed in.` }) : '';
    // Trucks in repair: each row opens the repair record.
    const truckPanel = fleet ? UI.wsPanel('Trucks in repair', 'bi-truck', inRepair.length ? `<ul class="ws-trucks mo-scroll">${inRepair.map(t => `<li class="clickable" data-open-job="${+t.job_id}" role="button" tabindex="0">
          <div><b>${UI.esc(t.plate_number)}</b>${t.truck_number ? ` <span class="text-muted">#${UI.esc(t.truck_number)}</span>` : ''}<div class="cell-sub">${UI.esc(t.job_ref)} · ${UI.esc(t.truck_type)}</div></div>
          ${UI.badge(+t.awaiting_verification ? 'Awaiting verification' : t.status)}</li>`).join('')}</ul>` : '<div class="mo-empty"><i class="bi bi-check2-circle"></i> Every truck is in service.</div>',
      { cls: 'ws-r2', count: inRepair.length, go: 'maintenance' }) : '';

    const att = Array.isArray(d.attention) ? d.attention : [];
    const lim = (d.limits && d.limits.attention) || 25;
    const attShown = att.slice(0, lim);
    const act = Array.isArray(d.activity) ? d.activity : [];
    const failed = d._errors || {};

    const attentionBody = failed.attention ? '<div class="mo-empty text-danger">The list could not be loaded. Reload the page.</div>'
      : attShown.length ? `<ul class="mo-list mo-scroll">${attShown.map(r => MaintOverview.attentionItem(r, can)).join('')}</ul>`
        + (att.length > lim ? `<div class="mo-more">Showing the ${lim} records waiting the longest. <a href="#maintenance">Open Repair Management</a> for the rest.</div>` : '')
      : '<div class="mo-empty"><i class="bi bi-check2-circle"></i> Nothing needs your action right now.</div>';
    const activityBody = failed.activity ? '<div class="mo-empty text-danger">Recent activity could not be loaded. Reload the page.</div>'
      : act.length ? `<ul class="mo-acts mo-scroll">${act.map(MaintOverview.activityItem).join('')}</ul>`
      : '<div class="mo-empty">No maintenance activity recorded yet.</div>';

    ctx.el.innerHTML = `<div class="maint-overview mp-ws">
      ${UI.kpiTiles(tiles, 'ws-kpi4')}
      ${UI.quickBar([can.act ? { label: 'Report truck issue', icon: 'bi-plus-lg', act: 'report', primary: true } : null,
        { label: 'Repair management', icon: 'bi-wrench-adjustable', go: 'maintenance' },
        can.requestParts ? { label: 'Parts requests', icon: 'bi-box-seam', go: 'mp_parts_requests' } : null,
        { label: 'Mechanic attendance', icon: 'bi-clock-history', go: 'mechanic_attendance' },
        { label: 'Reports', icon: 'bi-graph-up', go: 'maintenance_reports' }])}
      <div class="ws-grid2">
        ${UI.wsPanel('Needs attention', 'bi-flag', attentionBody, { cls: 'mo-attention ws-l1', count: failed.attention ? undefined : att.length, go: 'maintenance' })}
        ${crewPanel}
        ${UI.wsPanel('Recent maintenance activity', 'bi-activity', activityBody, { cls: 'ws-l2', go: 'maint_history' })}
        ${truckPanel}
      </div></div>`;

    const root = ctx.el.querySelector('.maint-overview');
    root.querySelector('[data-qa="report"]')?.addEventListener('click', () => MaintUI.report());
    root.querySelectorAll('[data-open-job]').forEach(x => {
      const go = () => App.go('maintenance', { open: x.dataset.openJob });
      x.addEventListener('click', go);
      if (x.tagName !== 'BUTTON') x.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    });
  },
});

/* MOTORPOOL_REPORTS_V2 — Maintenance Reports & Analytics (Motorpool workspace "Reports").
   Data: Analytics::maintenanceReport (read-only SQL; metric definitions and suggestion rules are documented there).
   Row 1: Completed repairs · Ongoing repairs (now) · Trucks serviced · Maintenance cost (opens the traceable breakdown)
   Row 2: Maintenance Activity Timeline — multi-line chart; legend toggles series; a point opens a floating period popover
          (activity details + rule-based suggested actions + drill-downs). Keyboard: Left/Right choose a period, Enter opens.
   Row 3: Maintenance Expense Breakdown (donut + entries) · Repair Frequency by Truck (most / least repaired).
   Nothing on this page writes or creates records. */
const MaintReport = {
  charts: [],
  SERIES: [
    ['reported', 'Reported repairs', '#d97706', []], ['under_repair', 'Under repair', '#2563eb', []], ['completed', 'Completed repairs', '#16a34a', []],
    ['awaiting', 'Awaiting verification', '#9333ea', [6, 4]], ['parts', 'Parts requests', '#64748b', [2, 3]],
  ],
  COST_COLORS: ['#2f7d57', '#d99a1e', '#2563eb', '#9333ea', '#64748b', '#0891b2'],
  sugList(items, empty) {
    return items && items.length ? `<ul class="ws-sug">${items.map(x => `<li>${UI.esc(x.text)}${x.go && UI.canGo(x.go) ? ` <button type="button" class="btn btn-link btn-sm" data-go="${UI.esc(x.go)}">${UI.esc(x.label || 'Open')}</button>` : ''}</li>`).join('')}</ul>`
      : `<div class="ws-sug-empty">${UI.esc(empty)}</div>`;
  },
  /** Drawer with every entry behind the Maintenance Cost total (same date and inclusion rules). */
  openCost(d) {
    const c = d.cost || {}, e = d.entries || {}, per = d.period || {};
    const jobLink = (v, r) => r.job_id ? `<b>${UI.esc(v)}</b>` : UI.e(v);
    UI.drawer('Maintenance cost', `${UI.kv([['Period', UI.date(per.from) + ' – ' + UI.date(per.to)], ['Parts used', UI.money(c.parts) + ` <span class="cell-sub">${UI.num(c.parts_n)} release(s)</span>`],
        ...(c.types || []).map(t => [t.type, UI.money(t.total) + ` <span class="cell-sub">${UI.num(t.n)} entr${t.n === 1 ? 'y' : 'ies'}</span>`]), ['Total', `<b>${UI.money(c.total)}</b>`]])}
      <p class="small text-muted mt-2">Parts used = parts released to repairs, at the unit cost recorded on each release (release date). Other costs = labor and services recorded on repairs (date incurred). Purchase orders and payments are not included, so nothing is counted twice.</p>
      ${UI.h('Parts used (' + UI.num((e.parts || []).length) + ')')}${UI.table([['Released', 'at', 'datetime'], ['Repair', 'job_ref', jobLink], ['Truck', 'plate_number'], ['Request', 'request_ref'], ['Part', 'part_number'], ['Qty', 'qty', 'num'], ['Value', 'value', 'money']], e.parts || [], { plain: true, limit: 10, empty: 'No parts were released in this period.' })}
      ${UI.h('Labor and services (' + UI.num((e.costs || []).length) + ')')}${UI.table([['Date', 'at', 'date'], ['Repair', 'job_ref', jobLink], ['Truck', 'plate_number'], ['Type', 'cost_type'], ['Description', 'description'], ['Amount', 'amount', 'money']], e.costs || [], { plain: true, limit: 10, empty: 'No labor or service cost was recorded in this period.' })}`,
      { subtitle: 'Recorded entries behind the total' });
  },
};

App.page('maintenance_reports', {
  title: 'Maintenance Reports',
  subtitle: 'Repairs, maintenance activity and maintenance spending.',
  render(ctx) {
    MaintReport.charts.forEach(ch => { try { ch.destroy(); } catch { /* already gone */ } }); MaintReport.charts = [];
    const d = ctx.data, per = d.period || {}, b = d.buckets || [];
    const k = d.kpis && !Array.isArray(d.kpis) ? d.kpis : null, c = d.cost && !Array.isArray(d.cost) ? d.cost : null;
    const tl = d.timeline && !Array.isArray(d.timeline) ? d.timeline : null, sug = d.suggestions || {};
    const freq = Array.isArray(d.frequency) ? d.frequency : [];
    const unit = { day: 'day', week: 'week', month: 'month' }[per.granularity] || 'period';
    const tiles = [
      { label: 'Completed repairs', value: k ? UI.num(k.completed) : '—', note: 'completed in the period', tone: 'gray', go: 'maint_history' },
      { label: 'Ongoing repairs', value: k ? UI.num(k.ongoing) : '—', note: k ? `now · ${UI.num(k.awaiting_verification)} awaiting verification` : 'now', tone: 'gray', go: 'maintenance' },
      { label: 'Trucks serviced', value: k ? UI.num(k.trucks_serviced) : '—', note: 'trucks with a completed repair', tone: 'gray', go: 'maint_history' },
      { label: 'Maintenance cost', value: c ? UI.money(c.total) : '—', note: 'parts used + recorded repair costs', tone: 'gray' },
    ];
    const series = MaintReport.SERIES.filter(([key]) => tl && Array.isArray(tl[key]));
    const notes = [];
    if (tl) (tl.omitted || []).forEach(o => notes.push(`${o.series} not shown: ${o.reason}`));
    const basis = tl ? `Reported by date reported, completed by completion date, parts requests by request date; under repair and awaiting verification show the status at the end of each ${unit}, from the recorded status history.` : '';
    const timelineBody = tl ? `<div class="mr-chart" role="group" aria-label="Maintenance activity by ${unit}"><canvas id="mrTimeline" tabindex="0"
        aria-label="Maintenance activity timeline. Use the left and right arrow keys to choose a ${unit} and Enter to open its details."></canvas></div>
        <div class="mr-note">${UI.esc(basis)} Select a point to see that ${unit}'s details.${notes.length ? '<br>' + notes.map(UI.esc).join('<br>') : ''}</div>`
      : '<div class="mo-empty text-danger">The activity timeline could not be loaded.</div>';

    // Expense breakdown: parts used + each recorded cost type. The total equals the KPI (same query results).
    const cats = c ? [{ label: 'Parts used', value: +c.parts, n: c.parts_n }, ...(c.types || []).map(t => ({ label: t.type === 'Outside Service' ? 'Outside services' : t.type, value: +t.total, n: t.n }))] : [];
    const costBody = c ? `<div class="mr-cost"><div class="mr-donut">${c.total > 0 ? '<canvas id="mrCost" aria-label="Maintenance expense breakdown"></canvas>' : ''}
        <div class="mr-donut-total"><span>Total</span><b>${UI.money(c.total)}</b></div></div>
        <ul class="mr-legend">${cats.map((x, i) => `<li><i style="background:${MaintReport.COST_COLORS[i % MaintReport.COST_COLORS.length]}"></i><span>${UI.esc(x.label)}</span>
          <b>${UI.money(x.value)}</b><small>${c.total > 0 ? Math.round(x.value / c.total * 100) + '%' : '—'}</small></li>`).join('')}
          ${(c.types || []).length ? '' : '<li class="mr-legend-note">No labor or outside-service cost was recorded on repairs in this period.</li>'}</ul></div>`
      : '<div class="mo-empty text-danger">The expense breakdown could not be loaded.</div>';

    // Repair frequency: repair jobs reported in the period (cancelled excluded) for every active truck.
    const most = freq.filter(t => +t.repairs > 0).slice(0, 5);
    const least = freq.slice().sort((x, y) => (+x.repairs - +y.repairs) || String(x.plate_number).localeCompare(y.plate_number)).slice(0, 5);
    const row = (t) => { const go = 'maintenance?view=all&cancelled=0&vehicle_id=' + t.id + '&from=' + per.from + '&to=' + per.to, ok = UI.canGo(go);
      return `<li class="mr-truck${ok ? ' clickable' : ''}"${ok ? ` data-go="${UI.esc(go)}" role="link" tabindex="0"` : ''}><span><b>${UI.esc(t.plate_number)}</b>${t.truck_number ? ` <span class="text-muted">#${UI.esc(t.truck_number)}</span>` : ''}<small>${UI.esc(t.truck_type || '')}</small></span>
        <span class="badge-s ${+t.repairs ? 'blue' : 'gray'}">${UI.num(t.repairs)} repair${+t.repairs === 1 ? '' : 's'}</span></li>`; };
    const freqBody = `<div class="mr-freq"><div><h3>Most repaired</h3>${most.length ? `<ul>${most.map(row).join('')}</ul>` : '<div class="ws-sug-empty">No repair was reported in this period.</div>'}</div>
      <div><h3>Least repaired</h3>${least.length ? `<ul>${least.map(row).join('')}</ul>` : '<div class="ws-sug-empty">No active truck.</div>'}</div></div>`;

    ctx.el.innerHTML = `${UI.wsPeriod('maintenance_reports', per)}${UI.kpiTiles(tiles, 'ws-kpi4')}
      ${UI.wsPanel('Maintenance Activity Timeline', 'bi-graph-up', timelineBody, { cls: 'mr-timeline', tipHtml: MaintReport.sugList(sug.timeline, 'No action is suggested from the recorded maintenance activity.') })}
      <div class="ws-grid2 ws-report-row mt-3">
        ${UI.wsPanel('Maintenance Expense Breakdown', 'bi-pie-chart', costBody, { cls: 'ws-l1', foot: `<button type="button" class="btn btn-link btn-sm p-0" data-cost-open>View recorded entries</button> · parts at release date, other costs at the date incurred` })}
        ${UI.wsPanel('Repair Frequency by Truck', 'bi-truck', freqBody, { cls: 'ws-r1', tipHtml: MaintReport.sugList(sug.trucks, 'Repair counts per truck are shown as recorded; no rule applies.'),
          foot: `Repair jobs reported in the period for ${UI.num(freq.length)} active trucks (cancelled excluded). Not a reliability measure: truck usage is not recorded here.` })}
      </div>`;
    UI.bindTips(ctx.el); UI.bindPeriod(ctx.el);

    // Maintenance Cost KPI opens the same traceable breakdown.
    const costTile = [...ctx.el.querySelectorAll('.kpi-tile')][3];
    if (costTile && c) { costTile.classList.add('clickable'); costTile.setAttribute('role', 'button'); costTile.tabIndex = 0; costTile.setAttribute('aria-label', 'Maintenance cost ' + UI.money(c.total) + ': open the recorded entries');
      const open = () => MaintReport.openCost(d); costTile.onclick = open; costTile.onkeydown = (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } }; }
    ctx.el.querySelector('[data-cost-open]')?.addEventListener('click', () => MaintReport.openCost(d));

    if (typeof Chart === 'undefined') return;
    // ---- timeline chart
    const cv = ctx.el.querySelector('#mrTimeline');
    if (cv && tl) {
      const chart = new Chart(cv, { type: 'line',
        data: { labels: b.map(x => x.short), datasets: series.map(([key, label, color, dash]) => ({ label, data: tl[key], borderColor: color, backgroundColor: color,
          borderDash: dash, borderWidth: 2, pointRadius: b.length > 40 ? 2 : 3, pointHoverRadius: 6, tension: 0, fill: false })) },
        options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Count' } }, x: { ticks: { autoSkip: true, maxRotation: 0 } } },
          plugins: { legend: { position: 'top', align: 'start', labels: { usePointStyle: true, pointStyle: 'line', boxWidth: 18, boxHeight: 3, padding: 14, font: { size: 12 } } },
            tooltip: { callbacks: { title: (it) => b[it[0].dataIndex] ? b[it[0].dataIndex].label : '' } } },
          onClick: (evt, els, ch) => { const hit = ch.getElementsAtEventForMode(evt, 'index', { intersect: false }, false); if (hit.length) openPoint(hit[0].index); } } });
      MaintReport.charts.push(chart);
      const pointRect = (i) => () => {
        const r = cv.getBoundingClientRect(), metas = chart.data.datasets.map((_, j) => chart.getDatasetMeta(j)).filter(m => !m.hidden && m.data[i]);
        const x = metas.length ? metas[0].data[i].x : chart.chartArea.left, y = metas.length ? Math.min(...metas.map(m => m.data[i].y)) : chart.chartArea.top;
        return { left: r.left + x, right: r.left + x, top: r.top + y - 6, bottom: r.top + y + 6 };
      };
      const openPoint = (i) => {
        const bk = b[i]; if (!bk) return;
        const range = 'from=' + bk.from + '&to=' + bk.to;
        const rows = series.map(([key, label, color]) => `<li><i style="background:${color}"></i><span>${UI.esc(label)}${key === 'under_repair' || key === 'awaiting' ? ' <small>(end of ' + unit + ')</small>' : ''}</span><b>${UI.num(tl[key][i])}</b></li>`).join('');
        const links = [['maintenance?view=all&cancelled=0&' + range, 'Open related repairs'], ['mp_parts_requests?status=all&' + range, 'Open related parts requests']]
          .filter(([g]) => UI.canGo(g)).map(([g, l]) => `<button type="button" class="btn btn-sm btn-outline-secondary" data-go="${UI.esc(g)}">${l}</button>`).join('');
        UI.openPointPop(cv, pointRect(i), 'Activity details: ' + bk.label, `
          <div class="ws-pp-sec"><div class="ws-pp-h">Selected period</div><b>${UI.esc(bk.label)}</b> <span class="cell-sub">${UI.date(bk.from)}${bk.to !== bk.from ? ' – ' + UI.date(bk.to) : ''}</span></div>
          <div class="ws-pp-sec"><div class="ws-pp-h">Maintenance activity</div><ul class="ws-pp-kv">${rows}</ul><div class="ws-pp-links">${links}</div></div>
          <div class="ws-pp-sec ws-pp-sug"><div class="ws-pp-h"><i class="bi bi-lightbulb" aria-hidden="true"></i> Suggested actions</div>${MaintReport.sugList((sug.points || [])[i], 'No action is suggested for this period.')}</div>`);
        chart.setActiveElements(series.map((_, j) => ({ datasetIndex: j, index: i }))); chart.update('none');
      };
      // keyboard: Left / Right move the highlighted period, Enter / Space opens it
      let cur = -1;
      cv.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); cur = cur < 0 ? (e.key === 'ArrowRight' ? 0 : b.length - 1) : Math.max(0, Math.min(b.length - 1, cur + (e.key === 'ArrowRight' ? 1 : -1)));
          const act = series.map((_, j) => ({ datasetIndex: j, index: cur })); chart.setActiveElements(act); chart.tooltip.setActiveElements(act, { x: 0, y: 0 }); chart.update('none'); }
        if ((e.key === 'Enter' || e.key === ' ') && cur >= 0) { e.preventDefault(); openPoint(cur); }
      });
    }
    // ---- expense donut
    const dc = ctx.el.querySelector('#mrCost');
    if (dc && c && c.total > 0) MaintReport.charts.push(new Chart(dc, { type: 'doughnut',
      data: { labels: cats.map(x => x.label), datasets: [{ data: cats.map(x => x.value), backgroundColor: cats.map((_, i) => MaintReport.COST_COLORS[i % MaintReport.COST_COLORS.length]), borderColor: '#fff', borderWidth: 2 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '68%', plugins: { legend: { display: false },
        tooltip: { callbacks: { label: (it) => `${it.label}: ${UI.money(it.raw)} (${Math.round(it.raw / c.total * 100)}%)` } } } } }));
  },
});
