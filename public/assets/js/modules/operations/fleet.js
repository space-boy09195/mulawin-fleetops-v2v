/* assets/js/modules/operations/fleet.js — TRUCK MASTERLIST and DRIVER & HELPER MASTERLIST
   The only place where trucks, drivers and helpers are added or edited. Every other
   module (trips, maintenance, purchasing, reports) points to these records.
   Backend: backend/Modules/Operations/Fleet.php */
'use strict';
const FleetUI = {
  // Checklist #13/#14: prominent type tabs; no change to server-side filters.
  masterlistTypeTabs(page, param, options, current) {
    return `<div class="btn-group btn-group-sm flex-wrap" role="group" aria-label="${page === 'trucks' ? 'Truck types' : 'Personnel types'}">
      ${options.map(([value, label]) => `<button type="button" class="btn ${String(current || '') === String(value) ? 'btn-primary' : 'btn-outline-secondary'}" data-masterlist-tab="${UI.esc(page)}" data-param="${UI.esc(param)}" data-value="${UI.esc(value)}" aria-pressed="${String(current || '') === String(value) ? 'true' : 'false'}">${UI.esc(label)}</button>`).join('')}
    </div>`;
  },
  bindMasterlistTypeTabs(ctx) {
    ctx.el.querySelectorAll('[data-masterlist-tab]').forEach(button => {
      button.addEventListener('click', () => {
        const key = button.dataset.param;
        const value = button.dataset.value;
        const next = { ...ctx.query };
        if (value) next[key] = value;
        else delete next[key];
        App.go(ctx.page, next);
      });
    });
  },
  docBadge(days, date) {
    if (!date) return '<span class="text-muted">—</span>';
    return UI.date(date) + ' ' + UI.days(days);
  },
  truckRows(d,q) {
    return d.trucks.filter(v=>(q.inactive?true:+v.active)&&(!q.truck_type||v.truck_type===q.truck_type)&&(!q.status||v.status===q.status)&&(!q.docs||(q.docs==='expiring'&&((v.reg_days!==null&&+v.reg_days<=+d.alertDays)||(v.ins_days!==null&&+v.ins_days<=+d.alertDays)))));
  },
  truckForm(v, d) {
    const truckInfo = [
      { name: 'plate_number', label: 'Plate Number', required: true, col: 6, value: v.plate_number, placeholder: 'NAJ5941' },
      { name: 'truck_type', label: 'Truck Type', type: 'select', options: UI.list(App.me.truckTypes), required: true, col: 6, value: v.truck_type },
      { name: 'truck_number', label: 'Unit Number', col: 6, value: v.truck_number, placeholder: 'e.g. W2' },
      { name: 'make_brand', label: 'Make / Brand', col: 6, value: v.make_brand },
      { name: 'model', label: 'Model', col: 6, value: v.model },
    ];
    const fixedCrew = [
      { name: 'assigned_driver_id', label: 'Fixed Driver', type: 'select', options: UI.opts(d.drivers, p => p.full_name), col: 6, value: v.assigned_driver_id, help: 'Regular pairing used during dispatch.' },
      { name: 'assigned_helper_id', label: 'Regular Helper', type: 'select', options: UI.opts(d.helpers, p => p.full_name), col: 6, value: v.assigned_helper_id },
    ];
    const documents = [
      { name: 'mv_file_number', label: 'MV File No.', col: 6, value: v.mv_file_number },
      { name: 'chassis_number', label: 'Chassis No.', col: 6, value: v.chassis_number },
      { name: 'engine_number', label: 'Engine No.', col: 6, value: v.engine_number },
      { name: 'or_number', label: 'OR No.', col: 6, value: v.or_number },
      { name: 'cr_number', label: 'CR No.', col: 6, value: v.cr_number },
      { name: 'registration_expiry', label: 'Registration Expiry', type: 'date', col: 6, value: v.registration_expiry },
      { name: 'insurance_provider', label: 'Insurance Provider', col: 6, value: v.insurance_provider },
      { name: 'insurance_policy_number', label: 'Policy No.', col: 6, value: v.insurance_policy_number },
      { name: 'insurance_expiry', label: 'Insurance Expiry', type: 'date', col: 6, value: v.insurance_expiry },
      { name: 'notes', label: 'Notes', type: 'textarea', col: 12, value: v.notes },
    ];
    const groups = [
      FormComponents.section('Truck Information', 'bi-truck', truckInfo),
      FormComponents.section('Fixed Crew Assignment', 'bi-people', fixedCrew),
      FormComponents.collapsible('Registration & Insurance', 'Vehicle documents, insurance and notes', 'bi-file-earmark-text', documents),
    ];
    return FormComponents.modal(
      v.id ? 'Edit Truck ' + UI.esc(v.plate_number) : 'Add Truck',
      groups, 'Fleet.truck_save', {
        extra: v.id ? { id: v.id } : {},
        intro: 'OR/CR and insurance files can be attached later in Truck Details → Documents.',
        submit: v.id ? 'Save Changes' : 'Save Truck',
        after: v.id ? () => FleetUI.truckDetails(v.id, d) : undefined,
      }
    );
  },
  async truckDetails(id, d) {
    let r; try { r = await App.api('Fleet.truck_details', { id }); } catch (e) { return UI.toast(e.message, 'error'); }
    const v = r.truck;
    const canEdit = d.can.edit, canStatus = d.can.status;
    const acts = [];
    if (canEdit) acts.push(`<button class="btn btn-sm btn-outline-primary" data-a="edit"><i class="bi bi-pencil"></i> Edit</button>`);
    if (canStatus && +v.active) {
      if (v.status === 'Unavailable') acts.push(`<button class="btn btn-sm btn-success" data-a="avail"><i class="bi bi-check-circle"></i> Set Available</button>`);
      if (['Available', 'Assigned'].includes(v.status)) acts.push(`<button class="btn btn-sm btn-outline-danger" data-a="unavail"><i class="bi bi-slash-circle"></i> Set Unavailable</button>`);
      if (UI.role('motorpool') && ['Available', 'Assigned', 'Unavailable'].includes(v.status) && !r.openJob) acts.push(`<button class="btn btn-sm btn-outline-danger" data-a="maint"><i class="bi bi-wrench-adjustable"></i> Report problem</button>`);
    }
    if (canEdit) acts.push(`<button class="btn btn-sm btn-outline-secondary" data-a="docs"><i class="bi bi-paperclip"></i> Attach OR/CR / insurance</button>`,
      `<button class="btn btn-sm btn-outline-${+v.active ? 'danger' : 'success'}" data-a="active">${+v.active ? 'Deactivate' : 'Reactivate'}</button>`);
    const html = `<div class="d-flex gap-2 align-items-center mb-2">${UI.badge(v.status)}${+v.active ? '' : ' <span class="badge-s red">Inactive</span>'}${v.status_reason ? `<span class="small text-muted">${UI.esc(v.status_reason)}</span>` : ''}</div>
      ${acts.length ? `<div class="drawer-actions">${acts.join('')}</div>` : ''}
      ${UI.h('Truck')}${UI.kv([['Plate / unit', `<b>${UI.esc(v.plate_number)}</b>${v.truck_number ? ' #' + UI.esc(v.truck_number) : ''}`], [UI.role('operations_head')?'Truck Type':'Type', UI.e(v.truck_type)], ['Make / model', UI.esc([v.make_brand, v.model].filter(Boolean).join(' ') || '—')],
        ['Chassis / engine', UI.esc([v.chassis_number, v.engine_number].filter(Boolean).join(' / ') || '—')], ['Regular driver', UI.e(v.driver_name)], ['Regular helper', UI.e(v.helper_name)],
        ['Notes', UI.e(v.notes)]])}
      ${r.openJob ? `<div class="next-step warn"><i class="bi bi-wrench-adjustable"></i> Under corrective maintenance: <a href="#maintenance?open=${r.openJob.id}">${UI.esc(r.openJob.job_ref)}</a> (${UI.esc(r.openJob.status === 'Completed' ? 'repair done, waiting for verification' : r.openJob.status)}). The truck cannot be assigned until the repair is verified.</div>` : ''}
      ${UI.h('Vehicle documents')}${UI.kv([['MV file no.', UI.e(v.mv_file_number)], ['OR / CR no.', UI.esc([v.or_number, v.cr_number].filter(Boolean).join(' / ') || '—')],
        ['Registration expiry', UI.date(v.registration_expiry)], ['Insurance', UI.esc([v.insurance_provider, v.insurance_policy_number].filter(Boolean).join(' · ') || '—')], ['Insurance expiry', UI.date(v.insurance_expiry)]])}
      <div class="mt-2">${UI.docs(r.documents, 'No OR/CR or insurance files attached yet.')}</div>
      ${UI.h('Trips (latest 50)')}${UI.table([['Trip', 'trip_ref'], ['Status', 'status', 'badge'], ['Schedule', 'scheduled_at', 'datetime'], ['Client', 'client'], ['Driver', 'driver_name'], ['Route', 'delivery_point']], r.trips, { plain: true, empty: 'No trips yet.' })}
      ${UI.h('Maintenance history')}${UI.table([['Record', 'job_ref'], ['Source', 'source'], ['Problem', 'problem'], ['Mechanic', 'mechanic'], ['Status', 'status', 'badge'], ['Reported', 'reported_on', 'date'], ['Completed', 'completed_on', 'date'], ['Cost', 'cost', 'money']], r.maintenance,
        { plain: true, empty: 'No maintenance records.', rowClick: (m) => { UI.closeDrawer(); App.go('maintenance', { open: m.id }); } })}
      ${UI.h('Status history')}<ul class="timeline">${r.statusLog.map(l => `<li><span class="when">${UI.datetime(l.created_at)} · ${UI.esc(l.actor || 'System')}</span><br>${UI.esc(l.from_status || 'new')} → <b>${UI.esc(l.to_status)}</b>${l.reason ? ' · ' + UI.esc(l.reason) : ''}</li>`).join('') || '<li>No changes yet.</li>'}</ul>`;
    const el = UI.drawer('Truck ' + UI.esc(v.plate_number), html, { subtitle: UI.esc(v.truck_type) });
    el.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
      const a = b.dataset.a;
      if (a === 'edit') FleetUI.truckForm(v, d);
      if (a === 'avail') UI.confirmAction('Set ' + UI.esc(v.plate_number) + ' Available', 'The truck can be assigned to trips again.', 'Fleet.truck_status', { id: v.id, status: 'Available' }, { submit: 'Set Available' });
      if (a === 'unavail') UI.confirmAction('Set ' + UI.esc(v.plate_number) + ' Unavailable', 'A pre-assigned trip of this truck is re-assigned automatically (or waits for resources).', 'Fleet.truck_status', { id: v.id, status: 'Unavailable' },
        { danger: true, submit: 'Set Unavailable', fields: [{ name: 'reason', label: 'Reason (e.g. registration renewal)', required: true, col: 12 }] });
      if (a === 'maint') { UI.closeDrawer(); App.go('maintenance', { report: v.id }); }
      if (a === 'docs') UI.modal('Attach files to ' + UI.esc(v.plate_number), UI.fields([
          { name: 'category', label: 'Document', type: 'select', options: UI.list(['OR/CR', 'Insurance', 'Permit / Pass', 'Other']), required: true, col: 6 },
          { name: 'expires_on', label: 'Expiry date (for reminders)', type: 'date', col: 6 },
          { name: 'files', label: 'Files (PDF / photo)', type: 'file', multiple: true, required: true, col: 12, attrs: 'accept="application/pdf,image/*"' }]),
        async (dd, form) => { const fd = new FormData(); fd.append('entity_type', 'vehicle'); fd.append('entity_id', v.id); fd.append('category', dd.category); fd.append('expires_on', dd.expires_on || '');
          [...form.querySelector('[name=files]').files].forEach(f => fd.append('files[]', f)); const res = await App.upload(fd); UI.result(res); FleetUI.truckDetails(v.id, d); }, { size: '', submit: 'Upload' });
      if (a === 'active') UI.confirmAction((+v.active ? 'Deactivate ' : 'Reactivate ') + UI.esc(v.plate_number), +v.active ? 'The truck leaves the masterlist choices. Its past trips and records stay.' : 'The truck returns to the masterlist.', 'Fleet.truck_active', { id: v.id, active: +v.active ? 0 : 1 }, { danger: +v.active });
    });
  },
  crewForm(p, d = {}) {
    if(UI.role('operations_head'))return FleetUI.opsCrewForm(p,d);
    UI.formModal(p.id ? 'Edit ' + UI.esc(p.full_name) : 'Add driver or helper', [
      { name: 'full_name', label: 'Name (LAST NAME, FIRST NAME)', required: true, col: 8, value: p.full_name, placeholder: 'JUEGOS, HECTOR' },
      { name: 'person_type', label: 'Type', type: 'select', options: UI.list(['Driver', 'Helper']), required: true, col: 4, value: p.person_type },
      { name: 'employee_no', label: 'Employee no.', col: 4, value: p.employee_no }, { name: 'contact_number', label: 'Contact number', col: 4, value: p.contact_number },
      { name: 'date_hired', label: 'Employment start date', type: 'date', col: 4, value: p.date_hired },
      { name: 'license_number', label: "Driver's license no. (drivers)", col: 6, value: p.license_number }, { name: 'license_expiry', label: 'Driver’s license expiry date', type: 'date', col: 6, value: p.license_expiry },
      { name: 'address', label: 'Address', col: 12, value: p.address }, { name: 'notes', label: 'Notes', col: 12, value: p.notes }],
      'Fleet.crew_save', { extra: p.id ? { id: p.id } : {}, intro: 'Drivers need a license number and expiry. A driver with an expired license is not assigned automatically.' });
  },
  async opsCrewForm(p,d) {
    if(!d.assignmentTrucks||!d.assignmentHelpers){
      try { d=await App.data('crew'); }
      catch(error){ return UI.toast(error.message,'error'); }
    }
    const type=p.person_type==='Helper'?'Helper':'Driver',driver=type==='Driver';
    const trucks=(d.assignmentTrucks||[]).filter(t=>!t.assigned_driver_id||String(t.assigned_driver_id)===String(p.id));
    const identity=[
      {name:'person_type',type:'hidden',value:type},
      {name:'full_name',label:'Full Name (LAST NAME, FIRST NAME)',required:true,col:12,value:p.full_name},
      {name:'employee_no',label:'Employee No.',col:4,value:p.employee_no},
      {name:'contact_number',label:'Contact Number',col:4,value:p.contact_number},
      {name:'date_hired',label:'Start Date',type:'date',col:4,value:p.date_hired},
      ...(!driver ? [
        {name:'license_number',type:'hidden',value:p.license_number},
        {name:'license_expiry',type:'hidden',value:p.license_expiry},
      ] : []),
    ];
    const licensing=driver?[
      {name:'license_number',label:'License Number',required:true,col:6,value:p.license_number},
      {name:'license_expiry',label:'License Expiry',type:'date',required:true,col:6,value:p.license_expiry},
    ]:[];
    const assignment=driver?[
      {name:'fixed_truck_id',label:'Fixed Truck',type:'select',required:true,col:6,value:p.fixed_truck_id,
        options:trucks.map(t=>[t.id,t.plate_number+' - '+t.truck_type])},
      {name:'regular_helper_id',label:'Regular Helper',type:'select',col:6,value:p.regular_helper_id,
        options:(d.assignmentHelpers||[]).map(h=>[h.id,h.full_name])},
    ]:[];
    const additional=[
      {name:'address',label:'Address',col:12,value:p.address},
      {name:'notes',label:'Notes',type:'textarea',rows:2,col:12,value:p.notes},
    ];
    const sections=[
      FormComponents.section('Personal Information','bi-person-badge',identity),
      ...(driver?[FormComponents.section('License & Assignment','bi-card-checklist',[...licensing,...assignment])]:[]),
      FormComponents.collapsible('Additional Information','Address and notes','bi-file-earmark-text',additional),
    ];
    return FormComponents.modal((p.id?'Edit ':'Add ')+type,sections,'Fleet.crew_save',{
      extra:p.id?{id:p.id}:{},
      submit:'Save '+type,
      compact:true,
      compactSize:driver?'normal':'small',
      after(){UI.closeDrawer();App.route();},
      onShow(wrap,form){
        if(!driver)return;
        const pick=form.querySelector('[name=fixed_truck_id]'),
          helper=form.querySelector('[name=regular_helper_id]');
        const refresh=()=>{
          const truck=trucks.find(t=>String(t.id)===pick.value),cc=truck?.truck_type==='Car Carrier';
          helper.closest('[class*=col-]').hidden=cc;
          helper.disabled=cc;
          helper.required=!cc;
          if(cc)helper.value='';
          else if(truck?.assigned_helper_id&&!helper.value)helper.value=truck.assigned_helper_id;
        };
        pick.onchange=refresh;refresh();
      },
    });
  },
  async crewDetails(id, d) {
    let r; try { r = await App.api('Fleet.crew_details', { id }); } catch (e) { return UI.toast(e.message, 'error'); }
    const p = r.person;
    const acts = d.can.edit ? [`<button class="btn btn-sm btn-outline-primary" data-a="edit"><i class="bi bi-pencil"></i> Edit</button>`,
      +p.active ? (p.availability === 'Unavailable' ? `<button class="btn btn-sm btn-success" data-a="avail">Set Available</button>` : `<button class="btn btn-sm btn-outline-danger" data-a="unavail">Set Unavailable</button>`) : '',
      `<button class="btn btn-sm btn-outline-${+p.active ? 'danger' : 'success'}" data-a="active">${+p.active ? 'Deactivate' : 'Reactivate'}</button>`] : [];
    const html = `<div class="mb-2">${UI.badge(p.availability)} ${+p.active ? '' : '<span class="badge-s red">Inactive</span>'} ${p.unavailable_reason ? `<span class="small text-muted">${UI.esc(p.unavailable_reason)}</span>` : ''}</div>
      ${acts.length ? `<div class="drawer-actions">${acts.join('')}</div>` : ''}
      ${UI.h(UI.role('operations_head')?'Overview':'Details')}${UI.kv([[UI.role('operations_head')?'Role':'Type', UI.e(p.person_type)], ['Employee no.', UI.e(p.employee_no)], ['Contact', UI.e(p.contact_number)], ['Address', UI.e(p.address)],
        ["License", UI.esc([p.license_number, p.license_expiry ? 'expires ' + UI.date(p.license_expiry) : ''].filter(Boolean).join(' · ') || '—')], ['Date hired', UI.date(p.date_hired)], ['Notes', UI.e(p.notes)]])}
      ${UI.h('Trip history')}${UI.table([['Trip', 'trip_ref'], ['As', 'role_on_trip'], ['Status', 'status', 'badge'], ['Schedule', 'scheduled_at', 'datetime'], ['Truck', 'plate_number'], ['Client', 'client'], ['To', 'delivery_point']], r.trips, { empty: 'No trips yet.' })}`;
    const el = UI.drawer(UI.esc(p.full_name), html, { subtitle: UI.esc(p.person_type) });
    el.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
      const a = b.dataset.a;
      if (a === 'edit') FleetUI.crewForm(p,d);
      if (a === 'avail') UI.confirmAction('Set ' + UI.esc(p.full_name) + ' Available', 'This person can be assigned to trips again.', 'Fleet.crew_status', { id: p.id, availability: 'Available' });
      if (a === 'unavail') UI.confirmAction('Set ' + UI.esc(p.full_name) + ' Unavailable', 'An affected trip returns to manual Dispatcher assignment.', 'Fleet.crew_status', { id: p.id, availability: 'Unavailable' },
        { danger: true, fields: [{ name: 'reason', label: 'Reason (leave, sick, ...)', required: true, col: 12 }] });
      if (a === 'active') UI.confirmAction((+p.active ? 'Deactivate ' : 'Reactivate ') + UI.esc(p.full_name), +p.active ? 'Past trips keep the name.' : 'The person returns to the masterlist.', 'Fleet.crew_active', { id: p.id, active: +p.active ? 0 : 1 }, { danger: +p.active });
    });
  },
};

