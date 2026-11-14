/* assets/js/modules/operations/trips.js — TRIPS (create, assign, fund, checklist, dispatch, incident, complete, arrival checklist)
   One page for the whole trip workflow of PDF #12 (Scenarios A, B and C).
   - Active tab: every open trip as a card with a progress bar and the next step (who does what).
   - History tab: closed trips with ONE filter modal.
   - Incidents tab: incident reports (printable Incident Report Form, PDF #13).
   - Click a trip: details drawer with the actions allowed for your role.
   Backend: backend/Modules/Operations/Trips.php (fund request: Accounting/Funds.php) */
'use strict';
const TripUI = {
  STAGES: ['Requested', 'Assigned', 'Fund & checklist', 'Dispatched', 'Completed'],
  data: null,

  /** Current stage index and whether it is a problem state. */
  stage(t) {
    switch (t.status) {
      case 'Pending Review': return [0, false];
      case 'Awaiting Resources': return [1, true];
      case 'Pre-Assigned': case 'Ready for Dispatch': return [2, false];
      case 'For Reschedule': return [2, true];
      case 'Dispatched': case 'In Transit': return [3, false];
      case 'On Hold - Incident Reported': return [3, true];
      case 'Completed': return +t.awaiting_arrival ? [4, false] : [5, false];
      default: return [0, true];
    }
  },
  isDelayed(t) { return ['Pending Review', 'Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch'].includes(t.status) && new Date(String(t.scheduled_at).replace(' ', 'T')) < new Date(); },
  flags(t) {
    const f = [];
    if (TripUI.isDelayed(t)) f.push(UI.badge('Delayed'));
    if (+t.delay_count) f.push(`<span class="badge-s amber">${+t.delay_count} delay update(s)</span>`);
    if (t.incident_status && t.incident_status !== 'Resolved') f.push(`<span class="badge-s red">Incident: ${UI.esc(t.incident_status)}</span>`);
    if (+t.attempt_number > 1) f.push(`<span class="badge-s blue">Attempt #${+t.attempt_number}</span>`);
    if (+t.awaiting_arrival) f.push('<span class="badge-s amber">Arrival checklist pending</span>');
    if (+t.arrival_not_ok) f.push('<span class="badge-s red">Arrival: NOT OK items</span>');
    return f.join(' ');
  },
  fundText(t) {
    if (!t.fund_status) return +t.attempt_number > 1 ? 'Fund: optional for redispatch' : 'Fund: not requested yet';
    return 'Fund: ' + t.fund_status;
  },
  /** Plain-language next step and who does it. */
  next(t) {
    switch (t.status) {
      case 'Pending Review': return 'Operations Head reviews the request and confirms the details.';
      case 'Awaiting Resources': return 'Waiting for the assigned Dispatcher to select an eligible Truck and its fixed Driver.';
      case 'Pre-Assigned': {
        const s = [];
        if (!t.fund_status && +t.attempt_number === 1) s.push('dispatcher submits the fund request');
        if (t.fund_status === 'Rejected') s.push('dispatcher revises the rejected fund request');
        if (t.fund_status === 'Pending Approval') s.push('Operations Head approves the fund');
        if (t.fund_status === 'Ready for Release') s.push('Finance releases the fund');
        s.push('dispatcher does the pre-dispatch checklist');
        return 'Next: ' + s.join('; ') + '.';
      }
      case 'Ready for Dispatch':
        if (!t.fund_status && +t.attempt_number > 1) return 'Checklist done. Dispatcher can Confirm Redispatch.';
        if (t.fund_status === 'Released') return 'Checklist done and fund released. Dispatcher can Confirm Dispatch.';
        return 'Checklist done. Waiting for the fund (' + (t.fund_status || 'not requested') + ').';
      case 'Dispatched': case 'In Transit': return 'On the road. Dispatcher monitors the trip and completes it with the waybill and acknowledgement receipt.';
      case 'On Hold - Incident Reported':
        if (t.incident_status === 'Reported') return 'Maintenance assesses the damage (minor or major).';
        if (t.incident_status === 'Repaired - Cleared to Continue') return 'Motorpool recorded Fit to Continue. Dispatcher resumes the trip.';
        return 'Mechanic is repairing the truck on site.';
      case 'For Reschedule': return t.truck_status === 'Assigned' ? 'Truck is repaired. Dispatcher reschedules the trip.' : 'Truck is being repaired (major damage). Then the dispatcher reschedules.';
      case 'Completed':
        if (+t.awaiting_arrival) return 'Delivered. The truck is returning: the dispatcher does the arrival checklist when it is back.' + (t.billing_status ? ' · Billing: ' + t.billing_status : '');
        return 'Completed' + (t.billing_status ? ' · Billing: ' + t.billing_status : '');
      default: return t.cancel_reason ? 'Reason: ' + t.cancel_reason : '';
    }
  },
  needsMe(t) {
    const r = App.me.user.role;
    if (r === 'operations_head') return t.status === 'Pending Review' || t.fund_status === 'Pending Approval' || t.status === 'Awaiting Resources';
    if (UI.isDispatcher() && t.dispatcher_role === r) {
      return (t.status === 'Pre-Assigned') || (t.status === 'Ready for Dispatch' && (t.fund_status === 'Released' || (!t.fund_status && +t.attempt_number > 1)))
        || t.fund_status === 'Rejected' || (t.status === 'On Hold - Incident Reported' && t.incident_status === 'Repaired - Cleared to Continue')
        || (t.status === 'For Reschedule' && t.truck_status === 'Assigned') || (t.status === 'Completed' && +t.awaiting_arrival);
    }
    if (r === 'finance') return t.fund_status === 'Ready for Release';
    if (r === 'motorpool') return t.status === 'On Hold - Incident Reported' && t.incident_status === 'Reported';
    return false;
  },
  card(t) {
    const [st, warn] = TripUI.stage(t);
    const crew = [t.driver_name, t.driver2_name, t.helper_name].filter(Boolean).join(', ');
    return `<div class="trip-card ${TripUI.needsMe(t) ? 'needs-me' : ''}" data-trip="${t.id}">
      <div class="tc-top"><div><span class="tc-ref">${UI.esc(t.trip_ref)}</span> <span class="small text-muted">${UI.esc(t.truck_type || 'type not set')}</span></div>${UI.badge(t.status)}</div>
      <div class="tc-route"><b>${UI.esc(t.client)}</b>${t.consignee ? ' · ' + UI.esc(t.consignee) : ''}<br><i class="bi bi-geo-alt text-success"></i> ${UI.esc(t.pickup_point)} <i class="bi bi-arrow-right"></i> <i class="bi bi-geo text-danger"></i> ${UI.esc(t.delivery_point)}</div>
      <div class="tc-meta"><span><i class="bi bi-truck"></i> ${UI.esc(t.plate_number || 'no truck yet')}</span>${crew ? `<span><i class="bi bi-person"></i> ${UI.esc(crew)}</span>` : ''}
        ${['Pre-Assigned', 'Ready for Dispatch'].includes(t.status) ? `<span><i class="bi bi-cash-coin"></i> ${UI.esc(TripUI.fundText(t))}</span>` : ''}</div>
      ${UI.steps(TripUI.STAGES, st, { compact: true, warn })}
      ${TripUI.flags(t) ? `<div class="tc-flags">${TripUI.flags(t)}</div>` : ''}
      <div class="tc-next">${UI.esc(TripUI.next(t))}</div></div>`;
  },

  // ------------------------------------------------------------------ forms
  truckTypeOptions() {
    const r = App.me.user.role;
    if (r === 'car_carrier_dispatcher') return ['Car Carrier'];
    if (r === 'container_wingvan_dispatcher') return ['Container', 'Wing Van'];
    return App.me.truckTypes;
  },
  deliveryFields(t = {}, opts = {}) {
    const d = TripUI.data;
    const types = TripUI.truckTypeOptions();
    return [
      ...(opts.withClient ? [{ name: 'client_id', label: 'Client (Active clients with a billing rate)', type: 'select', required: true, col: 8, value: t.client_id,
          options: (d.clients || []).map(c => [c.id, c.company_name + (c.rate_types ? '' : ' (no rate)')]) }] : []),
      { name: 'consignee', label: 'Consignee / brand', col: 4, value: t.consignee, attrs: 'list="dlConsignee"', placeholder: 'e.g. JETOUR, COCA COLA' },
      { name: 'truck_type', label: 'Truck type', type: 'select', options: UI.list(types), value: t.truck_type || (types.length === 1 ? types[0] : ''), required: opts.needsKm, col: 4,
        help: 'Decides the dispatcher team: Car Carrier, or Container / Wing Van.' },
      { name: 'scheduled_at', label: 'Scheduled date and time', type: 'datetime', required: true, col: 4, value: t.scheduled_at ? String(t.scheduled_at).slice(0, 16).replace(' ', 'T') : '',
        // New schedule: now or later (Philippine time), at most 365 days ahead. An unchanged past schedule of an existing trip may stay.
        attrs: `min="${t.scheduled_at && String(t.scheduled_at).slice(0, 16).replace(' ', 'T') < UI.nowManila() ? String(t.scheduled_at).slice(0, 16).replace(' ', 'T') : UI.nowManila()}" max="${UI.nowManila(365 * 1440)}"`,
        help: 'Present or future date and time (Philippine time).' },
      { name: 'distance_km', label: 'Billable distance (km)', type: 'number', step: '0.01', min: 0, attrs: 'max="3000"', required: opts.needsKm, col: 4, value: t.distance_km,
        help: UI.Map.serviceReady() ? 'Filled in from the road distance or the approved route; you may change it (a reason is required). Billing = km x client rate.' : 'Pickup point to delivery point. Billing = km x client rate.' },
      { name: 'pickup_point', label: 'Pickup point', required: true, col: 6, value: t.pickup_point, attrs: 'list="dlPlaces"' },
      { name: 'delivery_point', label: 'Delivery point', required: true, col: 6, value: t.delivery_point, attrs: 'list="dlPlaces"' },
      { type: 'html', html: '<div data-mapbox></div>' },
      { type: 'hidden', name: 'pickup_lat', value: t.pickup_lat }, { type: 'hidden', name: 'pickup_lng', value: t.pickup_lng },
      { type: 'hidden', name: 'delivery_lat', value: t.delivery_lat }, { type: 'hidden', name: 'delivery_lng', value: t.delivery_lng },
      // Road distance (Checklist #5 Phase 3): approved client route, route option, distance info, override reason (map.js)
      ...UI.distanceFieldDefs({ withRoutePick: true, routeId: t.client_route_id, option: t.route_option, reason: t.distance_override_reason }),
      { name: 'delivery_details', label: 'Delivery details', type: 'textarea', rows: 2, col: 12, value: t.delivery_details },
      { name: 'booking_reference', label: 'Booking / nomination details', col: 6, value: t.booking_reference },
      { name: 'units_planned', label: 'Units (car carrier)', type: 'number', min: 1, col: 3, value: t.units_planned },
      { name: 'container_number', label: 'Container no.', col: 3, value: t.container_number },
      { name: 'container_size', label: 'Container size', col: 3, value: t.container_size, placeholder: '1X20 / X40' },
      { name: 'seal_number', label: 'Seal no.', col: 3, value: t.seal_number },
      { name: 'shipment_number', label: 'Shipment no.', col: 3, value: t.shipment_number },
      { type: 'html', html: '<div data-rate-warning class="col-12" role="status"></div>' },
    ];
  },
  datalists() {
    const d = TripUI.data || {};
    return `<datalist id="dlPlaces">${(d.places || []).map(p => `<option value="${UI.esc(p)}">`).join('')}</datalist>
      <datalist id="dlConsignee">${(d.consignees || []).map(p => `<option value="${UI.esc(p)}">`).join('')}</datalist>`;
  },
  /** Wires map, availability and type-specific fields of a trip form. */
  wireForm(wrap, form, t = {}) {
    // Map + road distance + approved client route (shared with Client Routes; the server checks again on save).
    const dist = UI.distanceWidget(form, { mapBox: wrap.querySelector('[data-mapbox]'), initial: t,
      routePick: form.querySelector('[name=client_id]') ? { clientField: 'client_id', truckField: 'truck_type' } : { clientId: t.client_id, truckField: 'truck_type' } });
    wrap.addEventListener('shown.bs.modal', () => dist.map && dist.map.invalidateSize());
    const typeSel = form.querySelector('[name=truck_type]');
    const show = () => {
      const type = typeSel.value;
      const toggle = (name, on) => { const el = form.querySelector(`[name=${name}]`); if (el) el.closest('[class*=col-]').style.display = on ? '' : 'none'; };
      toggle('units_planned', !type || type === 'Car Carrier');
      ['container_number', 'container_size', 'seal_number'].forEach(n => toggle(n, !type || type === 'Container'));
      toggle('shipment_number', !type || type !== 'Car Carrier');
      // Assignment stays server-side. Show only actionable client-rate errors here.
      const notice = wrap.querySelector('[data-rate-warning]');
      const client = form.querySelector('[name=client_id]');
      if (notice) {
        notice.innerHTML = '';
        if (client && client.value && type) {
          const c = (TripUI.data.clients || []).find(x => String(x.id) === client.value);
          if (c && !(c.rate_types || '').split('|').includes(type)) {
            notice.innerHTML = `<div class="alert alert-danger py-2 small mb-1">${UI.esc(c.company_name)} has no billing rate for ${UI.esc(type)} yet. Ask Billing to set it.</div>`;
          }
        }
      }
    };
    typeSel.addEventListener('change', show);
    form.querySelector('[name=client_id]')?.addEventListener('change', show);
    show();
  },

  review() { UI.toast('Trip approvals are retired. Historical requests are read-only.', 'info'); },
  edit(t) {
    if (UI.role('operations_head')) {
      UI.modal('Edit trip ' + UI.esc(t.trip_ref), TripUI.datalists() + UI.fields(TripUI.deliveryFields(t, { needsKm: true }).filter(f => f.name !== 'truck_type')),
        async (d, form) => { form._distance?.clean(d); const r = await App.api('Trips.edit', { ...d, trip_id: t.id }); UI.result(r); TripUI.open(t.id); App.route(); },
        { onShow: (w, f) => {
          const dist = UI.distanceWidget(f, { mapBox: w.querySelector('[data-mapbox]'), initial: t, routePick: { clientId: t.client_id, truckType: t.truck_type } });
          w.addEventListener('shown.bs.modal', () => dist.map && dist.map.invalidateSize());
          w.querySelector('[data-rate-warning]')?.remove(); } });
    } else {
      UI.formModal('Trip details ' + UI.esc(t.trip_ref), [
        { name: 'booking_reference', label: 'Booking / nomination details', value: t.booking_reference, col: 6 }, { name: 'units_planned', label: 'Units (car carrier)', type: 'number', min: 1, value: t.units_planned, col: 6 },
        { name: 'container_number', label: 'Container no.', value: t.container_number, col: 4 }, { name: 'container_size', label: 'Container size', value: t.container_size, col: 4 },
        { name: 'seal_number', label: 'Seal no.', value: t.seal_number, col: 4 }, { name: 'shipment_number', label: 'Shipment no.', value: t.shipment_number, col: 6 },
        { name: 'delivery_details', label: 'Delivery details', type: 'textarea', value: t.delivery_details, col: 12 }],
        'Trips.edit', { extra: { trip_id: t.id }, after: () => { TripUI.open(t.id); App.route(); } });
    }
  },
  async reassign() { UI.toast('The Dispatcher selects an eligible Truck and its fixed Driver in Trip Management.', 'info'); },
  async checklist(t, kind = 'Pre-Dispatch', before = null) {
    // Starts (or continues) the server-side inspection timer; reopening does not restart it.
    let timer;
    if (!await UI.confirmProceed('Begin truck checklist', 'Start or resume the ' + kind + ' checklist for ' + t.trip_ref + '?', 'Continue to checklist')) return;
    try { timer = await App.api('Trips.checklist_start', { trip_id: t.id, kind }); } catch (e) { return UI.toast(e.message, 'error'); }
    const secs = TripUI.data.checklist || [];
    const arrival = kind === 'Arrival';
    const prev = before ? (JSON.parse(before.items_json || '{}').items || {}) : {};
    const hint = (k) => !arrival ? '' : (prev[k] ? `<span class="badge-s ${prev[k].condition === 'OK' ? 'green' : 'red'} ms-1" title="Result before departure">Before: ${UI.esc(prev[k].condition)}</span>` : '<span class="badge-s ms-1">Before: —</span>');
    const intro = arrival
      ? `Truck <b>${UI.esc(t.plate_number)}</b> is back. Check every item again; the result before departure is shown beside each item. <b>NOT OK</b> items need photos and a description and open a repair record (the truck becomes Under Maintenance). All OK makes the truck Available.`
      : `Truck <b>${UI.esc(t.plate_number)}</b>. Mark every item OK or NOT OK. Any NOT OK item keeps the trip from being Ready for Dispatch.`;
    const extra = arrival
      ? UI.fields([{ name: 'odometer_km', label: 'Odometer on arrival (km)' + (before && before.odometer_km ? ' — departure: ' + before.odometer_km : ''), type: 'number', col: 4 },
          { name: 'fuel_level', label: 'Fuel level on arrival' + (before && before.fuel_level ? ' — departure: ' + before.fuel_level : ''), type: 'select', options: UI.list(TripUI.data.fuelLevels), required: true, col: 4 },
          { name: 'remarks', label: 'Remarks', col: 4 },
          { name: 'damage_notes', label: 'Damage, defects, missing equipment or incidents (required when an item is NOT OK)', type: 'textarea', rows: 2, col: 12 },
          { name: 'photos', label: 'Photos (required when an item is NOT OK)', type: 'file', multiple: true, col: 12, attrs: 'accept="image/*"' }])
      : UI.fields([{ name: 'odometer_km', label: 'Odometer (km)', type: 'number', col: 4 }, { name: 'fuel_level', label: 'Fuel level', type: 'select', options: UI.list(['Full', '3/4', '1/2', '1/4', 'Low']), col: 4 },
          { name: 'remarks', label: 'Remarks', col: 4 }]);
    const timerBox = +timer.min_minutes > 0 ? `<div class="alert alert-info py-2 small mb-2 d-flex align-items-center gap-2" data-timer><i class="bi bi-stopwatch"></i><span data-timer-text></span></div>` : '';
    const body = `${timerBox}<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2"><div class="small text-muted">${intro}</div>
      <button type="button" class="btn btn-sm btn-outline-success" id="chkAll"><i class="bi bi-check2-all"></i> Mark all OK</button></div>
      ${secs.map(s => `<div class="chk-sec"><h6>${UI.esc(s.title)}</h6>${s.items.map((label, i) => { const k = s.key + '_' + String(i + 1).padStart(2, '0');
        return `<div class="chk-row" data-k="${k}"><span>${UI.esc(label)}${hint(k)}</span><div class="btn-group" role="group">
          <input type="radio" class="btn-check" name="c_${k}" id="ok_${k}" value="OK"><label class="btn btn-outline-success" for="ok_${k}" title="OK" aria-label="OK">✓<span class="visually-hidden">OK</span></label>
          <input type="radio" class="btn-check" name="c_${k}" id="no_${k}" value="NOT OK"><label class="btn btn-outline-danger" for="no_${k}" title="NOT OK" aria-label="NOT OK">✕<span class="visually-hidden">NOT OK</span></label></div>
          <input class="form-control form-control-sm" placeholder="Remarks" data-rem="${k}" maxlength="250"></div>`; }).join('')}</div>`).join('')}
      ${extra}`;
    UI.modal((arrival ? 'Arrival checklist ' : 'Pre-dispatch checklist ') + UI.esc(t.trip_ref), body, async (d, form) => {
      const items = {}; const missing = [];
      form.querySelectorAll('.chk-row').forEach(r => { const k = r.dataset.k; const v = form.querySelector(`input[name="c_${k}"]:checked`);
        if (!v) missing.push(r.firstElementChild.textContent); items[k] = { condition: v ? v.value : '', remarks: form.querySelector(`[data-rem="${k}"]`).value }; });
      if (missing.length) throw new Error('Mark OK or NOT OK for: ' + missing.slice(0, 5).join(', ') + (missing.length > 5 ? ' and ' + (missing.length - 5) + ' more' : ''));
      let r;
      if (arrival) {
        const bad = Object.values(items).some(x => x.condition === 'NOT OK');
        if (bad && !String(d.damage_notes || '').trim()) throw new Error('Describe the damage, defects or missing equipment of the NOT OK items.');
        if (bad && !form.querySelector('[name=photos]').files.length) throw new Error('Attach photos of the NOT OK items.');
        r = await App.apiForm('Trips.arrival', { trip_id: t.id, items, meta: {}, odometer_km: d.odometer_km, fuel_level: d.fuel_level, remarks: d.remarks, damage_notes: d.damage_notes }, form);
      } else {
        r = await App.api('Trips.checklist', { trip_id: t.id, items, meta: {}, odometer_km: d.odometer_km, fuel_level: d.fuel_level, remarks: d.remarks });
      }
      UI.result(r); TripUI.open(t.id); App.route();
    }, { size: 'modal-xl', submit: arrival ? 'Submit arrival checklist' : 'Submit checklist', onShow: (w, form) => {
      if (+timer.min_minutes > 0) {
        const btn = form.querySelector('button[type=submit]'), box = w.querySelector('[data-timer]'), txt = w.querySelector('[data-timer-text]');
        const end = Date.now() + (+timer.remaining_seconds) * 1000;
        const started = timer.started_at ? String(timer.started_at).slice(11, 16) : '';
        const tick = () => {
          const left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
          if (left > 0) {
            txt.innerHTML = `Minimum inspection time: <b>${timer.min_minutes} min</b> (started ${UI.esc(started)}). You can submit in <b>${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}</b>. Closing this window does not reset the timer.`;
            btn.disabled = true;
          } else {
            txt.innerHTML = `Minimum inspection time of ${timer.min_minutes} min reached (started ${UI.esc(started)}). You can submit the checklist.`;
            box.classList.replace('alert-info', 'alert-success'); btn.disabled = false;
          }
          return left;
        };
        if (tick() > 0) { const iv = setInterval(() => { if (!w.isConnected || tick() === 0) clearInterval(iv); }, 1000); }
      }
      w.querySelector('#chkAll').onclick = () => w.querySelectorAll('input[value=OK]').forEach(x => { x.checked = true; x.closest('.chk-row').classList.remove('bad'); });
      w.addEventListener('change', (e) => { if (e.target.classList.contains('btn-check')) e.target.closest('.chk-row').classList.toggle('bad', e.target.value === 'NOT OK'); });
    } });
  },
  complete(t) {
    const cc = t.truck_type === 'Car Carrier';
    const body = `<div class="small text-muted mb-2">Record the completion details and attach the supporting documents. <b>Complete Trip</b> stays disabled until everything required is there.</div>
      ${UI.fields([
        { name: 'completed_at', label: 'Actual completion date and time', type: 'datetime', value: (() => { const x = new Date(); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 16); })(), required: true, col: 6 },
        { name: 'waybill_number', label: 'Waybill no.', required: true, col: 6, value: t.waybill_number },
        ...(cc ? [{ name: 'units_delivered', label: 'Units delivered', type: 'number', min: 1, value: t.units_planned, required: true, col: 6 }] : []),
        { name: 'delivery_notes', label: 'Delivery / return notes', col: cc ? 6 : 12 },
        { name: 'waybill', label: 'Waybill file (PDF or photo)' + (+t.waybill_docs ? ' — already attached: ' + t.waybill_docs : ''), type: 'file', col: 6, attrs: 'accept="application/pdf,image/*"' },
        { name: 'ack_receipt', label: 'Acknowledgement receipt file' + (+t.ar_docs ? ' — already attached: ' + t.ar_docs : ''), type: 'file', col: 6, attrs: 'accept="application/pdf,image/*"' },
        { name: 'delivery_receipt', label: 'Delivery Receipt (DR) file' + (+t.dr_docs ? ' — already attached: ' + t.dr_docs : ''), type: 'file', col: 6, attrs: 'accept="application/pdf,image/*"' },
        { name: 'supplier_soa', label: 'Supplier SOA (optional)' + (+t.soa_docs ? ' — already attached: ' + t.soa_docs : ''), type: 'file', multiple: true, col: 6, attrs: 'accept="application/pdf,image/*"', help: 'Statement of Account from the diesel supplier, if given.' },
        { name: 'other', label: 'Other documents (optional)', type: 'file', multiple: true, col: 6, attrs: 'accept="application/pdf,image/*"' }])}
      <ul class="req-list" data-req></ul>`;
    UI.modal('Complete trip ' + UI.esc(t.trip_ref), body, async (d, form) => {
      const r = await App.apiForm('Trips.complete', { ...d, trip_id: t.id }, form); UI.result(r); TripUI.open(t.id); App.route();
    }, { submit: 'Complete Trip', onShow: (w, form) => {
      const btn = form.querySelector('button[type=submit]');
      const check = () => {
        const has = (n) => form.querySelector(`[name=${n}]`).files.length > 0;
        const items = [['Completion date and time', !!form.completed_at.value], ['Waybill number', !!form.waybill_number.value.trim()],
          ...(cc ? [['Units delivered', +form.units_delivered.value > 0]] : []),
          ['Waybill file', has('waybill') || +t.waybill_docs > 0], ['Acknowledgement receipt file', has('ack_receipt') || +t.ar_docs > 0],
          ['Delivery Receipt (DR) file', has('delivery_receipt') || +t.dr_docs > 0]];
        w.querySelector('[data-req]').innerHTML = items.map(([l, ok]) => `<li class="${ok ? 'ok' : 'no'}"><i class="bi ${ok ? 'bi-check-circle-fill' : 'bi-x-circle'}"></i> ${UI.esc(l)}${ok ? '' : ' — missing'}</li>`).join('');
        btn.disabled = items.some(x => !x[1]);
      };
      form.addEventListener('input', check); form.addEventListener('change', check); check();
    } });
  },
  incident(t) {
    const x = new Date(); x.setMinutes(x.getMinutes() - x.getTimezoneOffset());
    UI.formModalFiles('Incident report ' + UI.esc(t.trip_ref), [
      { name: 'incident_type', label: 'Incident type', type: 'select', options: UI.list(TripUI.data.incidentTypes), required: true, col: 6 },
      { name: 'incident_at', label: 'Incident date and time', type: 'datetime', value: x.toISOString().slice(0, 16), required: true, col: 6 },
      { name: 'location', label: 'Location of the incident', required: true, col: 6 },
      { name: 'persons_involved', label: 'Persons involved', col: 6, value: [t.driver_name, t.driver2_name, t.helper_name].filter(Boolean).join(', ') },
      { name: 'problem', label: 'Reported problem or damage', required: true, col: 6 },
      { name: 'reason', label: 'Reason / cause (if known)', col: 6 },
      { name: 'details', label: 'Incident details', type: 'textarea', rows: 4, required: true, col: 12 },
      { name: 'photos', label: 'Photos (optional)', type: 'file', multiple: true, col: 6, attrs: 'accept="image/*"' },
      { name: 'attachments', label: 'Police report / other documents (optional)', type: 'file', multiple: true, col: 6, attrs: 'accept="application/pdf,image/*"' }],
      'Trips.incident', { extra: { trip_id: t.id }, intro: 'The driver called in the breakdown. This puts the trip On Hold and sends the report to Maintenance.', submit: 'Submit incident report',
        after: () => { TripUI.open(t.id); App.route(); } });
  },
  /** " (17 min)" — how long the checklist took, from opening to submission. */
  duration(i) {
    if (!i.started_at) return '';
    const m = Math.round((new Date(String(i.completed_at).replace(' ', 'T')) - new Date(String(i.started_at).replace(' ', 'T'))) / 60000);
    return ` <span class="text-muted small">(${m} min)</span>`;
  },

  /** Arrival checklist result, compared with the pre-dispatch checklist (PDF #11 G: condition before departure vs upon arrival). */
  arrivalHtml(t, pre, arr) {
    if (!arr) return `<div class="text-muted small">${+t.awaiting_arrival ? 'Pending. The truck stays On Trip until the dispatcher submits the arrival checklist.' : 'Not required (trip completed before the arrival checklist was added).'}</div>`;
    const now = JSON.parse(arr.items_json || '{}').items || {}, then = pre ? (JSON.parse(pre.items_json || '{}').items || {}) : {};
    const bad = [], changed = [];
    (TripUI.data.checklist || []).forEach(s => s.items.forEach((label, i) => { const k = s.key + '_' + String(i + 1).padStart(2, '0');
      if (now[k] && now[k].condition === 'NOT OK') { bad.push(label + (now[k].remarks ? ' (' + now[k].remarks + ')' : '')); if (then[k] && then[k].condition === 'OK') changed.push(label); } }));
    const km = pre && pre.odometer_km && arr.odometer_km ? +arr.odometer_km - +pre.odometer_km : null;
    return UI.kv([['Result', bad.length ? '<span class="badge-s red">NOT OK items</span>' : '<span class="badge-s green">All 57 items OK</span>'], ['Done by', UI.e(arr.actor) + ' · ' + UI.datetime(arr.completed_at) + TripUI.duration(arr)],
      ['Odometer', arr.odometer_km ? (pre && pre.odometer_km ? UI.esc(pre.odometer_km) + ' → ' : '') + UI.esc(arr.odometer_km) + ' km' + (km !== null ? ` <span class="text-muted small">(${UI.num(km, 0)} km travelled)</span>` : '') : 'Not recorded on arrival' + (pre && pre.odometer_km ? ' (departure: ' + UI.esc(pre.odometer_km) + ' km)' : '')],
      ['Fuel', (pre && pre.fuel_level ? UI.esc(pre.fuel_level) + ' → ' : '') + UI.e(arr.fuel_level)],
      bad.length ? ['NOT OK', UI.esc(bad.join('; '))] : null,
      changed.length ? ['Changed since departure', '<span class="text-danger">' + UI.esc(changed.join(', ')) + '</span>'] : null,
      arr.damage_notes ? ['Damage / missing', UI.esc(arr.damage_notes)] : null, arr.remarks ? ['Remarks', UI.esc(arr.remarks)] : null,
      arr.job_ref ? ['Repair record', UI.esc(arr.job_ref) + ' ' + UI.badge(arr.job_status)] : null]);
  },

  printIncident(i, t) {
    UI.printForm('INCIDENT REPORT', `<div class="pf-grid"><div><b>NAME:</b> ${UI.esc(i.reported_by_name)}</div><div><b>DATE:</b> ${UI.date(i.created_at)}</div>
      <div><b>DEPARTMENT:</b> Operations</div><div><b>REFERENCE:</b> ${UI.esc(i.incident_ref)} / ${UI.esc(t.trip_ref || i.trip_ref || '')}</div></div>
      <div class="pf-grid"><div><b>INCIDENT TYPE:</b> ${UI.esc(i.incident_type)}</div><div><b>TRUCK:</b> ${UI.esc(t.plate_number || i.plate_number || '')}</div>
      <div><b>INCIDENT DATE:</b> ${UI.date(i.incident_at)}</div><div><b>INCIDENT TIME:</b> ${UI.esc(String(i.incident_at).slice(11, 16))}</div>
      <div style="grid-column:1/3"><b>LOCATION / PERSON/S INVOLVED IN INCIDENT:</b> ${UI.esc(i.location)}${i.persons_involved ? ' / ' + UI.esc(i.persons_involved) : ''}</div>
      <div style="grid-column:1/3"><b>REASON:</b> ${UI.esc(i.reason || '')}</div></div>
      <div><b>DESCRIPTION OF INCIDENT:</b></div><div class="pf-box" style="min-height:160px">${UI.esc(i.problem)}\n\n${UI.esc(i.details)}</div>
      <div class="pf-signs">${UI.sign('Reported by (name and signature)', i.reported_by_name, i.reported_by, 'incident', i.id)}${UI.sign('Received by (name and signature)', i.assessed_by_name || '', i.assessed_by, 'incident', i.id)}</div>
      <div class="mt-2"><b>RECOMMENDATION:</b></div><div class="pf-box">${UI.esc(i.recommendation || '')}</div>
      <div class="pf-signs">${UI.sign('Recommended by (name and signature)')}${UI.sign('Approved by (name and signature)')}</div>`, { number: i.incident_ref });
  },

  // ------------------------------------------------------------------ details drawer
  async open(id) {
    let r;
    try { r = await App.api('Trips.details', { trip_id: id }); } catch (e) { return UI.toast(e.message, 'error'); }
    const t = r.trip, me = App.me.user, mine = UI.isDispatcher() && t.dispatcher_role === me.role, ops = UI.role('operations_head');
    const [st, warn] = TripUI.stage(t);
    const btn = (act, label, cls = 'outline-primary', icon = '', disabled = false) => `<button class="btn btn-sm btn-${cls}" data-tact="${act}" ${disabled ? 'disabled' : ''}>${icon ? `<i class="bi ${icon}"></i> ` : ''}${UI.esc(label)}</button>`;
    const acts = [];
    let why = '';
    const pre = ['Awaiting Resources', 'Pre-Assigned', 'Ready for Dispatch', 'For Reschedule'];
    const gates = t.time_gates || {};
    if (ops) {
      if (pre.includes(t.status)) acts.push(btn('edit', 'Edit details', 'outline-secondary', 'bi-pencil'));
      if (pre.includes(t.status)) acts.push(btn('cancel', 'Cancel trip', 'outline-danger', 'bi-x-circle'));
      const lastInc = r.incidents[r.incidents.length - 1];
      if (t.status === 'On Hold - Incident Reported' && lastInc && lastInc.severity === 'Minor' && lastInc.clearance === 'Not Fit to Continue')
        acts.push(btn('increschedule', 'Move to For Reschedule (not fit)', 'outline-danger', 'bi-calendar-x'));
    }
    if (mine) {
      const curFund = r.funds.find(f => +f.attempt_number === +t.attempt_number);
      if (['Pre-Assigned', 'Ready for Dispatch'].includes(t.status) && (!curFund || curFund.status === 'Rejected')) acts.push(btn('fund', curFund ? 'Revise fund request' : 'Submit fund request', 'primary', 'bi-cash-coin'));
      // CHECKLIST_38_40_TRIP_TIME_GATES_V1: the server refuses early actions too; the button only mirrors it.
      if (t.status === 'Pre-Assigned') {
        const early = gates.checklist_allowed_now === false;
        acts.push(btn('checklist', 'Pre-dispatch checklist', early ? 'secondary' : 'primary', 'bi-ui-checks', early));
        if (early) why = 'Pre-dispatch checklist opens ' + UI.datetime(gates.checklist_opens_at) + ' (Philippine time).';
      }
      if (['Pre-Assigned', 'Ready for Dispatch'].includes(t.status)) {
        const blocked = t.dispatch_blockers.length > 0;
        acts.push(btn('dispatch', +t.attempt_number > 1 ? 'Confirm Redispatch' : 'Confirm Dispatch', blocked ? 'secondary' : 'success', 'bi-send', blocked));
        if (blocked) why = (why ? why + ' ' : '') + 'Confirm Dispatch is enabled when: ' + t.dispatch_blockers.join(' ');
      }
      if (['Dispatched', 'In Transit'].includes(t.status)) acts.push(btn('complete', 'Complete trip', 'success', 'bi-flag'), btn('incident', 'Report incident', 'outline-danger', 'bi-exclamation-triangle'));
      if (['Dispatched','In Transit','On Hold - Incident Reported'].includes(t.status)) acts.push(btn('progress','Record Progress','outline-success','bi-pencil-square'));
      // CHECKLIST_44_DIESEL_PO_SOA_ATTACHMENTS_V1: optional Diesel PO attachment (the server checks role, team, status and file).
      if (['Pre-Assigned', 'Ready for Dispatch', 'Dispatched', 'On Hold - Incident Reported', 'In Transit', 'For Reschedule', 'Completed'].includes(t.status))
        acts.push(btn('dieselpo', +t.diesel_po_docs ? `Diesel PO (${+t.diesel_po_docs}) — attach more` : 'Attach Diesel PO', 'outline-secondary', 'bi-fuel-pump'));
      if (t.status === 'On Hold - Incident Reported') {
        const ok = t.incident_status === 'Repaired - Cleared to Continue';
        acts.push(btn('resume', 'Resume trip', ok ? 'success' : 'secondary', 'bi-play-circle', !ok));
        if (!ok) why = 'Resume is enabled after Maintenance confirms the on-site repair (now: ' + t.incident_status + ').';
      }
      if (t.status === 'For Reschedule') {
        const ok = t.truck_status === 'Assigned' || !t.vehicle_id;
        acts.push(btn('reschedule', 'Reschedule trip', ok ? 'primary' : 'secondary', 'bi-calendar-plus', !ok));
        if (!ok) why = 'The truck is ' + t.truck_status + '. Reschedule after Maintenance confirms the repair (or the Dispatcher selects another eligible fixed pair).';
      }
      if (t.status === 'Completed' && +t.awaiting_arrival) acts.push(btn('arrival', 'Arrival checklist', 'primary', 'bi-clipboard-check'));
      // CHECKLIST_27_TRIP_DETAILS_LOCK_V1: match backend pre-dispatch edit rule.
      if (pre.includes(t.status)) acts.push(btn('edit', 'Booking / container details', 'outline-secondary', 'bi-pencil'));
    }
    const fund = r.funds.find(f => +f.attempt_number === +t.attempt_number);
    const ins = r.inspections.find(i => +i.attempt_number === +t.attempt_number && i.kind === 'Pre-Dispatch');
    const arr = r.inspections.find(i => +i.attempt_number === +t.attempt_number && i.kind === 'Arrival');
    const crew = [['Driver', t.driver_name], ...(t.truck_type === 'Car Carrier' ? [['Driver 2', t.driver2_name]] : [['Helper', t.helper_name]])];
    // CHECKLIST_42_ATTENDANCE_ENFORCEMENT_V1: attendance per crew member for the date of "dispatch now" (server data).
    const att = t.crew_attendance;
    const attBadge = (role) => {
      const c = att && att.crew.find(x => x.role === role);
      if (!c) return '';
      const color = c.problem ? (att.mode === 'Enforce' ? 'red' : 'amber') : 'green';
      return ` <span class="badge-s ${color}">${UI.esc(c.issue || 'Present')}</span>`;
    };
    const missingRow = att && att.missing && att.missing.length ? ['Crew required', att.missing.map(m => `<div class="small text-danger"><i class="bi bi-exclamation-octagon"></i> ${UI.esc(m)}</div>`).join('')] : null;
    const attRow = att && att.crew.length ? ['Crew attendance', `<span class="small">${UI.esc(att.mode)} mode · Confirm Dispatch now: ${UI.esc(att.dispatch_day)} · Trip scheduled: ${UI.esc(att.scheduled_day)}</span>`
      + att.crew.filter(c => c.problem).map(c => `<div class="small ${att.mode === 'Enforce' ? 'text-danger' : 'text-warning-emphasis'}"><i class="bi bi-exclamation-triangle"></i> ${UI.esc(c.problem)}</div>`).join('')] : null;
    let checklistHtml = '<div class="text-muted small">Not done yet.</div>';
    if (ins) {
      const items = JSON.parse(ins.items_json || '{}').items || {};
      const bad = []; (TripUI.data.checklist || []).forEach(s => s.items.forEach((label, i) => { const k = s.key + '_' + String(i + 1).padStart(2, '0'); if (items[k] && items[k].condition === 'NOT OK') bad.push(label + (items[k].remarks ? ' (' + items[k].remarks + ')' : '')); }));
      checklistHtml = UI.kv([['Result', bad.length ? '<span class="badge-s red">NOT OK items</span>' : '<span class="badge-s green">All 57 items OK</span>'], ['Done by', UI.e(ins.actor) + ' · ' + UI.datetime(ins.completed_at) + TripUI.duration(ins)],
        ['Odometer / fuel', UI.e(ins.odometer_km) + ' km · ' + UI.e(ins.fuel_level)], bad.length ? ['NOT OK', UI.esc(bad.join('; '))] : null, ins.remarks ? ['Remarks', UI.esc(ins.remarks)] : null]);
    }
    const html = `${UI.tripProgress(t, r)}
      <div class="d-flex flex-wrap gap-1 align-items-center mb-1">${UI.badge(t.status)} ${TripUI.flags(t)}</div>
      <div class="ops-next-step"><b>${ops ? 'Operations oversight' : mine ? 'Your next step' : 'Workflow next step'}</b><div>${UI.esc(TripUI.next(t))}</div></div>
      <div class="small my-2">${t.client_route_id ? '<span class="badge-s green">Approved client route linked</span>' : '<span class="badge-s amber">No approved client route linked</span> Map/manual distance does not establish route approval.'}</div>
      ${acts.length ? `<div class="drawer-actions">${acts.join('')}${why ? `<div class="why"><i class="bi bi-info-circle"></i> ${UI.esc(why)}</div>` : ''}</div>` : ''}
      ${UI.h('Trip')}
      ${UI.kv([['Client', `<b>${UI.esc(t.client)}</b>${t.consignee ? ' · ' + UI.esc(t.consignee) : ''}`], ['Route', `${UI.esc(t.pickup_point)} <i class="bi bi-arrow-right"></i> ${UI.esc(t.delivery_point)}`],
        ...(t.pricing_estimate?[['Pricing',UI.esc(t.pricing_estimate.frozen?'Dispatch snapshot':'Current estimate only')],['Fixed route / fuel / total',t.pricing_estimate.total===null||t.pricing_estimate.total===undefined?'Fuel Estimate Unavailable / Pending Fuel Calculation':UI.money(t.pricing_estimate.fixed)+' / '+UI.money(t.pricing_estimate.fuel)+' / '+UI.money(t.pricing_estimate.total)]]:[]),
        ['Billable distance', t.distance_km ? UI.num(t.distance_km, 2) + ' km' + (t.fuel_estimate ? ` <span class="text-muted small">(est. diesel ${UI.money(t.fuel_estimate)})</span>` : '') + (t.distance_source ? ` <span class="text-muted small">· ${UI.esc(t.distance_source)}</span>` : '') : '—'],
        t.distance_computed_km ? [t.client_route_id ? 'Approved client route' : 'Computed road distance', UI.num(t.distance_computed_km, 2) + ' km'
          + (t.client_route_ref ? ' · ' + UI.esc(t.client_route_ref) : '') + (t.route_option && (UI.Map.cfg().routeOptions || {})[t.route_option] ? ' · ' + UI.esc(UI.Map.cfg().routeOptions[t.route_option]) : '')
          + (t.distance_computed_at ? ' · ' + UI.datetime(t.distance_computed_at) : '')] : null,
        t.distance_override_reason ? ['Reason the km was changed', UI.esc(t.distance_override_reason)] : null,
        ['Schedule', UI.datetime(t.scheduled_at) + (pre.includes(t.status) && gates.checklist_opens_at
          ? `<div class="small text-muted">Pre-dispatch checklist from ${UI.datetime(gates.checklist_opens_at)} · Confirm Dispatch from ${UI.datetime(gates.dispatch_opens_at)} (Philippine time)</div>`
            + (+gates.late_minutes > 0 ? `<div class="small text-danger">Past the scheduled time by ${UI.num(gates.late_minutes, 0)} min; a late dispatch is noted in the history.</div>` : '') : '')],
        ['Truck type', UI.e(t.truck_type)], ['Truck', t.plate_number ? `<b>${UI.esc(t.plate_number)}</b>${t.truck_number ? ' #' + UI.esc(t.truck_number) : ''} ${UI.badge(t.truck_status)}` : '—'],
        ...crew.map(([l, v]) => [l, UI.e(v) + (v ? attBadge(l) : '')]), t.helper_substitution_reason ? ['Helper replacement reason', UI.esc(t.helper_substitution_reason)] : null, missingRow, attRow, ['Dispatcher team', UI.e(UI.label(t.dispatcher_role))], ...(t.dispatcher_shift ? [['Planning dispatcher shift', UI.e(t.dispatcher_shift)]] : []), ['Dispatched by', UI.e(t.dispatcher_name)],
        ['Delivery details', UI.e(t.delivery_details)], ['Booking / nomination', UI.e(t.booking_reference)],
        t.truck_type === 'Car Carrier' ? ['Units', UI.e(t.units_planned) + (t.units_delivered ? ' planned · ' + t.units_delivered + ' delivered' : '')] : ['Container / seal / shipment', [t.container_number, t.container_size, t.seal_number, t.shipment_number].filter(Boolean).map(UI.esc).join(' · ') || '—'],
        ['Created', UI.e(t.created_by_name) + ' (' + UI.esc(t.intake_source) + ') · ' + UI.datetime(t.created_at)], t.cancel_reason ? ['Cancel / reject reason', UI.esc(t.cancel_reason)] : null])}
      <div class="route-map" data-routemap></div>
      ${UI.h('Fund request', fund ? `<button class="btn btn-sm btn-link p-0" data-tact="printfund">Print request form</button>` : '')}
      ${fund ? UI.fundProgress(fund) + UI.kv([['Reference', UI.esc(fund.fund_ref)], ['Status', UI.badge(fund.status)], ['Amount', UI.money(fund.amount)], ['Breakdown', fund.lines.map(l => UI.esc(l.category_name) + ' ' + UI.money(l.amount)).join('<br>')],
          ['Requested', UI.e(fund.requested_by_name)], ['Approved', UI.e(fund.approved_by_name)], ['Released', fund.released_at ? UI.e(fund.released_by_name) + ' · ' + UI.datetime(fund.released_at) : '—'],
          fund.rejected_reason ? ['Rejected because', UI.esc(fund.rejected_reason)] : null])
        : `<div class="text-muted small">${+t.attempt_number > 1 ? 'No new fund request for this attempt (optional for a redispatch).' : 'No fund request yet.'}</div>`}
      ${UI.h('Pre-dispatch checklist' + (+t.attempt_number > 1 ? ' (attempt #' + t.attempt_number + ')' : ''))}${checklistHtml}
      ${t.status === 'Completed' ? UI.h('Arrival checklist') + TripUI.arrivalHtml(t, ins, arr) : ''}
      ${r.incidents.length ? UI.h('Incidents') + r.incidents.map(i => `<div class="border rounded p-2 mb-2 small"><div class="d-flex justify-content-between"><b>${UI.esc(i.incident_ref)} · ${UI.esc(i.incident_type)}</b>${UI.badge(i.status)}</div>
          ${UI.esc(i.problem)} · ${UI.esc(i.location)} · ${UI.datetime(i.incident_at)}${i.recommendation ? '<br>Recommendation: ' + UI.esc(i.recommendation) : ''}${i.job_ref ? '<br>Maintenance record: ' + UI.esc(i.job_ref) + (i.job_status ? ' (' + UI.esc(i.job_status) + ')' : '') : ''}${i.severity ? '<br>Damage classification: ' + UI.esc(i.severity) : ''}${i.clearance ? '<br>Motorpool clearance: <b>' + UI.esc(i.clearance) + '</b> · ' + UI.esc(i.cleared_by_name || '') + ' · ' + UI.datetime(i.cleared_at) + '<br><span class="text-muted">' + UI.esc(i.clearance_findings || '') + '</span>' : ''}${i.resumed_at ? '<br>Resumed by ' + UI.esc(i.resumed_by_name || '') + ' · ' + UI.datetime(i.resumed_at) : ''}
          <div><button class="btn btn-link btn-sm p-0" data-printinc="${i.id}">Print Incident Report Form</button></div></div>`).join('') : ''}
      ${t.status === 'Completed' ? UI.h('Completion') + UI.kv([['Completed', UI.datetime(t.completed_at)], ['Waybill no.', UI.e(t.waybill_number)], ['Notes', UI.e(t.delivery_notes)],
          r.billing ? ['Billing', UI.badge(r.billing.status) + ' ' + UI.money(r.billing.total_amount)] : null]) : ''}
      ${UI.h('Updates and delays')}${r.updates.length ? `<ul class="timeline">${r.updates.map(u => `<li><span class="when">${UI.datetime(u.created_at)} · ${UI.esc(u.actor)}</span><br><b>${UI.esc(u.update_type)}</b>${u.delay_cause ? ' (' + UI.esc(u.delay_cause) + ')' : ''}: ${UI.esc(u.note)}</li>`).join('')}</ul>` : '<div class="text-muted small">No updates.</div>'}
      ${UI.h('Documents')}${UI.tripDocuments(r.documents)}${UI.docs(r.documents)}
      ${UI.h('History')}${UI.activity(r.events)}`;
    const el = UI.drawer('Trip ' + UI.esc(t.trip_ref), html, { subtitle: UI.esc(t.client) });
    const headings = [...el.querySelectorAll('.drawer-h')];
    const jump = document.createElement('nav'); jump.className = 'd-flex gap-1 flex-wrap my-3'; jump.setAttribute('aria-label', 'Trip detail sections');
    headings.forEach(h => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm btn-outline-secondary';
      b.textContent = h.querySelector('span').textContent;
      b.onclick = () => { h.tabIndex = -1; h.focus({ preventScroll: true }); h.scrollIntoView({ block: 'start' }); };
      jump.append(b);
    });
    headings[0]?.before(jump);
    UI.routeMap(el.querySelector('[data-routemap]'), t, t.route_geometry && t.pickup_lat && t.delivery_lat ? { a: [+t.pickup_lat, +t.pickup_lng], b: [+t.delivery_lat, +t.delivery_lng],
      aLabel: 'Pickup: ' + t.pickup_point, bLabel: 'Destination: ' + t.delivery_point, geometry: t.route_geometry } : null);
    el.querySelectorAll('[data-printinc]').forEach(b => b.onclick = () => TripUI.printIncident(r.incidents.find(i => String(i.id) === b.dataset.printinc), t));
    el.querySelectorAll('[data-tact]').forEach(b => b.onclick = () => {
      const a = b.dataset.tact;
      if (a === 'review') TripUI.review(t);
      if (a === 'reassign') TripUI.reassign(t);
      if (a === 'edit') TripUI.edit(t);
      if (a === 'cancel') UI.confirmAction('Cancel ' + UI.esc(t.trip_ref), 'The truck and crew are freed. A released fund must be liquidated by Finance.', 'Trips.cancel', { trip_id: t.id }, { danger: true, submit: 'Cancel trip', fields: [{ name: 'reason', label: 'Reason', required: true, col: 12 }] });
      if (a === 'fund') { const old = r.funds.find(f => +f.attempt_number === +t.attempt_number);
        UI.fundForm({ title: 'Fund request for ' + t.trip_ref, categories: TripUI.data.fundCategories, action: 'Funds.submit', payload: { trip_id: t.id },
          selected: old ? old.lines.filter(l => +l.is_preset).map(l => l.category_name) : [], extra: old ? old.lines.filter(l => !+l.is_preset).map(l => ({ name: l.category_name, amount: l.amount })) : [],
          intro: old && old.rejected_reason ? 'Rejected because: ' + UI.esc(old.rejected_reason) + '. Revise and resubmit.' : 'Choose the applicable expenses. The system takes the preset amounts and computes the total.',
          after: () => { TripUI.open(t.id); App.route(); } }); }
      if (a === 'checklist') TripUI.checklist(t);
      if (a === 'arrival') TripUI.checklist(t, 'Arrival', ins || null);
      if (a === 'dispatch') UI.confirmAction((+t.attempt_number > 1 ? 'Confirm Redispatch ' : 'Confirm Dispatch ') + UI.esc(t.trip_ref), 'Confirm only when the truck is actually leaving. The billing draft is generated now.', 'Trips.dispatch', { trip_id: t.id }, { submit: 'Confirm' });
      if (a === 'progress') UI.formModal('Record Progress', [{name:'update_type',label:'Update Type',type:'select',required:true,options:UI.list(TripUI.data.updateTypes||['Progress','Loading','Unloading','Delivery','Delay','Other'])},{name:'delay_cause',label:'Delay Cause (for Delay)',type:'select',options:UI.list(TripUI.data.delayCauses||['Client','Pending Documents','Traffic','Weather','Operational','Other'])},{name:'note',label:'Recorded Progress',type:'textarea',required:true}], 'Trips.progress', {extra:{trip_id:t.id},after:()=>TripUI.open(t.id)});
      if (a === 'complete') TripUI.complete(t);
      if (a === 'dieselpo') UI.formModalFiles('Diesel PO for ' + UI.esc(t.trip_ref), [
        { name: 'diesel_po', label: 'Diesel PO file(s)', type: 'file', multiple: true, required: true, col: 12, attrs: 'accept="application/pdf,image/jpeg,image/png,image/webp"', help: 'PDF, JPG, PNG or WebP, up to 10 MB each.' }],
        'Trips.diesel_po', { extra: { trip_id: t.id }, intro: 'Optional. The Diesel PO is kept with the trip documents (Finance can view it).', after: () => { TripUI.open(t.id); App.route(); } });
      if (a === 'incident') TripUI.incident(t);
      if (a === 'increschedule') UI.formModal('For Reschedule · ' + UI.esc(t.trip_ref), [{ name: 'reason', label: 'Reason (Operations decision)', type: 'textarea', required: true, col: 12 }],
        'Trips.incident_reschedule', { extra: { trip_id: t.id }, submit: 'Move to For Reschedule', after: () => { TripUI.open(t.id); App.route(); },
          intro: 'Motorpool recorded <b>Not Fit to Continue</b> for this Minor incident. The classification and history are kept; the dispatcher reschedules after the repair is verified, or selects another eligible fixed pair.' });
      if (a === 'resume') UI.confirmAction('Resume ' + UI.esc(t.trip_ref), 'The trip continues (In Transit). This is not a second attempt.', 'Trips.resume', { trip_id: t.id }, { submit: 'Resume trip' });
      if (a === 'reschedule') UI.formModal('Reschedule ' + UI.esc(t.trip_ref), [{ name: 'scheduled_at', label: 'New dispatch schedule', type: 'datetime', required: true, col: 12, attrs: `min="${UI.nowManila()}" max="${UI.nowManila(365 * 1440)}"`, help: 'Present or future date and time (Philippine time).' }], 'Trips.reschedule',
        { extra: { trip_id: t.id }, intro: 'After rescheduling, do the pre-dispatch checklist again. Confirm Redispatch records the Second Attempt.', after: () => { TripUI.open(t.id); App.route(); } });
      if (a === 'printfund') FundUI.print(fund.id);
    });
    if (!ops && !UI.isDispatcher()) UI.drawerSections(el,[['overview','Overview',['Trip']],['funds','Trip Funds & Fuel',['Fund request']],['pre','Pre-Dispatch',['Pre-dispatch checklist']],['progress','Dispatch & Progress',['Updates and delays','History']],['incidents','Incidents & Maintenance',['Incidents']],['arrival','Arrival & Completion',['Arrival checklist','Completion','Documents']]]);
    if (ops && typeof OpsHead !== 'undefined') OpsHead.decorateTrip(el, r);
    if (UI.isDispatcher() && typeof DispatcherUI !== 'undefined') DispatcherUI.decorateTrip(el, r);
  },
};

App.page('trips', {
  title: 'Trip Management',
  subtitle: 'Create trips, follow their progress and do the next step. Click a trip for details.',
  render(ctx) {
    const d = TripUI.data = ctx.data;
    if (ctx.query.new && d.can.create) return TripUI.newPage(ctx);
    if (ctx.query.batch && d.can.create) return TripUI.newPage(ctx);
    if (d.can.create) {
      const b = document.createElement('button'); b.className = 'btn btn-sm btn-primary';
      b.innerHTML = '<i class="bi bi-plus-lg"></i> New Trip'; b.onclick = () => App.go('trips', { new: 1 }); ctx.tools.append(b);
    }
    const counts = (s) => d.active.filter(t => s.includes(t.status)).length;
    ctx.el.innerHTML = UI.cards([
      { label: 'Waiting (review / resources)', value: counts(['Pending Review', 'Awaiting Resources']), icon: 'bi-hourglass-split', color: 'amber' },
      { label: 'Preparing (fund & checklist)', value: counts(['Pre-Assigned', 'Ready for Dispatch']), icon: 'bi-ui-checks', color: 'blue' },
      { label: 'On the road / returning', value: counts(['Dispatched', 'In Transit']) + d.active.filter(t => +t.awaiting_arrival).length, icon: 'bi-truck', color: 'green' },
      { label: 'Problems (incident / reschedule)', value: counts(['On Hold - Incident Reported', 'For Reschedule']), icon: 'bi-exclamation-triangle', color: 'red' },
    ]);
    const filterFields = [
      { name: 'from', label: 'Schedule from', type: 'date', col: 6 }, { name: 'to', label: 'Schedule to', type: 'date', col: 6 },
      { name: 'q', label: 'Search (trip no., place, waybill, booking)', col: 12 },
      { name: 'status', label: 'Status', type: 'select', options: UI.list(['Completed', 'Cancelled', 'Rejected']), col: 6 },
      { name: 'truck_type', label: 'Truck type', type: 'select', options: UI.list(App.me.truckTypes), col: 6 },
      { name: 'client_id', label: 'Client', type: 'select', options: UI.opts(d.filterOptions.clients, c => c.company_name), col: 6 },
      { name: 'vehicle_id', label: 'Truck', type: 'select', options: UI.opts(d.filterOptions.trucks, v => v.plate_number), col: 6 },
      { name: 'person_id', label: 'Driver / helper', type: 'select', options: UI.opts(d.filterOptions.drivers, p => p.full_name + ' (' + p.person_type + ')'), col: 6 },
      { name: 'dispatcher_id', label: 'Dispatcher', type: 'select', options: UI.opts(d.filterOptions.dispatchers, u => u.full_name), col: 6 },
    ];
    const tabs = [
      ['active', `Active trips (${d.active.length})`, (body) => {
        const prefKey = 'rpm:trip-view:' + String(App.me?.user?.id ?? App.me?.user?.role ?? 'account');
        // FLEETOPS_LIST_FIRST_UI_2026_11: default list; old saved card preference deliberately reset once.
        const versionKey = prefKey + ':list-default-v1';
        let saved = 'list';
        try {
          if (localStorage.getItem(versionKey) === '1') saved = localStorage.getItem(prefKey) === 'cards' ? 'cards' : 'list';
          else { localStorage.setItem(prefKey, 'list'); localStorage.setItem(versionKey, '1'); }
        } catch (_) { /* private browsing: default to list */ }
        let mode = saved, page = 0, pageSize = 12;
        body.innerHTML = `<div class="board-tools rpm-trip-tools">
          <div class="table-search"><i class="bi bi-search"></i><input class="form-control form-control-sm" type="search" placeholder="Search trip no., client, place, truck…" id="tbSearch" aria-label="Search active trips"></div>
          <select class="form-select form-select-sm ops-filter" id="tbStatus" aria-label="Filter active trips by status"><option value="">All statuses</option>${[...new Set(d.active.map(t => t.status))].sort().map(s => `<option>${UI.esc(s)}</option>`).join('')}</select>
          <select class="form-select form-select-sm ops-filter" id="tbType" aria-label="Filter active trips by truck type"><option value="">All truck types</option>${TripUI.truckTypeOptions().map(s => `<option>${UI.esc(s)}</option>`).join('')}</select>
          ${['operations_head', 'car_carrier_dispatcher', 'container_wingvan_dispatcher', 'finance', 'motorpool'].includes(App.me.user.role) ? '<div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="tbMine"><label class="form-check-label small" for="tbMine">Only trips waiting for me</label></div>' : ''}
          <div class="rpm-trip-view-switch" role="group" aria-label="Trip display layout">
            <button type="button" class="rpm-trip-view-btn" data-trip-view="cards" aria-label="Grid layout" title="Grid layout" aria-pressed="false"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i></button>
            <button type="button" class="rpm-trip-view-btn" data-trip-view="list" aria-label="List layout" title="List layout" aria-pressed="false"><i class="bi bi-list-ul" aria-hidden="true"></i></button>
          </div>
          <label class="rpm-trip-per-page" for="tbPageSize">Per page <select class="form-select form-select-sm" id="tbPageSize" aria-label="Trips per page"><option value="12">12</option><option value="24">24</option><option value="48">48</option></select></label>
          <span class="small text-muted rpm-trip-count" id="tbCount" aria-live="polite"></span>
        </div>
        <div id="tbBoard" class="trip-board"></div>
        <nav class="rpm-trip-pagination" id="tbPager" aria-label="Active trip pages"></nav>`;
        const search = body.querySelector('#tbSearch'), mine = body.querySelector('#tbMine');
        const board = body.querySelector('#tbBoard'), pager = body.querySelector('#tbPager');
        const viewButtons = body.querySelectorAll('[data-trip-view]');
        const emptyText = d.active.length ? 'No active trip matches the search.' : 'No active trips.' + (d.can.create ? ' Use “New trip” to encode a client delivery.' : '');
        const draw = () => {
          const q = (search.value || '').trim().toLowerCase(), mineOnly = !!mine?.checked;
          const status = body.querySelector('#tbStatus').value, type = body.querySelector('#tbType').value;
          const rows = d.active.filter(t => (!q || [t.trip_ref, t.client, t.pickup_point, t.delivery_point, t.plate_number, t.driver_name, t.status, t.consignee].join(' ').toLowerCase().includes(q)) && (!mineOnly || TripUI.needsMe(t)) && (!status || t.status === status) && (!type || t.truck_type === type))
            .sort((a, b) => Number(TripUI.needsMe(b)) - Number(TripUI.needsMe(a)) || Number(TripUI.isDelayed(b)) - Number(TripUI.isDelayed(a)) || String(a.scheduled_at).localeCompare(String(b.scheduled_at)));
          const totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
          page = Math.max(0, Math.min(page, totalPages - 1));
          const from = page * pageSize, shown = rows.slice(from, from + pageSize);
          body.querySelector('#tbCount').textContent = rows.length ? `${from + 1}–${from + shown.length} of ${rows.length} trip(s)` : '0 trip(s)';
          viewButtons.forEach(b => { const active = b.dataset.tripView === mode; b.classList.toggle('active', active); b.setAttribute('aria-pressed', String(active)); });
          board.classList.toggle('trip-board', mode === 'cards');
          board.classList.toggle('rpm-trip-list', mode === 'list');
          if (!rows.length) {
            board.innerHTML = `<div class="panel rpm-trip-empty"><div class="table-empty"><i class="bi bi-signpost-split"></i><span>${emptyText}</span></div></div>`;
          } else if (mode === 'cards') {
            board.innerHTML = shown.map(TripUI.card).join('');
          } else {
            board.innerHTML = `<div class="table-wrap rpm-trip-table-wrap"><table class="data-table rpm-trip-table"><thead><tr>
              <th>Trip / schedule</th><th>Client</th><th>Route</th><th>Truck / Crew</th><th>Status / next step</th><th class="actions-col">Action</th>
              </tr></thead><tbody>${shown.map(t => `<tr>
                <td><strong>${UI.esc(t.trip_ref)}</strong><div class="cell-sub">${UI.esc(t.truck_type || '')}</div><div class="cell-sub">${UI.datetime(t.scheduled_at)}</div></td>
                <td>${UI.esc(t.client)}${t.consignee ? `<div class="cell-sub">${UI.esc(t.consignee)}</div>` : ''}</td>
                <td><div class="rpm-trip-route" title="${UI.esc([t.pickup_point, t.delivery_point].filter(Boolean).join(' → '))}">${UI.esc(t.pickup_point || '—')} <i class="bi bi-arrow-right" aria-hidden="true"></i> ${UI.esc(t.delivery_point || '—')}</div></td>
                <td>${UI.esc(t.plate_number || 'Not assigned')}${t.driver_name ? `<div class="cell-sub">${UI.esc(t.driver_name)}</div>` : ''}</td>
                <td>${UI.badge(t.status)}${TripUI.flags(t) ? `<div class="rpm-trip-list-flags">${TripUI.flags(t)}</div>` : ''}<div class="cell-sub ops-next-copy">${UI.esc(TripUI.next(t))}</div></td>
                <td class="actions-col"><button type="button" class="btn btn-sm btn-outline-success table-view-btn" data-trip="${UI.esc(t.id)}"><i class="bi bi-eye" aria-hidden="true"></i> View</button></td>
              </tr>`).join('')}</tbody></table></div>`;
          }
          board.querySelectorAll('[data-trip]').forEach(el => { el.onclick = () => TripUI.open(el.dataset.trip); });
          pager.innerHTML = rows.length > pageSize ? `<div class="rpm-trip-page-info">Page ${page + 1} of ${totalPages}</div><div class="rpm-trip-page-buttons">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-page="prev" ${page === 0 ? 'disabled' : ''}>Previous</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-page="next" ${page >= totalPages - 1 ? 'disabled' : ''}>Next</button></div>` : '';
          pager.querySelectorAll('[data-page]').forEach(b => { b.onclick = () => { page += b.dataset.page === 'next' ? 1 : -1; draw(); }; });
        };
        search.addEventListener('input', () => { page = 0; draw(); });
        mine?.addEventListener('change', () => { page = 0; draw(); });
        ['#tbStatus', '#tbType'].forEach(s => body.querySelector(s).addEventListener('change', () => { page = 0; draw(); }));
        body.querySelector('#tbPageSize').addEventListener('change', e => { pageSize = Number(e.target.value) || 12; page = 0; draw(); });
        viewButtons.forEach(b => { b.addEventListener('click', () => {
          mode = b.dataset.tripView; page = 0;
          try { localStorage.setItem(prefKey, mode); } catch (_) { /* local preference is optional */ }
          draw();
        }); });
        draw();
      }],
      ['history', 'Trip History', (body) => {
        body.innerHTML = (UI.filterModal(ctx, filterFields, { note: 'Filters apply to completed, cancelled and rejected trips.' }) || '') + UI.table([
          ['Trip', 'trip_ref'], ['Status', 'status', 'badge'], ['Client', 'client'], ['Truck type', 'truck_type'], ['Truck', 'plate_number'], ['Driver', 'driver_name'], ['Waybill', 'waybill_number'],
          ['Scheduled date and time', 'scheduled_at', 'datetime'], ['Completion date and time', 'completed_at', 'datetime'], ['Billing', 'billing_status', 'badge']],
          d.history, { title: 'Trip records', actions: () => UI.btn('view', 'View', 'outline-primary'), onAction: (a, t) => TripUI.open(t.id), empty: 'No trips match.' });
      }],
      ['incidents', `Incidents (${d.incidents.filter(i => i.status !== 'Resolved').length} open)`, (body) => {
        body.innerHTML = `<div class="board-tools"><label for="incidentStatus" class="small">Incident status</label><select id="incidentStatus" class="form-select form-select-sm ops-filter"><option value="">All incidents</option><option value="open">Open incidents</option>${[...new Set(d.incidents.map(i => i.status))].sort().map(s => `<option>${UI.esc(s)}</option>`).join('')}</select></div><div data-incident-table></div>`;
        const draw = () => {
          const status = body.querySelector('#incidentStatus').value;
          const rows = d.incidents.filter(i => !status || (status === 'open' ? i.status !== 'Resolved' : i.status === status))
            .slice().sort((a, b) => Number(a.status === 'Resolved') - Number(b.status === 'Resolved') || String(b.incident_at).localeCompare(String(a.incident_at)));
          const table = body.querySelector('[data-incident-table]');
          table.innerHTML = UI.table([['Incident', 'incident_ref'], ['Incident date and time', 'incident_at', 'datetime'], ['Trip', 'trip_ref'], ['Truck', 'plate_number'], ['Type', 'incident_type'], ['Problem', 'problem'],
            ['Location', 'location'], ['Status', 'status', 'badge']], rows,
            { title: 'Incident reports', actions: () => UI.btn('trip', 'View trip') + UI.btn('print', 'Print report', 'outline-secondary'),
              onAction: (a, i) => a === 'trip' ? TripUI.open(i.trip_id) : TripUI.printIncident(i, {}), empty: 'No incidents match this status.' });
          UI.activate(table);
        };
        body.querySelector('#incidentStatus').onchange = draw; draw();
      }],
    ];
    const box = document.createElement('div'); ctx.el.append(box);
    const selectedTab = ['active', 'history', 'incidents'].includes(ctx.query.tab) ? ctx.query.tab : (ctx.query.q || ctx.query.status || ctx.query.from ? 'history' : 'active');
    UI.tabs(box, tabs, selectedTab, { forceActive: true });
    box.querySelectorAll('[data-tab]').forEach(b => b.onclick = () => App.go('trips', { ...ctx.query, tab: b.dataset.tab }));
    const once = UI.takeQuery(ctx, 'open');
    if (once.open) TripUI.open(once.open);
  },
});