App.page('trucks', {
  title: 'Trucks',
  subtitle: 'Fleet records, fixed crew assignments and availability.',
  render(ctx) {
    const d = ctx.data, q = ctx.query;
    const availabilityView = q.tab === 'availability';
    const rows = FleetUI.truckRows(d, { ...q, inactive: availabilityView ? '' : q.inactive });
    const active = d.trucks.filter(v => +v.active);
    const count = status => active.filter(v => v.status === status).length;
    const statuses = ['Available', 'Assigned', 'On Trip', 'Under Maintenance', 'Unavailable'];
    const filterOption = (value, label, selected) => '<option value="' + UI.esc(value) + '"'
      + (String(selected || '') === String(value) ? ' selected' : '') + '>' + UI.esc(label) + '</option>';
    const toolbar = `<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger"
      aria-controls="truck-filter-panel" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
      <button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger"
      aria-controls="truck-sort-panel" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>
      <div class="record-workspace-popovers">
        <div id="truck-filter-panel" class="record-workspace-popover" hidden>
          <strong>Filter Trucks</strong>
          <label>Status<select name="status" class="form-select form-select-sm">
            ${filterOption('', 'All statuses', q.status)}
            ${statuses.map(status => filterOption(status, status, q.status)).join('')}
          </select></label>
          <label>Documents<select name="docs" class="form-select form-select-sm">
            ${filterOption('', 'All documents', q.docs)}
            ${filterOption('expiring', 'Expiring / expired', q.docs)}
          </select></label>
          <label class="record-workspace-check"><input type="checkbox" name="inactive" ${q.inactive ? 'checked' : ''}> Include inactive trucks</label>
          <div class="record-workspace-popover-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button>
            <button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button>
          </div>
        </div>
        <div id="truck-sort-panel" class="record-workspace-popover record-workspace-sort-panel" hidden>
          <strong>Sort Trucks</strong>
          <label class="record-workspace-sort-option"><input type="radio" name="truck_sort" value="default" data-record-sort checked> Default order</label>
          <label class="record-workspace-sort-option"><input type="radio" name="truck_sort" value="plate" data-record-sort> Plate number</label>
          <label class="record-workspace-sort-option"><input type="radio" name="truck_sort" value="type" data-record-sort> Truck type</label>
          <label class="record-workspace-sort-option"><input type="radio" name="truck_sort" value="status" data-record-sort> Status</label>
        </div>
      </div>`
      + (d.can.edit ? '<button type="button" class="btn btn-sm btn-primary record-workspace-add"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Truck</button>' : '');
    const typeTabs = [['', 'All Trucks'], ...(App.me.truckTypes || []).map(t => [t, t])];
    const columns = [
      ['Plate No.', 'plate_number', (v, r) => `<b>${UI.esc(v)}</b>${+r.active ? '' : ' <span class="badge-s red">Inactive</span>'}<div class="cell-sub">${r.truck_number ? 'Unit #' + UI.esc(r.truck_number) : 'No unit no.'}</div>`],
      ['Truck Type', 'truck_type'],
      ['Status', 'status', (v, r) => UI.badge(v) + (r.status_reason && v === 'Unavailable' ? `<div class="cell-sub">${UI.esc(r.status_reason)}</div>` : '')],
      ['Current Assignment', 'trip_ref', (v, r) => v ? `${UI.esc(v)}<div class="cell-sub">${UI.esc(r.trip_status)}</div>` : (r.open_job ? `${UI.esc(r.open_job)}<div class="cell-sub">${UI.esc(r.open_job_status === 'Completed' ? 'waiting for verification' : r.open_job_status)}${r.expected_back ? ' · back ' + UI.date(r.expected_back) : ''}</div>` : '<span class="text-muted">—</span>')],
      ['Fixed Driver', 'driver_name'],
      ['Regular Helper', 'helper_name'],
    ];
    const tableHtml = UI.table(columns, rows, {
      tools: toolbar,
      actions: () => UI.btn('view', 'View'),
      onAction: (action, vehicle) => FleetUI.truckDetails(vehicle.id, d),
      rowClick: vehicle => FleetUI.truckDetails(vehicle.id, d),
      empty: active.length === 0 ? 'No trucks registered yet. Add your first truck to start managing fleet records.' : 'No trucks match these filters. Try clearing your search or filters.',
    });
    ctx.el.innerHTML = `<div class="fleet-trucks-page">
      ${RecordComponents.kpis([
        { label: 'Total Trucks', value: active.length },
        { label: 'Available', value: count('Available'), color: 'green', go: 'trucks?status=Available' },
        { label: 'Assigned / On Trip', value: count('Assigned') + count('On Trip'), color: 'blue' },
        { label: 'Under Maintenance', value: count('Under Maintenance'), color: count('Under Maintenance') ? 'red' : '', go: 'trucks?status=Under%20Maintenance' },
      ])}
      ${RecordComponents.tabs([{value:'master',label:'Master List'},{value:'availability',label:'Availability'}], availabilityView ? 'availability' : 'master','data-truck-view')}
      ${RecordComponents.panel(tableHtml, RecordComponents.categories(typeTabs.map(([value,label]) => ({value,label})),q.truck_type||'','data-truck-type'))}
    </div>`;
    const panel = ctx.el.querySelector('.record-workspace-panel .panel');
    UI.activate(ctx.el);
    RecordComponents.mountPanel(ctx.el, {
      title: 'Truck Records',
      categorySelector: '.record-workspace-categories',
      addSelector: '.record-workspace-add',
    });
    ctx.el.querySelectorAll('[data-truck-view]').forEach(button => button.addEventListener('click', () => {
      const next = { ...ctx.query };
      if (button.dataset.truckView === 'availability') next.tab = 'availability';
      else delete next.tab;
      delete next.inactive;
      App.go(ctx.page, next);
    }));
    ctx.el.querySelectorAll('[data-truck-type]').forEach(button => button.addEventListener('click', () => {
      const next = { ...ctx.query }, value = button.dataset.truckType;
      if (value) next.truck_type = value; else delete next.truck_type;
      App.go(ctx.page, next);
    }));
    ctx.el.querySelector('.record-workspace-add')?.addEventListener('click', () => FleetUI.truckForm({}, d));
    const tableBox = panel?.querySelector('[data-tbody]');
    const tableState = tableBox ? UI._tables[tableBox.dataset.tbody] : null;
    const originalOrder = tableState ? tableState.rows.slice() : [];
    RecordComponents.bindDropdowns(ctx.el, {
      apply(filterPanel) {
        const next = { ...ctx.query };
        const status = filterPanel.querySelector('[name="status"]').value;
        const docs = filterPanel.querySelector('[name="docs"]').value;
        const inactive = filterPanel.querySelector('[name="inactive"]').checked;
        if (status) next.status = status; else delete next.status;
        if (docs) next.docs = docs; else delete next.docs;
        if (inactive) next.inactive = '1'; else delete next.inactive;
        App.go(ctx.page, next);
      },
      reset() {
        const next = { ...ctx.query };
        delete next.status; delete next.docs; delete next.inactive;
        App.go(ctx.page, next);
      },
      sort(key) {
        if (!tableState || !tableBox) return;
        if (key === 'default') tableState.rows = originalOrder.slice();
        else {
          const field = {plate:'plate_number', type:'truck_type', status:'status'}[key];
          if (!field) return;
          tableState.rows = originalOrder.slice().sort((a,b) =>
            String(a[field] || '').localeCompare(String(b[field] || ''), undefined, {numeric:true}));
        }
        tableState.page = 0;
        UI.drawTable(tableBox.dataset.tbody);
      },
    });
    if (q.open) FleetUI.truckDetails(q.open, d);
  },
});

App.page('crew', {
  title: 'Driver & Helper Masterlist',
  subtitle: 'Drivers and helpers: availability, current trip and license expiry.',
  render(ctx) {
    const d = ctx.data, q = ctx.query;
    if (d.can.edit) UI.tool(ctx, 'Add driver or helper', () => FleetUI.crewForm({}), 'bi-plus-lg', 'primary');
    if (App.me.user.role === 'admin') UI.tool(ctx, 'Attendance', () => AttendanceUI.open('Driver'), 'bi-clipboard-check', 'outline-primary');
    const act = d.crew.filter(p => +p.active);
    const rows = d.crew.filter(p => (q.inactive ? true : +p.active) && (!q.person_type || p.person_type === q.person_type) && (!q.availability || p.availability === q.availability)
      && (!q.license || (p.license_days !== null && +p.license_days <= +d.alertDays)));
    const c = (t, a) => act.filter(p => p.person_type === t && (!a || p.availability === a)).length;
    ctx.el.innerHTML = UI.statusStrip([
      { label: 'Drivers', value: c('Driver') },
      { label: 'Drivers available', value: c('Driver', 'Available'), color: 'green' },
      { label: 'Drivers on trip', value: c('Driver', 'On Trip'), color: 'blue' },
      { label: 'Helpers available', value: c('Helper', 'Available') + ' / ' + c('Helper') },
      { label: 'Unavailable', value: act.filter(p => p.availability === 'Unavailable').length, color: act.some(p => p.availability === 'Unavailable') ? 'red' : '' },
    ]) + `<div class="d-flex flex-wrap align-items-center gap-2 mb-3">${FleetUI.masterlistTypeTabs('crew', 'person_type', [['', 'All personnel'], ['Driver', 'Drivers'], ['Helper', 'Helpers']], q.person_type)}` + UI.filterModal(ctx, [
      { name: 'availability', label: 'Availability', type: 'select', options: UI.list(['Available', 'On Trip', 'Unavailable']), col: 6 },
      { name: 'license', label: 'License', type: 'select', options: [['expiring', 'Expiring / expired']], col: 6 },
      { name: 'inactive', label: 'Include inactive drivers and helpers', type: 'checkbox', col: 12 }], { hint: 'Showing all active drivers and helpers.' }) + `</div>` + UI.table([
      ['Name', 'full_name', (v, r) => `<b>${UI.esc(v)}</b>${+r.active ? '' : ' <span class="badge-s red">Inactive</span>'}<div class="cell-sub">${UI.esc(r.employee_no ? 'ID ' + r.employee_no : r.person_type)}</div>`], ['Type', 'person_type'],
      ['Availability', 'availability', (v, r) => UI.badge(v) + (r.unavailable_reason ? `<div class="small text-muted">${UI.esc(r.unavailable_reason)}</div>` : '')],
      ['Current trip', 'trip_ref', (v, r) => v ? `${UI.esc(v)} <span class="small text-muted">${UI.esc(r.trip_plate || '')} · ${UI.esc(r.trip_status)}</span>` : '—'],
      ['License expiry', 'license_expiry', (v, r) => FleetUI.docBadge(r.license_days, v)], ['Completed trips', 'completed_trips', 'num']], rows,
      { actions: () => UI.btn('view', 'Open'), onAction: (a, p) => FleetUI.crewDetails(p.id, d), rowClick: (p) => FleetUI.crewDetails(p.id, d), empty: 'No driver or helper matches the filter.' });
    UI.activate(ctx.el);
    FleetUI.bindMasterlistTypeTabs(ctx);
  },
});
