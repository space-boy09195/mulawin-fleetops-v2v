/* Operations Head presentation. Existing modules and server authorization remain authoritative. */
'use strict';
const OpsHead = {
  is: () => UI.role('operations_head'),
  closed: ['Completed', 'Cancelled', 'Rejected'],
  groups: {
    pending: ['Pending Review'], awaiting: ['Awaiting Resources'], preparing: ['Pre-Assigned', 'Ready for Dispatch'],
    attention: ['Awaiting Resources','On Hold - Incident Reported','For Reschedule'], road: ['Dispatched', 'In Transit'], problems: ['On Hold - Incident Reported', 'For Reschedule'],
  },
  matches(t, group) { return !group || (group === 'arrival' ? !!+t.awaiting_arrival : (this.groups[group] || []).includes(t.status)); },
  datetime(v) {
    if (!v) return '—';
    const d = new Date(/^\d{4}-\d\d-\d\d[ T]\d\d:\d\d(?::\d\d)?$/.test(v) ? v.replace(' ', 'T') + '+08:00' : v);
    return Number.isNaN(+d) ? '—' : new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }).format(d);
  },
  link(page, query = {}) { return '#' + page + (Object.keys(query).length ? '?' + new URLSearchParams(query) : ''); },
  panel(title, html, action = '') { return `<section class="oh-panel"><div class="oh-panel-head"><h2>${UI.operationsLabel(title)}</h2>${action}</div><div class="oh-panel-body">${html}</div></section>`; },
  metrics(items) { return `<div class="oh-metrics">${items.map(([label, count, href]) => `<${href ? 'a' : 'div'} class="oh-metric" ${href ? `href="${UI.esc(href)}"` : ''}><span>${UI.esc(UI.operationsLabel(label))}</span><strong>${count == null ? 'Unavailable' : UI.num(count)}</strong>${href ? '<i class="bi bi-arrow-up-right" aria-hidden="true"></i>' : ''}</${href ? 'a' : 'div'}>`).join('')}</div>`; },
  badge(status) { const color=/Rejected|On Hold|Under Repair/.test(status||'')?'red':UI.badgeColor(status);return status?`<span class="badge-s ${color}">${UI.esc(status)}</span>`:UI.badge(status); },
  bindTrips(el) { el.querySelectorAll('[data-oh-trip]').forEach(b => b.onclick = () => TripUI.open(b.dataset.ohTrip)); },
  search(el, draw, label = 'Search records', fields = []) {
    el.innerHTML = `<form class="oh-record-search" role="search"><div class="oh-record-toolbar"><label class="oh-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" name="search" type="search" aria-label="${UI.esc(label)}" placeholder="${UI.esc(label)}"></label><button type="submit" class="btn btn-primary">Search</button><button type="button" class="btn btn-outline-success" data-record-filters aria-expanded="false">Filters</button></div><div class="oh-record-filter-panel" hidden>${UI.fields(fields.length?fields:[{name:'match',label:'Search within records',placeholder:'Type a matching value'}])}<div class="oh-filter-actions"><button type="button" class="btn btn-primary" data-record-apply>Apply Filters</button><button type="button" class="btn btn-outline-secondary" data-record-clear>Clear Filters</button></div></div></form><div data-list></div>`;
    const form=el.querySelector('form'),input=form.elements.search,list=el.querySelector('[data-list]');let applied={};
    const refresh=()=>{draw((input.value||applied.match||'').trim().toLowerCase(),list,applied);list.querySelectorAll('.table-search').forEach(n=>n.remove());};
    input.oninput=refresh;form.onsubmit=event=>{event.preventDefault();refresh();};
    form.querySelector('[data-record-filters]').onclick=event=>{const panel=form.querySelector('.oh-record-filter-panel');panel.hidden=!panel.hidden;event.currentTarget.setAttribute('aria-expanded',String(!panel.hidden));};
    form.querySelector('[data-record-apply]').onclick=()=>{applied=UI.formData(form);delete applied.search;refresh();};form.querySelector('[data-record-clear]').onclick=()=>{form.reset();applied={};refresh();};refresh();
  },
  compactFilters(form) {
    UI.searchToolbar(form);
    const panel=form.querySelector('.oh-record-filter-panel');if(!panel)return;
    const reset=panel.querySelector('[data-reset-filters]');
    if(reset)reset.replaceChildren(document.createTextNode('Clear Filters'));
    else{const clear=document.createElement('button');clear.type='button';clear.className='btn btn-outline-secondary';clear.textContent='Clear Filters';clear.dataset.resetFilters='';clear.onclick=()=>{[...form.elements].filter(e=>e.name).forEach(e=>e.value='');form.dispatchEvent(new Event('change',{bubbles:true}));};panel.append(clear);}
  },
  masterlist(ctx, page) {
    ctx.el.querySelectorAll(':scope > .metric-strip').forEach(n=>n.remove());
    const input=ctx.el.querySelector('[data-tsearch],[data-docfm-search-input]');if(!input)return;
    const toolbar=document.createElement('form');toolbar.className='oh-master-search';toolbar.setAttribute('role','search');
    toolbar.innerHTML='<div class="oh-record-toolbar"><label class="oh-search"><i class="bi bi-search" aria-hidden="true"></i></label><button class="btn btn-primary" type="submit">Search</button><button class="btn btn-outline-success" type="button" data-master-filters aria-expanded="false">Filters</button></div>';
    const old=input.closest('.table-search,.docfm-search-label');toolbar.querySelector('label').append(input);old?.remove();
    const fields=page==='trucks'?[{name:'truck_type',label:'Truck Type',type:'select',options:UI.list(App.me.truckTypes)},{name:'status',label:'Status',type:'select',options:UI.list(['Available','Assigned','On Trip','Under Maintenance','Unavailable'])},{name:'docs',label:'Documents',type:'select',options:[['expiring','Expiring / expired']]},{name:'inactive',label:'Include inactive trucks',type:'checkbox'}]:page==='clients'?[{name:'status',label:'Status',type:'select',options:UI.list(['Active','Pending Rate','Incomplete','Inactive'])}]:[{name:'entity_type',label:'Record type',type:'select',options:Object.entries(ctx.data.entityLabels||{})},{name:'category',label:'Category',type:'select',options:UI.list([...new Set((ctx.data.documents||[]).map(d=>d.category))])},{name:'expiring',label:'Expiry',type:'select',options:[['1','Expiring / expired only']]}];
    toolbar.insertAdjacentHTML('beforeend',`<div class="oh-record-filter-panel" hidden>${UI.fields(fields.map(f=>({...f,value:ctx.query[f.name]??'',col:6})))}<div class="oh-filter-actions"><button type="button" class="btn btn-primary" data-master-apply>Apply Filters</button><button type="button" class="btn btn-outline-secondary" data-master-clear>Clear Filters</button></div></div>`);ctx.el.prepend(toolbar);
    ctx.el.querySelectorAll('.filter-summary').forEach(n=>n.remove());
    toolbar.onsubmit=event=>{event.preventDefault();input.dispatchEvent(new Event('input',{bubbles:true}));};toolbar.querySelector('[data-master-filters]').onclick=event=>{const panel=toolbar.querySelector('.oh-record-filter-panel');panel.hidden=!panel.hidden;event.currentTarget.setAttribute('aria-expanded',String(!panel.hidden));};
    toolbar.querySelector('[data-master-apply]').onclick=()=>{const values=UI.formData(toolbar),q={...ctx.query};fields.forEach(f=>{if(values[f.name])q[f.name]=values[f.name];else delete q[f.name];});App.go(ctx.page,q);};toolbar.querySelector('[data-master-clear]').onclick=()=>{input.value='';input.dispatchEvent(new Event('input',{bubbles:true}));App.go(ctx.page,ctx.query.tab?{tab:ctx.query.tab}:{});};
  },
  trucks(ctx, masterRender) {
    // FleetUI owns the KPI-first Trucks page, its tabs, filters and actions.
    // Do not prepend the legacy Operations Head masterlist toolbar/tabs:
    // those duplicate controls and place the KPI cards below search.
    masterRender(ctx);
    ctx.setTitle('Trucks', 'Truck records and fixed personnel associations.');
  },
  async dashboard(ctx) {
    ctx.setTitle('Dashboard', 'Operations overview and priority tasks.');
    UI.tool(ctx, 'Refresh', () => ctx.reload(), 'bi-arrow-clockwise', 'outline-secondary');
    const d = ctx.data;
    const tripLink = group => this.link('trips', group === 'pending' ? { view: 'approvals' } : { tab: 'active', group });
    const open = d.incidents?.open;
    ctx.el.innerHTML = `<div class="oh-metrics oh-dashboard-summary"><a class="oh-metric" href="#trips?tab=active"><i class="bi bi-signpost-split" aria-hidden="true"></i><span>Active Trips</span><strong data-active-count>Loading…</strong></a><a class="oh-metric" href="#trips?tab=incidents&incident=open"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Ongoing Incidents</span><strong>${open==null?'Unavailable':UI.num(open)}</strong></a><a class="oh-metric" href="#funds?approval=pending"><i class="bi bi-cash-coin" aria-hidden="true"></i><span>Pending Fund Approvals</span><strong>${d.funds?.pending==null?'Unavailable':UI.num(d.funds.pending)}</strong></a><article class="oh-metric"><i class="bi bi-truck" aria-hidden="true"></i><span>Resource Availability</span><div class="oh-resource-values"><a href="#trucks"><b>${Array.isArray(d.fleet)?UI.num(d.fleet.filter(x=>x.status==='Available').reduce((sum,x)=>sum+Number(x.count),0)):'Unavailable'}</b> Available Trucks</a><a href="#crew"><b data-crew-count>Loading…</b> Available Drivers &amp; Helpers</a></div></article></div><div class="oh-dashboard-grid">${this.panel('Trip Schedule', d.schedule ?
      `<div data-schedule-records><div class="table-responsive"><table class="data-table"><thead><tr><th>Trip / Client</th><th>Scheduled Dispatch</th><th>Truck Type</th><th>Status</th></tr></thead><tbody>${d.schedule.map(t => `<tr><td><button class="btn btn-link p-0" data-oh-trip="${+t.id}">${UI.esc(t.trip_ref)}</button><div>${UI.esc(t.client)}</div></td><td>${this.datetime(t.scheduled_at)}</td><td>${UI.esc(t.truck_type)}</td><td>${OpsHead.badge(t.status)}</td></tr>`).join('') || '<tr><td colspan="4" class="table-empty">No trips scheduled today.</td></tr>'}</tbody></table></div><div class="table-pager">${UI.recordPager({total:d.schedule.length,pageSize:Math.max(1,d.schedule.length)})}</div></div>` : '<p role="alert">Schedule unavailable. Refresh to try again.</p>',
      '<a href="#trips?tab=active">View All</a>')}
      ${this.panel('Needs Action', `<div class="oh-queues">${[[d.funds?.pending,'#funds?approval=pending','Pending Fund Approvals'],[open,'#trips?tab=incidents&incident=open','Ongoing Incidents'],[d.counts?.awaiting,tripLink('awaiting'),'Trips Awaiting Resources']].filter(([n])=>Number(n)>0).map(([n,href,label])=>`<a href="${href}">${label}<b>${UI.num(n)}</b></a>`).join('')||'<p class="small text-muted">No priority actions are available from the current dashboard data.</p>'}</div><a class="oh-monitor-shortcut" href="#dispatcher-monitoring">Dispatch Monitoring <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a><details class="oh-dashboard-activity"><summary>Recent Activity</summary>${d.activity?`<ul class="timeline">${d.activity.map(e=>`<li><span class="when">${this.datetime(e.created_at)} · ${UI.esc(e.actor||'System')}</span><br><button class="btn btn-link p-0" data-oh-trip="${+e.trip_id}">${UI.esc(e.reference)}</button> · ${UI.esc(e.action)}</li>`).join('')||'<li>No operational activity recorded.</li>'}</ul>`:'<p>Activity unavailable.</p>'}</details>`)}</div>`;
    if(Array.isArray(d.schedule))UI.standardTable(ctx.el.querySelector('[data-schedule-records]'),{total:d.schedule.length});
    this.bindTrips(ctx.el);
    const resourceCard=ctx.el.querySelector('.oh-resource-values').closest('article');resourceCard.setAttribute('role','link');resourceCard.tabIndex=0;resourceCard.setAttribute('aria-label','Resource Availability');resourceCard.onclick=event=>{if(!event.target.closest('a'))App.go('trucks');};resourceCard.onkeydown=event=>{if(event.target===resourceCard&&['Enter',' '].includes(event.key)){event.preventDefault();App.go('trucks');}};
    const activeTarget=ctx.el.querySelector('[data-active-count]'),crewTarget=ctx.el.querySelector('[data-crew-count]');
    const values=await Promise.allSettled([App.data('trips'),App.data('crew')]);
    if(activeTarget.isConnected)activeTarget.textContent=values[0].status==='fulfilled'&&Array.isArray(values[0].value.active)?UI.num(values[0].value.opsSummary?.active??values[0].value.active.filter(t=>!this.closed.includes(t.status)&&t.status!=='Pending Review').length):'Unavailable';
    if(crewTarget.isConnected)crewTarget.textContent=values[1].status==='fulfilled'&&Array.isArray(values[1].value.crew)?UI.num(values[1].value.crew.filter(p=>+p.active===1&&p.availability==='Available'&&['Driver','Helper'].includes(p.person_type)).length):'Unavailable';
  },

  trips(ctx) {
    if (ctx.query.view === 'approvals') return App.go('trips', {tab:'history',legacy:'1'});
    if (document.querySelector('.oh-approval-drawer')) {
      this._approvalSequence++;
      UI.closeDrawer();
    }
    const d = TripUI.data = ctx.data;
    const q = ctx.query;
    const tab = ['active','history','incidents'].includes(q.tab) ? q.tab : 'active';
    const legacyOpenPlanner = !!(q.new || q.batch) && !!d.can.create;
    ctx.setTitle(tab === 'incidents' ? 'Incidents & Repairs' : 'Trips',
      tab === 'incidents' ? 'Review incidents and corrective maintenance coordination.' : 'Monitor, manage, and coordinate operational trips.');
    if (tab === 'incidents') {
      ctx.el.innerHTML = '<div data-workspace></div>';
      return this.incidents(ctx.el.querySelector('[data-workspace]'),d.incidents,q);
    }
    const history = tab === 'history';
    const all = d[tab];
    if (!Array.isArray(all)) {
      ctx.el.innerHTML = '<p role="alert">Trip records are unavailable. Refresh this page to retry.</p>';
      return;
    }
    const rows = (history ? all : q.group === 'arrival' ? all : all.filter(t => !this.closed.includes(t.status) && t.status !== 'Pending Review'))
      .filter(t => (history || this.matches(t,q.group))
        && (!q.status || t.status === q.status)
        && (!q.client_id || String(t.client_id) === String(q.client_id))
        && (!q.truck_type || t.truck_type === q.truck_type)
        && (!q.vehicle_id || String(t.vehicle_id) === String(q.vehicle_id))
        && (!q.from || String(t.scheduled_at || '').slice(0,10) >= q.from)
        && (!q.to || String(t.scheduled_at || '').slice(0,10) <= q.to));
    const option = (value,label,current) => '<option value="' + UI.esc(value) + '"'
      + (String(current||'') === String(value) ? ' selected' : '') + '>' + UI.esc(label) + '</option>';
    const statuses = history ? this.closed : (d.statuses || []);
    const clients = d.filterOptions?.clients || [];
    const trucks = d.filterOptions?.trucks || [];
    const types = TripUI.truckTypeOptions();
    const toolbar = '<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger" aria-expanded="false" aria-controls="trip-filter-panel"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>'
      + '<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger" aria-expanded="false" aria-controls="trip-sort-panel"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>'
      + '<div class="record-workspace-popovers">'
      + '<div id="trip-filter-panel" class="record-workspace-popover record-workspace-popover-wide" hidden><strong>Filter Trips</strong>'
      + '<label>Status<select class="form-select form-select-sm" name="status">' + option('','All statuses',q.status) + statuses.map(x => option(x,x,q.status)).join('') + '</select></label>'
      + '<label>Client<select class="form-select form-select-sm" name="client_id">' + option('','All clients',q.client_id) + clients.map(x => option(x.id,x.company_name,q.client_id)).join('') + '</select></label>'
      + '<label>Truck Type<select class="form-select form-select-sm" name="truck_type">' + option('','All types',q.truck_type) + types.map(x => option(x,x,q.truck_type)).join('') + '</select></label>'
      + '<label>Assigned Truck<select class="form-select form-select-sm" name="vehicle_id">' + option('','All trucks',q.vehicle_id) + trucks.map(x => option(x.id,x.plate_number,q.vehicle_id)).join('') + '</select></label>'
      + '<div class="record-workspace-date-range"><label>From<input class="form-control form-control-sm" type="date" name="from" value="' + UI.esc(q.from||'') + '"></label><label>To<input class="form-control form-control-sm" type="date" name="to" value="' + UI.esc(q.to||'') + '"></label></div>'
      + '<div class="record-workspace-popover-actions"><button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button><button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button></div></div>'
      + '<div id="trip-sort-panel" class="record-workspace-popover record-workspace-sort-panel" hidden><strong>Sort Trips</strong>'
      + [['default','Default order'],['newest','Newest dispatch'],['oldest','Oldest dispatch'],['client','Client'],['status','Status']].map(([value,label],i)=>
        '<label class="record-workspace-sort-option"><input type="radio" name="trip_sort" data-record-sort value="' + value + '"' + (i===0?' checked':'') + '> ' + label + '</label>').join('')
      + '</div></div>'
      + (d.can.create ? '<button type="button" class="btn btn-sm btn-primary record-workspace-add"><i class="bi bi-plus-lg"></i> Add Trip</button>' : '');
    const columns = [
      ['Trip / Client','trip_ref',(value,t) => '<b>' + UI.esc(value) + '</b><div class="cell-sub">' + UI.esc(t.client) + '</div>'],
      ['Route / Truck','pickup_point',(value,t) => UI.esc(t.pickup_point || '') + ' → ' + UI.esc(t.delivery_point || '') + '<div class="cell-sub">' + UI.esc(t.plate_number || 'Awaiting assignment') + '</div>'],
      [history?'Closure / Schedule':'Scheduled Dispatch','scheduled_at',(value,t) => history
        ? (t.completed_at ? this.datetime(t.completed_at) : 'Closure date not recorded') + '<div class="cell-sub">Scheduled: ' + this.datetime(value) + '</div>'
        : this.datetime(value)],
      ['Status','status',(value,t) => OpsHead.badge(value) + (+t.awaiting_arrival ? '<div class="badge-s amber">Arrival Pending</div>' : '')],
    ];
    const table = UI.table(columns,rows,{
      tools:toolbar,
      showAllColumns:true,
      actions:() => UI.btn('view','View'),
      onAction:(action,t) => TripUI.open(t.id),
      rowClick:t => TripUI.open(t.id),
      empty: all.length ? 'No trips match your search or filters.' : (history ? 'No trip history available.' : 'No active trips available.'),
    });
    const active = (d.active || []).filter(t => !this.closed.includes(t.status) && t.status !== 'Pending Review');
    ctx.el.innerHTML = '<div class="fleet-trips-workspace">'
      + RecordComponents.kpis([
        {label:'Active Trips',value:d.opsSummary?.active ?? active.length},
        {label:'Needs Action',value:d.opsSummary?.attention ?? 0,color:'amber'},
        {label:'Trips with Incidents',value:d.opsSummary?.incidents ?? 0,color:'red'},
        {label:'Pending Arrival Inspections',value:d.opsSummary?.arrival ?? 0,color:'blue'},
      ])
      + RecordComponents.tabs([{value:'active',label:'Active Trips'},{value:'history',label:'Trip History'}],tab,'data-trip-view')
      + (history ? '<p class="record-workspace-help"><a href="#trips?tab=history&legacy=1">Historical Trip Requests (read-only)</a></p>' : '')
      + (q.group && !history ? '<p class="record-workspace-help">Monitoring group: <b>' + UI.esc(q.group) + '</b> · <a href="#trips?tab=active">Clear group</a></p>' : '')
      + RecordComponents.panel(table) + '</div>';
    UI.activate(ctx.el);
    RecordComponents.mountPanel(ctx.el,{title:history?'Trip History Records':'Active Trip Records',addSelector:'.record-workspace-add'});
    // Keep the trip views inside the record card, matching Route & Contract
    // Records. Reparent the existing tab buttons so their handlers and state
    // remain unchanged, and reuse the shared category-row presentation.
    const tripCategoryRow = ctx.el.querySelector('.record-workspace-category-row');
    const tripViews = ctx.el.querySelector('.record-workspace-tabs');
    if (tripCategoryRow && tripViews) {
      tripCategoryRow.append(tripViews);
      tripCategoryRow.classList.add('fleet-trips-view-row');
    } else tripCategoryRow?.remove();
    ctx.el.querySelector('.record-workspace-add')?.addEventListener('click',()=>this.openTripPlanner(ctx));
    ctx.el.querySelectorAll('[data-trip-view]').forEach(button=>button.addEventListener('click',()=>{
      App.go('trips',{tab:button.dataset.tripView});
    }));
    const tbody=ctx.el.querySelector('[data-tbody]');
    const state=tbody?UI._tables[tbody.dataset.tbody]:null;
    const initial=state?state.rows.slice():[];
    RecordComponents.bindDropdowns(ctx.el,{
      apply(panel){
        const next={...q,tab};
        for(const key of ['status','client_id','truck_type','vehicle_id','from','to']){
          const value=panel.querySelector('[name="'+key+'"]')?.value||'';
          if(value)next[key]=value;else delete next[key];
        }
        if(next.from && next.to && next.from > next.to) {
          UI.toast('End date must be on or after the start date.','error');return;
        }
        App.go('trips',next);
      },
      reset(){
        const next={...q,tab};
        for(const key of ['status','client_id','truck_type','vehicle_id','from','to'])delete next[key];
        App.go('trips',next);
      },
      sort(key){
        if(!state||!tbody)return;
        const ordered=initial.slice();
        if(key==='newest'||key==='oldest'){
          const timestamp=t=>Date.parse(String(t.scheduled_at||'').replace(' ','T'))||0;
          ordered.sort((a,b)=>key==='newest'?timestamp(b)-timestamp(a):timestamp(a)-timestamp(b));
        } else if(key==='client'||key==='status')ordered.sort((a,b)=>
          String(a[key]||'').localeCompare(String(b[key]||''),undefined,{numeric:true}));
        state.rows=ordered;state.page=0;UI.drawTable(tbody.dataset.tbody);
      },
    },{filter:'trip-filter-panel',sort:'trip-sort-panel'});
    if (legacyOpenPlanner) this.openTripPlanner(ctx);
    if(q.open)TripUI.open(UI.takeQuery(ctx,'open').open);
  },

  incidents(workspace, rows, query) {
    if (!Array.isArray(rows)) {
      workspace.innerHTML = '<p role="alert">Incident records are unavailable. Please refresh to retry.</p>';
      return;
    }
    const tab = query.incident === 'resolved' ? 'resolved' : 'open';
    const ongoing = rows.filter(i => i.status !== 'Resolved');
    const resolved = rows.filter(i => i.status === 'Resolved');
    const underRepair = rows.filter(i => i.job_status === 'Under Repair');
    const types = [...new Set(rows.map(i => i.incident_type).filter(Boolean))];
    const repairStatuses = [...new Set(rows.map(i => i.job_status).filter(Boolean))];
    const current = rows.filter(i => (tab === 'open' ? i.status !== 'Resolved' : i.status === 'Resolved')
      && (!query.incident_type || i.incident_type === query.incident_type)
      && (!query.job_status || i.job_status === query.job_status));
    const option = (value,label,selected) => '<option value="' + UI.esc(value) + '"' +
      (String(selected || '') === String(value) ? ' selected' : '') + '>' + UI.esc(label) + '</option>';
    const toolbar = `<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger"
      aria-controls="incident-filter-panel" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
      <button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger"
      aria-controls="incident-sort-panel" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>
      <div class="record-workspace-popovers">
        <div id="incident-filter-panel" class="record-workspace-popover" hidden>
          <strong>Filter Incidents</strong>
          <label>Incident Type<select name="incident_type" class="form-select form-select-sm">
            ${option('','All incident types',query.incident_type)}
            ${types.map(v => option(v,v,query.incident_type)).join('')}
          </select></label>
          <label>Maintenance Status<select name="job_status" class="form-select form-select-sm">
            ${option('','All repair statuses',query.job_status)}
            ${repairStatuses.map(v => option(v,v,query.job_status)).join('')}
          </select></label>
          <div class="record-workspace-popover-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button>
            <button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button>
          </div>
        </div>
        <div id="incident-sort-panel" class="record-workspace-popover record-workspace-sort-panel" hidden>
          <strong>Sort Incidents</strong>
          <label class="record-workspace-sort-option"><input type="radio" name="incident_sort" value="default" data-record-sort checked> Default order</label>
          <label class="record-workspace-sort-option"><input type="radio" name="incident_sort" value="newest" data-record-sort> Newest first</label>
          <label class="record-workspace-sort-option"><input type="radio" name="incident_sort" value="oldest" data-record-sort> Oldest first</label>
          <label class="record-workspace-sort-option"><input type="radio" name="incident_sort" value="severity" data-record-sort> Severity</label>
        </div>
      </div>
`;
    const table = UI.table([
      ['Incident / Trip', 'incident_ref', (value,i) => '<b>' + UI.esc(value) + '</b><div class="cell-sub">' + UI.esc(i.trip_ref) + '</div>'],
      ['Truck', 'plate_number'],
      ['Problem', 'problem', (value,i) => UI.esc(value || i.incident_type || '—')],
      ['Severity', 'severity', value => UI.esc(value || 'Not assessed')],
      ['Repair Status', 'job_status', value => UI.esc(value || 'Not linked')],
      ['Status', 'status', value => OpsHead.badge(value)],
    ], current, {
      tools: toolbar,
      showAllColumns: true,
      actions: () => UI.btn('view', 'View'),
      onAction: (action,i) => this.incident(i),
      rowClick: i => this.incident(i),
      empty: tab === 'open' ? 'No ongoing incidents match your search or filters.' : 'No resolved incidents match your search or filters.',
    });
    workspace.innerHTML = '<div class="fleet-incidents-workspace">'
      + RecordComponents.kpis([
        {label:'Total Incidents',value:rows.length},
        {label:'Ongoing',value:ongoing.length,color:ongoing.length?'amber':''},
        {label:'Under Repair',value:underRepair.length,color:'blue'},
        {label:'Resolved',value:resolved.length,color:'green'},
      ])
      + RecordComponents.tabs([
        {value:'open',label:'Ongoing Incidents'},
        {value:'resolved',label:'Resolved Incidents'},
      ],tab,'data-incident-view')
      + RecordComponents.panel(table)
      + '</div>';
    UI.activate(workspace);
    RecordComponents.mountPanel(workspace,{
      title:tab === 'open' ? 'Ongoing Incident Records' : 'Resolved Incident Records',
      addSelector:'.record-workspace-add',
    });
    workspace.querySelector('.record-workspace-category-row')?.remove();
    workspace.querySelectorAll('[data-incident-view]').forEach(button => button.addEventListener('click',()=>{
      const next={...query,tab:'incidents',incident:button.dataset.incidentView};
      delete next.incident_type; delete next.job_status;
      App.go('trips',next);
    }));
    const tbody=workspace.querySelector('[data-tbody]');
    const state=tbody?UI._tables[tbody.dataset.tbody]:null;
    const original=state?state.rows.slice():[];
    RecordComponents.bindDropdowns(workspace,{
      apply(panel){
        const next={...query,tab:'incidents',incident:tab};
        const type=panel.querySelector('[name="incident_type"]').value;
        const repair=panel.querySelector('[name="job_status"]').value;
        if(type)next.incident_type=type;else delete next.incident_type;
        if(repair)next.job_status=repair;else delete next.job_status;
        App.go('trips',next);
      },
      reset(){
        const next={...query,tab:'incidents',incident:tab};
        delete next.incident_type;delete next.job_status;
        App.go('trips',next);
      },
      sort(key){
        if(!state||!tbody)return;
        if(key==='default')state.rows=original.slice();
        else {
          const values=original.slice();
          if(key==='newest'||key==='oldest'){
            const timestamp=i=>Date.parse(String(i.incident_at||i.created_at||'').replace(' ','T'))||0;
            values.sort((a,b)=>key==='newest'?timestamp(b)-timestamp(a):timestamp(a)-timestamp(b));
          } else if(key==='severity'){
            const weight={Critical:4,High:3,Medium:2,Low:1};
            values.sort((a,b)=>(weight[b.severity]||0)-(weight[a.severity]||0));
          }
          state.rows=values;
        }
        state.page=0;
        UI.drawTable(tbody.dataset.tbody);
      },
    },{filter:'incident-filter-panel',sort:'incident-sort-panel'});
  },
  incident(i) {
    const el = UI.drawer(UI.esc(i.incident_ref), this.panel('Incident & Maintenance Coordination', UI.kv([['Trip', UI.esc(i.trip_ref)], ['Truck', UI.esc(i.plate_number)], ['Problem', UI.e(i.problem)], ['Details', UI.e(i.details)], ['Location', UI.e(i.location)], ['Reported', this.datetime(i.incident_at)], ['Severity', UI.e(i.severity || 'Not assessed')], ['Incident Status', OpsHead.badge(i.status)], ['Trip Status', OpsHead.badge(i.trip_status)], ['Maintenance Record', UI.e(i.job_ref)], ['Repair Status', UI.e(i.job_status)], ['Recommendation', UI.e(i.recommendation)]]) + '<p class="small text-muted">Maintenance personnel perform repairs and clearance. Trip rescheduling follows the existing Dispatcher workflow.</p>') + `<button class="btn btn-primary" data-oh-trip="${+i.trip_id}">View Trip</button> <button class="btn btn-outline-secondary" data-print-incident>Print Incident Report</button>`, { subtitle: UI.esc(i.plate_number) });
    this.bindTrips(el); el.querySelector('[data-print-incident]').onclick = () => TripUI.printIncident(i, {});
  },

  async openFund(id) {
    let f;
    try { f = (await App.api('Funds.details', { fund_id: id })).fund; } catch (ex) { return UI.toast(ex.message, 'error'); }
    const canApprove = f.status === 'Pending Approval' && !this.closed.includes(f.trip_status);
    const el = UI.drawer(UI.esc(f.fund_ref), UI.fundProgress(f) + this.panel('Request Information', UI.kv([['Related Trip', UI.esc(f.trip_ref)], ['Requested By', UI.e(f.requested_by_name)], ['Request Date', this.datetime(f.created_at)], ['Amount', UI.money(f.amount)], ['Approval Status', OpsHead.badge(['Ready for Release', 'Released'].includes(f.status) ? 'Approved' : f.status)], ['Finance Release', UI.e(f.status === 'Released' ? 'Released' : f.status === 'Ready for Release' ? 'Awaiting Finance' : null)], ['Notes / Justification', UI.e(f.notes)]])) + this.panel('Expense Breakdown', UI.table([['Category', 'category_name'], ['Amount', 'amount', 'money']], f.lines, { plain: true })) + this.panel('Approval History', (f.history || []).length ? `<ul class="timeline">${f.history.map(e => `<li>${UI.esc(e.action)}<br><small>${this.datetime(e.created_at)} · ${UI.esc(e.actor || 'System')}</small></li>`).join('')}</ul>` : UI.kv([['Approved By', UI.e(f.approved_by_name)], ['Approved At', this.datetime(f.approved_at)], ['Rejection Reason', UI.e(f.rejected_reason)]])) + this.panel('Documents', UI.docs(f.documents)) + `<div class="oh-form-footer"><button class="btn btn-outline-secondary" data-related-trip>View Trip</button>${canApprove ? '<button class="btn btn-outline-danger" data-reject>Reject</button><button class="btn btn-primary" data-approve>Approve</button>' : ''}</div>`, { subtitle: UI.esc(f.trip_ref) });
    el.querySelector('[data-related-trip]').onclick = () => { UI.closeDrawer(); App.go('trips', { tab: 'active', open: f.trip_id }); };
    const decide = decision => UI.confirmAction(`${decision === 'approve' ? 'Approve' : 'Reject'} ${UI.esc(f.fund_ref)}`, decision === 'approve' ? `Approve ${UI.money(f.amount)}? Finance will handle release.` : 'Provide a reason for the dispatcher to revise this request.', 'Funds.decide', { fund_id: f.id, decision }, { submit: decision === 'approve' ? 'Approve' : 'Reject', danger: decision === 'reject', fields: decision === 'reject' ? [{ name: 'reason', label: 'Reason', required: true, col: 12, attrs: 'maxlength="300"' }] : [], after: () => { UI.closeDrawer(); App.route(); } });
    el.querySelector('[data-approve]')?.addEventListener('click', () => decide('approve'));
    el.querySelector('[data-reject]')?.addEventListener('click', () => decide('reject'));
  },

  routes(ctx) {
    ctx.setTitle('Client Routes', 'Manage approved client routes and route requests.');
    const d=ctx.data,q=ctx.query;
    if(!Array.isArray(d.routes)){
      ctx.el.innerHTML='<p role="alert">Route records are unavailable. Please refresh to try again.</p>';
      return;
    }
    const tab=q.tab==='requests'?'requests':'approved';
    const all=d.routes;
    const approved=all.filter(r=>r.status==='Approved');
    const requests=all.filter(r=>r.status!=='Approved'||r.pending_rate_id);
    const rows=(tab==='approved'?approved:requests).filter(r=>
      (!q.truck_type||r.truck_type===q.truck_type)
      &&(!q.status||r.status===q.status)
    );
    const typeOptions=App.me.truckTypes||[];
    const option=(value,label,current)=>'<option value="'+UI.esc(value)+'"'+
      (String(current||'')===String(value)?' selected':'')+'>'+UI.esc(label)+'</option>';
    const toolbar=`<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger"
      aria-controls="routes-filter-panel" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
      <button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger"
      aria-controls="routes-sort-panel" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>
      <div class="record-workspace-popovers">
        <div id="routes-filter-panel" class="record-workspace-popover" hidden>
          <strong>Filter Routes</strong>
          <label>Truck Type<select name="truck_type" class="form-select form-select-sm">
            ${option('','All truck types',q.truck_type)}
            ${typeOptions.map(type=>option(type,type,q.truck_type)).join('')}
          </select></label>
          <label>Status<select name="status" class="form-select form-select-sm">
            ${option('','All statuses',q.status)}
            ${['Approved','Pending Rate','Pending Approval','Rejected','Withdrawn','Inactive'].map(status=>option(status,status,q.status)).join('')}
          </select></label>
          <div class="record-workspace-popover-actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button>
            <button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button>
          </div>
        </div>
        <div id="routes-sort-panel" class="record-workspace-popover record-workspace-sort-panel" hidden>
          <strong>Sort Routes</strong>
          <label class="record-workspace-sort-option"><input type="radio" name="routes_sort" data-record-sort value="default" checked> Default order</label>
          <label class="record-workspace-sort-option"><input type="radio" name="routes_sort" data-record-sort value="client"> Client</label>
          <label class="record-workspace-sort-option"><input type="radio" name="routes_sort" data-record-sort value="truck_type"> Truck type</label>
          <label class="record-workspace-sort-option"><input type="radio" name="routes_sort" data-record-sort value="status"> Status</label>
          <label class="record-workspace-sort-option"><input type="radio" name="routes_sort" data-record-sort value="distance_km"> Distance</label>
        </div>
      </div>`
      +(d.can?.request?'<button type="button" class="btn btn-sm btn-primary record-workspace-add"><i class="bi bi-plus-lg" aria-hidden="true"></i> Request New Route</button>':'');
    const columns=[
      ['Client','client',value=>'<strong class="oh-route-client">'+UI.e(value)+'</strong>'],
      ['Route','origin_label',(value,r)=>'<div class="oh-route-journey">'
        +'<div class="oh-route-place"><span class="oh-route-point origin" aria-hidden="true"></span><span>'+UI.e(r.origin_label)+'</span></div>'
        +'<div class="oh-route-connector" aria-hidden="true"></div>'
        +'<div class="oh-route-place"><span class="oh-route-point destination" aria-hidden="true"></span><span>'+UI.e(r.destination_label)+'</span></div>'
        +'</div>'],
      ['Truck Type','truck_type',value=>'<span class="oh-route-truck">'+UI.e(value)+'</span>'],
      ...(tab==='approved'?[
        ['Distance (km)','distance_km',value=>'<span class="oh-route-distance">'+UI.num(value,2)+'</span>'],
        ['Approved Rate','rate_amount',(value,r)=>'<strong class="oh-route-rate">'+RouteUI.rateText(r.rate_type,value,r.minimum_charge)+'</strong>'],
      ]:[
        ['Request Date','requested_at',value=>OpsHead.datetime(value)],
      ]),
      ['Status','status',(value,r)=>'<span class="oh-route-status">'+OpsHead.badge(value)+'</span>'+(r.pending_rate_id&&value==='Approved'?'<div class="small">Rate revision pending</div>':'')],
    ];
    const table=UI.table(columns,rows,{
      tools:toolbar,showAllColumns:true,
      actions:()=>UI.btn('view','View'),
      onAction:(action,r)=>RouteUI.open(r.id,d),
      rowClick:r=>RouteUI.open(r.id,d),
      empty:tab==='approved'?'No approved routes match your search or filters.':'No route requests match your search or filters.',
    });
    ctx.el.innerHTML='<div class="fleet-routes-workspace oh-client-routes-workspace">'
      +RecordComponents.kpis([
        {label:'Approved Routes',value:approved.length,color:'green'},
        {label:'Route Requests',value:requests.length},
        {label:'Pending Rate',value:all.filter(r=>r.status==='Pending Rate').length,color:'blue'},
        {label:'Pending Approval',value:all.filter(r=>r.status==='Pending Approval'||!!r.pending_rate_id).length,color:'amber'},
      ])
      +RecordComponents.tabs([
        {value:'approved',label:'Approved Routes'},
        {value:'requests',label:'Route Requests'},
      ],tab,'data-route-view')
      +RecordComponents.panel(table)
      +'</div>';
    UI.activate(ctx.el);
    RecordComponents.mountPanel(ctx.el,{
      title:tab==='approved'?'Approved Route Records':'Route Request Records',
      addSelector:'.record-workspace-add',
    });
    ctx.el.querySelector('.record-workspace-category-row')?.remove();
    ctx.el.querySelector('.record-workspace-add')?.addEventListener('click',()=>RouteUI.request(d));
    ctx.el.querySelectorAll('[data-route-view]').forEach(button=>button.addEventListener('click',()=>{
      const next={...ctx.query,tab:button.dataset.routeView};
      delete next.truck_type;delete next.status;
      App.go(ctx.page,next);
    }));
    const tbody=ctx.el.querySelector('[data-tbody]');
    const tableState=tbody?UI._tables[tbody.dataset.tbody]:null;
    const original=tableState?tableState.rows.slice():[];
    RecordComponents.bindDropdowns(ctx.el,{
      apply(panel){
        const next={...ctx.query,tab},type=panel.querySelector('[name="truck_type"]').value,
          status=panel.querySelector('[name="status"]').value;
        if(type)next.truck_type=type;else delete next.truck_type;
        if(status)next.status=status;else delete next.status;
        App.go(ctx.page,next);
      },
      reset(){
        const next={...ctx.query,tab};
        delete next.truck_type;delete next.status;
        App.go(ctx.page,next);
      },
      sort(key){
        if(!tableState||!tbody)return;
        tableState.rows=key==='default'?original.slice():
          original.slice().sort((a,b)=>key==='distance_km'
            ? (+a.distance_km||0)-(+b.distance_km||0)
            : String(a[key]||'').localeCompare(String(b[key]||''),undefined,{numeric:true}));
        tableState.page=0;
        UI.drawTable(tbody.dataset.tbody);
      },
    },{filter:'routes-filter-panel',sort:'routes-sort-panel'});
  },

  decorateTrip(el, r) {
    const t = r.trip, body = el.querySelector('.offcanvas-body');
    el.classList.add('oh-trip-drawer');
    el.querySelector('.offcanvas-header > div').insertAdjacentHTML('beforeend', OpsHead.badge(t.status));
    el.querySelector('.btn-close').setAttribute('aria-label', 'Close Trip Details');
    const originalActions = body.querySelector('.drawer-actions');
    const sections = new Map();
    // Move the existing rendered sections so their authorized actions and event handlers survive.
    [...body.querySelectorAll('.drawer-h')].forEach(h => {
      const label = h.querySelector('span').textContent, fragment = document.createDocumentFragment();
      let node = h.nextSibling;
      while (node && !(node.nodeType === 1 && node.classList.contains('drawer-h'))) { const next = node.nextSibling; fragment.append(node); node = next; }
      const buttons = [...h.querySelectorAll('button')]; buttons.forEach(b => fragment.prepend(b));
      sections.set(label, fragment); h.remove();
    });
    const ins = r.inspections.find(i => +i.attempt_number === +t.attempt_number && i.kind === 'Pre-Dispatch');
    const arr = r.inspections.find(i => +i.attempt_number === +t.attempt_number && i.kind === 'Arrival');
    const fund = r.funds.find(f => +f.attempt_number === +t.attempt_number);
    const approved = !!t.approved_at;
    const dispatched = !!t.dispatched_at;
    const prepared = !!ins?.completed_at && !+ins.has_not_ok;
    const progress = t.status === 'Completed' ? 5 : dispatched ? 4 : prepared ? 3 : t.vehicle_id ? 2 : approved ? 1 : 0;
    const kv = UI.kv;
    const text = UI.e;
    body.innerHTML = this.panel('Trip Summary', kv([['Client', text(t.client)], ['Approved Route', text(t.client_route_ref || (t.client_route_id ? 'Linked route' : 'No approved route linked'))], ['Route', `${UI.esc(t.pickup_point)} → ${UI.esc(t.delivery_point)}`], ['Truck', text(t.plate_number)], ['Driver / Helper', [t.driver_name, t.driver2_name, t.helper_name].filter(Boolean).map(UI.esc).join(' · ') || '—'], ['Schedule', this.datetime(t.scheduled_at)]])) +
      this.panel('Progress', UI.tripProgress(t, r) + `<p class="small mb-0">Official status: ${OpsHead.badge(t.status)}</p><p class="small text-muted">${UI.esc(TripUI.next(t))}</p>`) +
      this.panel('Readiness', kv([[t.intake_source === 'Dispatcher' ? 'Historical Request State' : 'Trip Creation', t.intake_source === 'Dispatcher' ? OpsHead.badge(t.status) : 'Created by Operations Head'], ['Truck & Personnel', t.status === 'Completed' ? 'See recorded Truck and personnel below' : t.vehicle_id && t.driver_id && (t.truck_type === 'Car Carrier' ? t.driver2_id : t.helper_id) ? 'Assigned' : 'Incomplete / awaiting resources'], ['Trip Fund', fund ? OpsHead.badge(fund.status) : (+t.attempt_number > 1 ? 'No new request (optional for redispatch)' : 'Not requested')], ['Pre-Dispatch Checklist', ins ? (prepared ? 'Completed' : 'NOT OK / incomplete') : 'Not completed'], ['Dispatch', dispatched ? this.datetime(t.dispatched_at) : 'Not dispatched'], ['Arrival Checklist', t.status === 'Completed' ? (+t.awaiting_arrival ? (arr ? 'Recorded; clearance pending' : 'Pending') : arr ? 'Recorded' : 'Not recorded') : 'Not yet applicable']])) + '<div data-trip-sections></div>';
    const content = body.querySelector('[data-trip-sections]');
    const append = (title, html, open = false) => {
      const details = document.createElement('details'); details.className = 'oh-detail-section'; details.open = open;
      const summary = document.createElement('summary'); summary.textContent = title; details.append(summary);
      const inner = document.createElement('div'); inner.className = 'oh-detail-body';
      if (typeof html === 'string') inner.innerHTML = html; else if (html) inner.append(html);
      details.append(inner); content.append(details); return details;
    };
    append('Overview', kv([['Reference', text(t.trip_ref)], ['Client', text(t.client)], ['Truck Type', text(t.truck_type)], ['Requested By', text(t.created_by_name)], ['Created', this.datetime(t.created_at)], ['Official Status', OpsHead.badge(t.status)], t.cancel_reason ? ['Cancellation / Rejection Reason', text(t.cancel_reason)] : null]), true);
    const route = append('Route & Schedule', kv([['Approved Route', text(t.client_route_ref)], ['Pickup', text(t.pickup_point)], ['Delivery', text(t.delivery_point)], ['Dispatch Schedule', this.datetime(t.scheduled_at)], ['Billable Distance', UI.num(t.distance_km, 2) + ' km'], ['Distance Source', text(t.distance_source)], ['Computed Distance', t.distance_computed_km ? UI.num(t.distance_computed_km, 2) + ' km' : '—'], ['Override Reason', text(t.distance_override_reason)], ['Checklist Opens', this.datetime(t.time_gates?.checklist_opens_at)], ['Dispatch Opens', this.datetime(t.time_gates?.dispatch_opens_at)]]));
    const savedMap = sections.get('Trip')?.querySelector?.('[data-routemap]');
    if (savedMap) { route.querySelector('.oh-detail-body').append(savedMap); route.addEventListener('toggle', () => window.dispatchEvent(new Event('resize'))); }
    append('Shipment & Delivery', kv([['Instructions', text(t.delivery_details)], ['Booking Reference', text(t.booking_reference)], ['Consignee', text(t.consignee)], ...(t.truck_type === 'Car Carrier' ? [['Cargo Units', text(t.units_planned)]] : [['Shipment Number', text(t.shipment_number)]]), ...(t.truck_type === 'Container' ? [['Container Number', text(t.container_number)], ['Container Size', text(t.container_size)], ['Seal Number', text(t.seal_number)]] : [])]));
    append('Truck & Personnel', kv([['Truck', text(t.plate_number)], ['Vehicle Status', OpsHead.badge(t.truck_status)], ['Driver', text(t.driver_name)], ['Second Driver', text(t.driver2_name)], ['Helper', text(t.helper_name)], ['Dispatcher', text(t.dispatcher_name)], ['Team', text(UI.label(t.dispatcher_role))], ...(t.dispatcher_shift ? [['Planning Dispatcher Shift', text(t.dispatcher_shift)]] : []), ['Attendance Mode', text(t.crew_attendance?.mode)], ['Attendance Shift', text(t.crew_attendance?.dispatch_shift)]]) + (t.crew_attendance?.crew || []).map(c => `<p class="small">${UI.esc(c.role)} · ${UI.esc(c.full_name || c.name || '')}: ${UI.esc(c.issue || 'Present')}${c.problem ? ' · ' + UI.esc(c.problem) : ''}${c.outside_scheduled_shift ? ' · Outside scheduled shift' : ''}</p>`).join('') + (t.crew_attendance?.missing || []).map(x => `<p class="text-danger small">${UI.esc(x)}</p>`).join(''));
    const funds = append('Trip Funds & Fuel', sections.get('Fund request'));
    funds.querySelector('.oh-detail-body').insertAdjacentHTML('beforeend', kv([['Estimated Fuel Cost', t.fuel_estimate ? UI.money(t.fuel_estimate) : '—']]));
    if (fund) { const b = document.createElement('button'); b.className = 'btn btn-sm btn-outline-success'; b.textContent = 'View Fund'; b.onclick = () => this.openFund(fund.id); funds.querySelector('.oh-detail-body').append(b); }
    append('Pre-Dispatch Checklist', [...sections].find(([key]) => key.startsWith('Pre-dispatch checklist'))?.[1]);
    append('Dispatch & Progress', sections.get('Updates and delays'));
    const incidents = append('Incidents & Repairs', sections.get('Incidents') || '<p>No incidents recorded.</p>'); incidents.dataset.incidentSection = '';
    const completion = append('Completion & Arrival', sections.get('Completion') || '<p>Trip is not completed.</p>');
    if (sections.get('Arrival checklist')) completion.querySelector('.oh-detail-body').append(sections.get('Arrival checklist'));
    append('Documents', sections.get('Documents'));
    append('Activity Timeline', sections.get('History'));
    if (originalActions) body.prepend(originalActions);
    // Move one existing authorized action to a fixed footer; moving preserves its listener.
    const primary = originalActions?.querySelector('button:not([disabled])');
    el.querySelector('.oh-drawer-footer')?.remove();
    const footer = document.createElement('div'); footer.className = 'oh-drawer-footer';
    if (primary) { primary.className = 'btn btn-primary'; footer.append(primary); }
    else { const close = document.createElement('button'); close.className = 'btn btn-outline-secondary'; close.textContent = 'Close'; close.onclick = () => UI.closeDrawer(); footer.append(close); }
    el.append(footer);
  },

  crew(ctx) {
    const d = ctx.data || {}, q = ctx.query;
    if (!Array.isArray(d.crew)) {
      ctx.el.innerHTML = '<p role="alert">Personnel records are unavailable. Please refresh or check the personnel service.</p>';
      return;
    }
    const type = q.person_type === 'Helper' ? 'Helper' : 'Driver';
    const active = d.crew.filter(p => +p.active);
    const typeRows = active.filter(p => p.person_type === type);
    const available = typeRows.filter(p => p.availability === 'Available').length;
    const onTrip = typeRows.filter(p => p.availability === 'On Trip').length;
    const unavailable = typeRows.filter(p => p.availability === 'Unavailable').length;
    const rows = d.crew.filter(p =>
      (q.inactive ? true : +p.active)
      && p.person_type === type
      && (!q.availability || p.availability === q.availability)
      && (!q.license || (p.license_days !== null && +p.license_days <= +d.alertDays))
    );
    const option = (value, label, current) => '<option value="' + UI.esc(value) + '"'
      + (String(value) === String(current || '') ? ' selected' : '') + '>' + UI.esc(label) + '</option>';
    const toolbar = `<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger"
       aria-controls="crew-filter-panel" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
       <button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger"
       aria-controls="crew-sort-panel" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>
       <div class="record-workspace-popovers">
         <div id="crew-filter-panel" class="record-workspace-popover" hidden>
           <strong>Filter Personnel</strong>
           <label>Availability<select class="form-select form-select-sm" name="availability">
             ${option('', 'All statuses', q.availability)}
             ${['Available','On Trip','Unavailable'].map(v => option(v, v, q.availability)).join('')}
           </select></label>
           ${type === 'Driver' ? `<label>License<select class="form-select form-select-sm" name="license">
             ${option('', 'Any license expiry', q.license)}${option('expiring', 'Expiring / expired', q.license)}
           </select></label>` : ''}
           <label class="record-workspace-check"><input type="checkbox" name="inactive" ${q.inactive ? 'checked' : ''}> Include inactive personnel</label>
           <div class="record-workspace-popover-actions">
             <button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button>
             <button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button>
           </div>
         </div>
         <div id="crew-sort-panel" class="record-workspace-popover record-workspace-sort-panel" hidden>
           <strong>Sort Personnel</strong>
           <label class="record-workspace-sort-option"><input type="radio" name="crew_sort" value="default" data-record-sort checked> Default order</label>
           <label class="record-workspace-sort-option"><input type="radio" name="crew_sort" value="name" data-record-sort> Name (A–Z)</label>
           <label class="record-workspace-sort-option"><input type="radio" name="crew_sort" value="availability" data-record-sort> Availability</label>
           <label class="record-workspace-sort-option"><input type="radio" name="crew_sort" value="truck" data-record-sort> Fixed truck</label>
         </div>
       </div>
       ${d.can?.edit ? '<button type="button" class="btn btn-sm btn-primary record-workspace-add"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add ' + type + '</button>' : ''}`;
    const columns = [
      ['Name', 'full_name', (value, person) => '<b>' + UI.esc(value) + '</b>' + (!+person.active ? ' <span class="badge-s red">Inactive</span>' : '')],
      ['Fixed Truck', 'regular_truck'],
      ...(type === 'Driver' ? [['Regular Helper', 'regular_helper_name']] : []),
      ['Attendance Readiness', 'id', (value, person) => !Array.isArray(d.attendance)
        ? 'Unavailable' : OpsHead.badge(d.attendance.find(entry => +entry.personnel_id === +person.id)?.status || 'Not Yet Marked')],
      ['Availability', 'availability', 'badge'],
      ...(type === 'Driver' ? [['License Expiry', 'license_expiry', 'date']] : []),
    ];
    const table = UI.table(columns, rows, {
      tools: toolbar, showAllColumns: true,
      actions: () => UI.btn('view', 'View'),
      onAction: (action, person) => FleetUI.crewDetails(person.id, d),
      rowClick: person => FleetUI.crewDetails(person.id, d),
      empty: typeRows.length ? 'No personnel match your search or filters.' : 'No ' + (type === 'Driver' ? 'drivers' : 'helpers') + ' registered yet.',
    });
    ctx.el.innerHTML = '<div class="fleet-crew-workspace">'
      + RecordComponents.kpis([
        {label:'Total ' + (type === 'Driver' ? 'Drivers' : 'Helpers'),value:typeRows.length},
        {label:'Available',value:available,color:'green'},
        {label:'On Trip',value:onTrip,color:'blue'},
        {label:'Unavailable',value:unavailable,color:unavailable?'red':''},
      ])
      + RecordComponents.tabs([{value:'Driver',label:'Drivers'},{value:'Helper',label:'Helpers'}],type,'data-crew-type')
      + RecordComponents.panel(table, '')
      + '<p class="record-workspace-help">Attendance readiness reflects the current shift. Dispatch performs its own eligibility checks.</p>'
      + '</div>';
    UI.activate(ctx.el);
    RecordComponents.mountPanel(ctx.el,{title: type + ' Records',addSelector:'.record-workspace-add'});
    ctx.el.querySelector('.record-workspace-category-row')?.remove();
    ctx.el.querySelectorAll('[data-crew-type]').forEach(button => button.addEventListener('click',() => {
      const next = {...ctx.query,person_type:button.dataset.crewType};
      delete next.availability;delete next.license;delete next.inactive;
      App.go(ctx.page,next);
    }));
    ctx.el.querySelector('.record-workspace-add')?.addEventListener('click',()=>FleetUI.crewForm({person_type:type},d));
    const tableBox=ctx.el.querySelector('[data-tbody]');
    const state=tableBox ? UI._tables[tableBox.dataset.tbody] : null;
    const original=state ? state.rows.slice() : [];
    RecordComponents.bindDropdowns(ctx.el,{
      apply(panel) {
        const next={...ctx.query,person_type:type};
        const availability=panel.querySelector('[name="availability"]').value;
        const license=panel.querySelector('[name="license"]')?.value;
        if(availability)next.availability=availability;else delete next.availability;
        if(license)next.license=license;else delete next.license;
        if(panel.querySelector('[name="inactive"]').checked)next.inactive='1';else delete next.inactive;
        App.go(ctx.page,next);
      },
      reset() {
        const next={...ctx.query,person_type:type};
        delete next.availability;delete next.license;delete next.inactive;
        App.go(ctx.page,next);
      },
      sort(key) {
        if(!state||!tableBox)return;
        const field={name:'full_name',availability:'availability',truck:'regular_truck'}[key];
        state.rows=key==='default' ? original.slice() :
          original.slice().sort((a,b)=>String(a[field]||'').localeCompare(String(b[field]||''),undefined,{numeric:true}));
        state.page=0;UI.drawTable(tableBox.dataset.tbody);
      },
    },{filter:'crew-filter-panel',sort:'crew-sort-panel'});
  },

  openTripPlanner(ctx) {
    if (!ctx.data?.can?.create) return;
    const existing = document.querySelector('.oh-trip-planner-modal');
    if (existing) { existing.querySelector('.modal-body')?.focus(); return; }
    const wrap = document.createElement('div');
    wrap.className = 'modal fade oh-trip-planner-modal';
    wrap.tabIndex = -1;
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    wrap.setAttribute('aria-labelledby', 'oh-trip-planner-title');
    wrap.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">'
      + '<div class="modal-content">'
      + '<div class="modal-header"><div><h5 class="modal-title" id="oh-trip-planner-title">Add Trip</h5>'
      + '<p class="small text-muted mb-0">Select a client route, schedule, and dispatcher.</p></div>'
      + '<button type="button" class="btn-close" data-plan-close aria-label="Close trip planner"></button></div>'
      + '<div class="modal-body" tabindex="-1"></div></div></div>';
    document.body.append(wrap);
    const modal = new bootstrap.Modal(wrap, { backdrop: 'static', keyboard: false, focus: true });
    const body = wrap.querySelector('.modal-body');
    const initialPlan = { ...ctx, el: body, setTitle() {} };
    // Keep the existing battle-tested planner logic and API calls unchanged.
    // Only swap its rendering target from the page to the modal body.
    const close = async () => {
      if (body.dataset.planBusy === 'true' || body.querySelector('[data-confirm]')?.textContent?.includes('Creating Trips')) {
        UI.toast('Finish the current trip submission before closing.', 'warning'); return;
      }
      const hasChanges = [...body.querySelectorAll('input:not([type=hidden]),select,textarea')]
        .some(el => el.name !== 'quantity' && (el.type === 'checkbox' ? el.checked : !!String(el.value || '').trim()))
        || !!body.querySelector('[data-batch-list] .oh-batch-entry')
        || !!body.querySelector('[data-review-entries] .oh-panel');
      if (hasChanges && !await UI.confirmProceed(
        'Close Trip Planning', 'Your unsaved trip planning changes may be lost. Close this form?', 'Close Planner')) return;
      modal.hide();
    };
    wrap.addEventListener('click', event => {
      if (event.target.closest('[data-plan-close]')) { event.preventDefault(); close(); }
    });
    wrap.addEventListener('hidden.bs.modal', () => wrap.remove(), {once:true});
    modal.show();
    this.planner(initialPlan);
  },

  planner(ctx) {
    // Render inside the Add Trip modal; leave the Trips page itself visible.
    let entries = [], drafts = [], busy = false, attempted = false, uncertain = false;
    let editingId=null, editingBackup=null;
    const batch=()=>entries.filter(e=>e.queued!==false);
    const current=()=>entries.find(e=>e.id===editingId)||entries.find(e=>e.queued===false);
    const dirty=e=>e&&Object.entries(e.data).some(([key,value])=>key==='quantity'?+value!==1:value!==''&&value!=null);
    const stages=step=>`<nav class="oh-planning-stages" aria-label="Trip planning stages"><span ${step===1?'aria-current="step" class="active"':''}><b>1</b> Trip Planning</span><span ${step===2?'aria-current="step" class="active"':''}><b>2</b> Review &amp; Create</span></nav>`;
    const recoveryKey = `fleetops:operations-planning:${App.me.user.id}`;
    const remember = () => {
      try {
        if (drafts.length && drafts.every(x => x.result?.trip_id)) sessionStorage.removeItem(recoveryKey);
        else sessionStorage.setItem(recoveryKey, JSON.stringify({ entries: batch().map(({id, data, route}) => ({id, data, route, queued:true, routes: route ? [route] : [], seq: 0, loading: false})), drafts, attempted, uncertain }));
      } catch { /* In-memory request keys still protect retries in this page. */ }
    };
    const clients = ctx.data.clients || [];
    const clientName = id => clients.find(c => String(c.id) === String(id))?.company_name || 'Choose client';
    const dispatcherOptions = type => !['Car Carrier','Container','Wing Van'].includes(type) ? [] : (ctx.data.dispatcherGroups||[]).filter(g=>g.role===(type==='Car Carrier'?'car_carrier_dispatcher':'container_wingvan_dispatcher')).map(g=>g.shift).map(shift => [`${type === 'Car Carrier' ? 'car_carrier_dispatcher' : 'container_wingvan_dispatcher'}:${shift}`, `${type === 'Car Carrier' ? 'Car Carrier' : 'Container / Wing Van'} Dispatcher (${shift})`]);
    const dispatcherName = data => dispatcherOptions(data.truck_type).find(([key]) => key === data.dispatcher_assignment)?.[1] || 'Choose dispatcher';
    const accountOptions = data => (ctx.data.dispatcherAccounts || []).filter(a => `${a.role}:${a.shift}` === data.dispatcher_assignment);
    const accountName = data => accountOptions(data).find(a => String(a.id) === String(data.responsible_dispatcher_id))?.full_name || 'Account not selected';
    const total = () => batch().reduce((n, e) => n + (+e.data.quantity || 0), 0);
    const error = message => { const box = ctx.el.querySelector('[data-plan-error]'); box.textContent = message; box.hidden = !message; };
    const update = () => {
      ctx.el.querySelector('[data-total]').textContent = `Total Required Trucks: ${total()}`;
      ctx.el.querySelector('[data-entry-count]').textContent = `${batch().length} route${batch().length === 1 ? '' : 's'} · ${total()} truck${total() === 1 ? '' : 's'}`;
      ctx.el.querySelector('[data-planned-routes]').hidden = !batch().length;
      ctx.el.querySelector('[data-add]').disabled = (!editingId&&total()>=20)||busy||!current();
      const reviewNow = ctx.el.querySelector('[data-add-review]');
      if (reviewNow) reviewNow.disabled = (!editingId&&total()>=20)||busy||!current();
      ctx.el.querySelector('[data-review-batch]').disabled=!batch().length||busy||!!editingId;
      const queue=ctx.el.querySelector('[data-batch-list]');
      queue.innerHTML=batch().map(e=>`<article class="oh-batch-entry"><b>${UI.esc(clientName(e.data.client_id))}</b><div>${UI.esc(e.data.truck_type)} · ${+e.data.quantity} truck(s)</div><div>${UI.esc(e.route?.origin_label||'')} → ${UI.esc(e.route?.destination_label||'')}</div><div>${this.datetime(e.data.scheduled_at)}</div><div class="oh-batch-actions"><button type="button" class="btn btn-sm btn-outline-success" data-edit-batch="${e.id}">Edit</button><button type="button" class="btn btn-sm btn-outline-danger" data-remove-batch="${e.id}">Remove</button></div></article>`).join('');
      queue.querySelectorAll('[data-edit-batch]').forEach(button=>button.onclick=async()=>{if(editingId===button.dataset.editBatch)return;if(dirty(current())&&current()?.id!==button.dataset.editBatch&&!await UI.confirmProceed('Edit batch entry','Discard the unsaved entry in the form?','Edit entry'))return;if(editingId&&editingBackup)Object.assign(current(),editingBackup);entries=entries.filter(e=>e.queued!==false);editingId=button.dataset.editBatch;const entry=current();editingBackup=JSON.parse(JSON.stringify({data:entry.data,route:entry.route,routes:entry.routes}));renderPlanning();});
      queue.querySelectorAll('[data-remove-batch]').forEach(button=>button.onclick=()=>{const entry=entries.find(e=>e.id===button.dataset.removeBatch);entry.seq++;entries=entries.filter(e=>e!==entry);if(editingId===entry.id){editingId=null;editingBackup=null;}if(!current())add();else renderPlanning();});
      entries.forEach((e, i) => {
        const card = ctx.el.querySelector(`[data-entry="${e.id}"]`);
        if (!card) return;
        card.querySelector('[data-entry-label]').textContent = editingId?'Edit Trip Entry':'Create Trip Entry';
        card.querySelector('[data-entry-summary]').textContent = `${clientName(e.data.client_id)} · ${e.data.truck_type || 'Truck type'} · ${e.data.scheduled_at ? OpsHead.datetime(e.data.scheduled_at) : 'Schedule'} · ${e.data.quantity} required truck(s)`;
      });
    };
    const add = () => {
      if (total() < 20&&!current())entries.push({ id: UI.uuid(), queued:false, data: { quantity: 1 }, routes: [], loading: false, seq: 0 }); renderPlanning();
    };
    const renderPlanning = () => {
      if(!current()&&total()<20)entries.push({id:UI.uuid(),queued:false,data:{quantity:1},routes:[],loading:false,seq:0});
      document.querySelector('.oh-trip-planner-modal')?.classList.remove('oh-trip-review-mode');
      ctx.el.innerHTML = `<div class="oh-planner oh-planning-workbench oh-simple-planner">${stages(1)}<div class="alert alert-danger" data-plan-error role="alert" hidden></div><div class="oh-planning-columns"><section class="oh-planning-editor"><form id="oh-trip-planning-form" data-planning novalidate><div data-entries></div>${!current()?'<p>The batch has reached the existing 20-trip limit. Edit or review its entries.</p>':''}</form></section></div><section class="oh-planned-routes" data-planned-routes ${batch().length?'':'hidden'} aria-label="Added routes"><div class="oh-planned-routes-head"><span><i class="bi bi-check2-circle" aria-hidden="true"></i> Added Routes</span><small data-entry-count></small></div><strong class="visually-hidden" data-total></strong><div data-batch-list></div></section><button type="button" data-review-batch hidden aria-hidden="true" tabindex="-1">Review queued routes</button><div class="oh-form-footer oh-trip-planner-actions"><small class="oh-trip-plan-limit">Maximum 20 trucks per batch</small><button type="button" class="btn btn-outline-secondary" data-clear>Clear Entry</button><button type="submit" form="oh-trip-planning-form" class="btn btn-outline-primary" data-add>${editingId?'Save Entry':'Add Another Route'}</button><button type="submit" form="oh-trip-planning-form" class="btn btn-primary" data-add-review>${editingId?'Save & Review':'Review & Create'}</button></div></div>`;
      const list = ctx.el.querySelector('[data-entries]');
      entries.filter(e=>e===current()).forEach((e, i) => {
        const card = document.createElement('details'); card.className = 'oh-entry'; card.dataset.entry = e.id; card.open = e.expanded !== false;
        card.ontoggle = () => e.expanded = card.open;
        card.innerHTML = `<summary><b data-entry-label>Trip Details</b><span data-entry-summary></span><i class="bi bi-chevron-down" aria-hidden="true"></i></summary><div class="oh-entry-body">${i ? '<button type="button" class="btn btn-sm btn-outline-danger oh-remove" data-remove>Remove Entry</button>' : ''}<div class="oh-form-grid">${UI.fields([
          { name: 'client_id', label: 'Client', type: 'select', required: true, col: 12, value: e.data.client_id, options: clients.map(c => [c.id, c.company_name]) },
          { type: 'html', col: 12, html: '<input type="hidden" name="truck_type" value="'+UI.esc(e.data.truck_type||'')+'">' },
          { name: 'client_route_id', label: 'Approved Route', type: 'select', required: true, col: 12, options: [], value: '' },
          { name: 'scheduled_at', label: 'Scheduled Date & Time', type: 'datetime', required: true, col: 12, value: e.data.scheduled_at, attrs: `min="${UI.nowManila()}" max="${UI.nowManila(365 * 1440)}"` },
          { type: 'html', col: 6, html: `<label class="form-label" for="qty-${e.id}">Number of Required Trucks</label><div class="oh-stepper"><button type="button" class="btn btn-outline-secondary" data-minus aria-label="Decrease number of required trucks">−</button><input id="qty-${e.id}" name="quantity" type="number" class="form-control" min="1" max="20" step="1" required value="${e.data.quantity}"><button type="button" class="btn btn-outline-secondary" data-plus aria-label="Increase number of required trucks">+</button></div>` },
          { name: 'dispatcher_assignment', label: 'Dispatcher Shift', type: 'select', required: true, col: 6, placeholder: 'Choose shift...', options: dispatcherOptions(e.data.truck_type).map(([value]) => [value, value.split(':').slice(1).join(':')]), value: e.data.dispatcher_assignment, attrs: `aria-describedby="dispatcher-error-${e.id}"` },
          { name: 'responsible_dispatcher_id', label: 'Responsible Dispatcher', type: 'select', required: true, col: 12, options: accountOptions(e.data).map(a => [a.id,a.full_name]), value: e.data.responsible_dispatcher_id },
          { name: 'delivery_details', label: 'Delivery Instructions', type: 'textarea', rows: 2, col: 12, value: e.data.delivery_details, attrs: 'maxlength="500"' },
        ])}</div><p class="small text-muted mb-0" data-route-state role="status"></p></div>`;
        list.append(card);
        card.open=true;card.querySelector('[data-entry-label]').textContent=editingId?'Edit Trip Entry':'Create Trip Entry';
        const grid=card.querySelector('.oh-form-grid'),fields=[...grid.querySelector('.row').children];
        grid.replaceChildren();
        // One compact form replaces the three tall numbered cards. Preserve
        // original input elements and names so existing handlers/validation work.
        const main = document.createElement('section');
        main.className = 'oh-compact-trip-fields';
        main.innerHTML = '<div class="row g-2"></div>';
        main.querySelector('.row').append(...fields.slice(0,7));
        grid.append(main);
        const instructions = document.createElement('details');
        instructions.className = 'oh-compact-instructions';
        instructions.open = !!String(e.data.delivery_details || '').trim();
        instructions.innerHTML = '<summary><i class="bi bi-file-text" aria-hidden="true"></i> Additional Instructions (optional)<i class="bi bi-chevron-down" aria-hidden="true"></i></summary><div class="row g-2"></div>';
        instructions.querySelector('.row').append(fields[7]);
        // Keep the accessible textarea label, but avoid repeating the
        // disclosure's title inside the expanded content.
        const instructionsLabel = instructions.querySelector('label');
        if (instructionsLabel) instructionsLabel.classList.add('visually-hidden');
        const instructionsInput = instructions.querySelector('[name=delivery_details]');
        if (instructionsInput) instructionsInput.placeholder = 'Pickup instructions, special handling, or other details…';
        grid.append(instructions);
        // UI.fields uses generic IDs. Each independent card receives unique accessible IDs.
        card.querySelectorAll('[name]').forEach(input => {
          const oldId = input.id; input.id = `${e.id}-${input.name}`;
          if (oldId) card.querySelectorAll('label').forEach(l => { if (l.htmlFor === oldId) l.htmlFor = input.id; });
          input.oninput = input.onchange = () => { e.data[input.name] = input.value; update(); };
        });
        const pick = card.querySelector('[name=client_route_id]'), note = card.querySelector('[data-route-state]');
        // Keep the approved route select as the form's source of truth and
        // preserve the existing validation/API payload. A searchable, two-line
        // picker is only a presentation layer over its existing change handler.
        pick.classList.add('oh-route-native-select');
        const routePicker = document.createElement('div');
        routePicker.className = 'oh-approved-route-picker';
        routePicker.innerHTML = '<button type="button" class="oh-route-picker-toggle" aria-haspopup="listbox" aria-expanded="false" aria-label="Choose approved route"><span class="oh-route-picker-selected"><span>Choose an approved route</span></span><i class="bi bi-chevron-down" aria-hidden="true"></i></button>'
          + '<div class="oh-route-picker-popover" hidden><div class="oh-route-picker-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" class="form-control" placeholder="Search route ID or truck type" aria-label="Search approved routes"></div><div class="oh-route-picker-options" role="listbox" aria-label="Approved routes"></div></div>';
        pick.insertAdjacentElement('afterend', routePicker);
        const routeToggle = routePicker.querySelector('.oh-route-picker-toggle');
        const routePopup = routePicker.querySelector('.oh-route-picker-popover');
        const routeSearch = routePicker.querySelector('input[type=search]');
        const routeOptions = routePicker.querySelector('.oh-route-picker-options');
        const closeRoutePicker = () => {
          routePopup.hidden = true;
          routeToggle.setAttribute('aria-expanded', 'false');
          routePicker.classList.remove('is-open');
        };
        const renderRouteOptions = () => {
          const term = routeSearch.value.toLocaleLowerCase().trim();
          const routes = e.routes.filter(r =>
            [r.route_ref,r.truck_type,r.origin_label,r.destination_label].some(value => String(value || '').toLocaleLowerCase().includes(term)));
          routeOptions.replaceChildren();
          if (!routes.length) {
            const empty = document.createElement('p');
            empty.className = 'oh-route-picker-empty';
            empty.textContent = e.loading ? 'Loading approved routes…' : e.routes.length ? 'No matching approved routes.' : 'No approved routes available.';
            routeOptions.append(empty);
            return;
          }
          routes.forEach(r => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'oh-route-picker-option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', String(String(r.id) === String(pick.value)));
            option.innerHTML = '<span class="oh-route-picker-option-text"><strong>' + UI.esc(r.route_ref)
              + '</strong><small><i class="bi bi-truck" aria-hidden="true"></i>' + UI.esc(r.truck_type || 'Truck type unavailable')
              + '</small></span><i class="bi bi-check2 oh-route-picker-check" aria-hidden="true"></i>';
            option.onclick = () => {
              pick.value = String(r.id);
              pick.dispatchEvent(new Event('change', { bubbles:true }));
              closeRoutePicker();
              routeToggle.focus();
            };
            routeOptions.append(option);
          });
        };
        const refreshRoutePicker = () => {
          const selected = e.routes.find(r => String(r.id) === String(pick.value));
          routeToggle.querySelector('.oh-route-picker-selected').innerHTML = selected
            ? '<strong>' + UI.esc(selected.route_ref) + '</strong><small><i class="bi bi-truck" aria-hidden="true"></i>' + UI.esc(selected.truck_type || 'Truck type unavailable') + '</small>'
            : '<span>' + UI.esc(e.loading ? 'Loading approved routes…' : !e.data.client_id ? 'Choose a client first' : 'Choose an approved route') + '</span>';
          routeToggle.disabled = pick.disabled;
          if (routeToggle.disabled) closeRoutePicker();
          if (!routePopup.hidden) renderRouteOptions();
        };
        routeToggle.onclick = () => {
          if (routeToggle.disabled) return;
          if (!routePopup.hidden) { closeRoutePicker(); return; }
          routeSearch.value = '';
          renderRouteOptions();
          routePopup.hidden = false;
          routePicker.classList.add('is-open');
          routeToggle.setAttribute('aria-expanded', 'true');
          routeSearch.focus();
        };
        routeSearch.oninput = renderRouteOptions;
        routeSearch.onkeydown = event => {
          if (event.key === 'Escape') { closeRoutePicker(); routeToggle.focus(); }
          if (event.key === 'ArrowDown') { event.preventDefault(); routeOptions.querySelector('button')?.focus(); }
        };
        routeOptions.onkeydown = event => {
          const options = [...routeOptions.querySelectorAll('button')];
          const index = options.indexOf(document.activeElement);
          if (event.key === 'Escape') { closeRoutePicker(); routeToggle.focus(); }
          if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            (options[index + (event.key === 'ArrowDown' ? 1 : -1)] || (event.key === 'ArrowDown' ? options[0] : routeSearch))?.focus();
          }
        };
        card.addEventListener('pointerdown', event => {
          if (!routePicker.contains(event.target)) closeRoutePicker();
        });
        const truckField = card.querySelector('[name=truck_type]');
        const dispatcher = card.querySelector('[name=dispatcher_assignment]');
        dispatcher.insertAdjacentHTML('afterend',`<div id="dispatcher-error-${e.id}" class="invalid-feedback" data-dispatcher-error hidden>Select an available shift for the selected route.</div><div class="oh-dispatcher-team-note" data-dispatcher-team role="status"></div>`);
        const dispatcherError = card.querySelector('[data-dispatcher-error]');
        const quantityField = card.querySelector('.oh-stepper').parentElement;
        quantityField.className = 'col-md-6 oh-planner-quantity-field';
        dispatcher.closest('.col-md-6')?.classList.add('oh-planner-dispatcher-field');
        const schedule=card.querySelector('[name=scheduled_at]'),scheduleField=schedule.parentElement;
        scheduleField.querySelector('label').remove();schedule.type='hidden';
        scheduleField.insertAdjacentHTML('beforeend',`<div class="row g-2"><div class="col-md-6"><label class="form-label req" for="date-${e.id}">Scheduled Dispatch Date</label><input id="date-${e.id}" type="date" class="form-control" required data-schedule-date min="${UI.nowManila().slice(0,10)}" max="${UI.nowManila(365*1440).slice(0,10)}" value="${UI.esc((e.data.scheduled_at||'').split('T')[0])}"></div><div class="col-md-6"><label class="form-label req" for="time-${e.id}">Scheduled Dispatch Time</label><input id="time-${e.id}" type="time" class="form-control" required data-schedule-time value="${UI.esc((e.data.scheduled_at||'').split('T')[1]||'')}"></div></div>`);
        [card.querySelector('[data-schedule-date]'),card.querySelector('[data-schedule-time]')].forEach(input=>input.oninput=input.onchange=()=>{schedule.value=card.querySelector('[data-schedule-date]').value&&card.querySelector('[data-schedule-time]').value?card.querySelector('[data-schedule-date]').value+'T'+card.querySelector('[data-schedule-time]').value:'';e.data.scheduled_at=schedule.value;update();});
        card.querySelector('label[for="'+card.querySelector('[name=quantity]').id+'"]').textContent='Required Trucks';
        card.querySelector('label[for="'+dispatcher.id+'"]').textContent='Dispatcher Shift';card.querySelector('label[for="'+card.querySelector('[name=delivery_details]').id+'"]').textContent='Additional Instructions (optional)';
        const account = card.querySelector('[name=responsible_dispatcher_id]');
        account.parentElement?.classList.add('oh-planner-account-field');
        account.insertAdjacentHTML('afterend','<div class="small text-muted oh-planner-account-hint" data-account-state role="status"></div>');
        const fillAccounts = () => {
          const options = accountOptions(e.data);
          if(!options.some(a => String(a.id) === String(e.data.responsible_dispatcher_id)))e.data.responsible_dispatcher_id = '';
          account.replaceChildren(new Option('Choose responsible account...', ''), ...options.map(a => new Option(a.full_name, a.id)));
          account.value = e.data.responsible_dispatcher_id || '';
          account.disabled = !options.length;
          card.querySelector('[data-account-state]').textContent = !e.data.dispatcher_assignment ? 'Select a dispatcher shift to see available accounts.' : !options.length ? 'No active dispatcher account is available for this group and shift. Contact Admin.' : 'Select the dispatcher responsible for this trip.';
        };
        const fillDispatchers = () => {
          const options = dispatcherOptions(e.data.truck_type);
          if(!options.some(([value]) => value === e.data.dispatcher_assignment))e.data.dispatcher_assignment = '';
          dispatcher.innerHTML = '<option value="">Choose shift...</option>' + options.map(([value]) => `<option value="${UI.esc(value)}">${UI.esc(value.split(':').slice(1).join(':'))}</option>`).join('');
          dispatcher.value = e.data.dispatcher_assignment || '';
          dispatcher.disabled = !options.length;
          const team = card.querySelector('[data-dispatcher-team]');
          if (team) team.textContent = !e.data.truck_type ? 'Dispatcher team follows the approved route.' : 'Team: ' + (e.data.truck_type === 'Car Carrier' ? 'Car Carrier' : 'Container / Wing Van');
          dispatcher.classList.remove('is-invalid');dispatcher.removeAttribute('aria-invalid');dispatcherError.hidden = true;dispatcherError.style.display='';
          fillAccounts();
        };
        dispatcher.onchange = dispatcher.oninput = () => { e.data.dispatcher_assignment = dispatcher.value;dispatcher.classList.remove('is-invalid');dispatcher.removeAttribute('aria-invalid');dispatcherError.hidden = true;dispatcherError.style.display='';fillAccounts();update(); };
        fillDispatchers();
        const fillRoutes = () => {
          pick.innerHTML = '<option value="">Choose an approved route</option>' + e.routes.map(r => `<option value="${+r.id}">${UI.esc(r.route_ref)}</option>`).join('');
          pick.value = e.data.client_route_id || ''; pick.disabled = e.loading || !e.data.client_id;
          refreshRoutePicker();
          const routeInfo = card.querySelector('[data-approved-rate]');
          const selected = e.route;
          routeInfo.hidden = !selected;
          if (selected) {
            routeInfo.innerHTML =
              '<div class="oh-trip-route-card oh-trip-route-card-journey">'
              +'<div class="oh-trip-route-card-label"><i class="bi bi-signpost-split" aria-hidden="true"></i> ROUTE</div>'
              +'<div class="oh-trip-route-path">'
              +'<div class="oh-trip-route-stop"><span class="oh-trip-route-stop-marker start" aria-hidden="true"></span><div><small>PICKUP</small><strong>'+UI.esc(selected.origin_label || 'Pickup not specified')+'</strong></div></div>'
              +'<div class="oh-trip-route-rail" aria-hidden="true"></div>'
              +'<div class="oh-trip-route-stop"><span class="oh-trip-route-stop-marker finish" aria-hidden="true"></span><div><small>DESTINATION</small><strong>'+UI.esc(selected.destination_label || 'Destination not specified')+'</strong></div></div>'
              +'</div></div>'
              +'<div class="oh-trip-route-card oh-trip-route-card-distance">'
              +'<div class="oh-trip-route-card-label"><i class="bi bi-map" aria-hidden="true"></i> DISTANCE</div>'
              +'<div class="oh-trip-route-card-value">'+UI.num(selected.distance_km,2)+'<small>km</small></div></div>'
              +'<div class="oh-trip-route-card oh-trip-route-card-truck">'
              +'<div class="oh-trip-route-card-label"><i class="bi bi-truck" aria-hidden="true"></i> TRUCK TYPE</div>'
              +'<div class="oh-trip-route-card-value oh-trip-route-truck-name">'+UI.esc(selected.truck_type || '—')+'</div>'
              +'<small class="oh-trip-route-auto">Auto-selected</small></div>';
          } else routeInfo.replaceChildren();
        };
        const loadRoutes = async (resetSelection = false) => {
          const seq = ++e.seq;
          const priorRouteId = resetSelection ? '' : String(e.data.client_route_id || '');
          if (resetSelection) {
            e.data.client_route_id = ''; e.route = null; e.data.truck_type = '';
            e.data.dispatcher_assignment = ''; e.data.responsible_dispatcher_id = '';
            truckField.value = ''; fillDispatchers();
          }
          e.routes = [];
          if (!e.data.client_id) { e.loading = false; e.routeMessage = 'Choose a client first.'; e.refresh(); return; }
          e.loading = true; e.routeMessage = 'Loading approved routes…'; e.refresh();
          try {
            const response = await App.api('Routes.selectable', { client_id: e.data.client_id });
            if (seq !== e.seq) return;
            e.routes = response.routes || [];
            e.route = e.routes.find(route => String(route.id) === priorRouteId) || null;
            e.data.client_route_id = e.route ? String(e.route.id) : '';
            e.data.truck_type = e.route?.truck_type || '';
            truckField.value = e.data.truck_type;
            if (!e.route) { e.data.dispatcher_assignment = ''; e.data.responsible_dispatcher_id = ''; }
            fillDispatchers();
            e.routeMessage = e.routes.length ? '' : 'No approved routes are available for this client. Request a route through Client Routes before creating a trip.';
          } catch (ex) { if (seq === e.seq) e.routeMessage = ex.message + ' Change the client to retry.'; }
          finally { if (seq === e.seq) { e.loading = false; e.refresh();update(); } }
        };
        card.querySelector('[name=client_id]').onchange = event => {
          e.data.client_id = event.target.value;
          loadRoutes(true); update();
        };
        pick.onchange = () => {
          e.data.client_route_id = pick.value;
          e.route = e.routes.find(r => String(r.id) === pick.value) || null;
          e.data.truck_type = e.route?.truck_type || '';
          truckField.value = e.data.truck_type;
          e.data.dispatcher_assignment = '';
          e.data.responsible_dispatcher_id = '';
          fillDispatchers(); e.refresh(); update();
        };
        card.querySelector('[data-minus]').onclick = () => { e.data.quantity = Math.max(1, (+e.data.quantity || 1) - 1); card.querySelector('[name=quantity]').value = e.data.quantity; update(); };
        card.querySelector('[data-plus]').onclick = () => { if (total() >= 20) return error('Maximum combined quantity is 20 trips.'); e.data.quantity = (+e.data.quantity || 1) + 1; card.querySelector('[name=quantity]').value = e.data.quantity; update(); };
        card.querySelector('[data-remove]')?.addEventListener('click', () => { e.seq++; entries = entries.filter(x => x !== e); renderPlanning(); });
        e.refresh = () => { fillRoutes(); note.textContent = e.routeMessage || ''; };
        note.insertAdjacentHTML('afterend','<div class="oh-trip-route-summary" data-approved-rate aria-live="polite" hidden></div>');
        pick.parentElement.append(note,card.querySelector('[data-approved-rate]'));
        e.refresh();
        // Restore an existing entry's selectable routes when returning from Review.
        if(e.data.client_id && !e.routes.length && !e.loading)loadRoutes();
      });
      ctx.el.querySelector('[data-clear]').onclick = async () => {
        if (dirty(current())&&!await UI.confirmProceed('Clear Entry','Discard the current unsaved entry?','Clear Entry'))return;
        const entry=current();if(entry)entry.seq++;
        if(editingId&&editingBackup)Object.assign(entry,editingBackup);else entries=entries.filter(e=>e!==entry);
        editingId=null;editingBackup=null;add();
      };
      ctx.el.querySelector('form').onsubmit = event => {
        event.preventDefault(); error('');
        const reviewImmediately = event.submitter?.hasAttribute('data-add-review') || false;
        if (entries.some(e => e.loading)) return error('Wait for approved routes to finish loading.');
        const entry=current();if(!entry)return;
        if (total()+(entry.queued===false?+entry.data.quantity:0)>20 || !Number.isInteger(+entry.data.quantity) || +entry.data.quantity < 1) return error('Use whole quantities of at least 1, with at most 20 trips combined.');
        const schedule=ctx.el.querySelector('[name=scheduled_at]');
        if(schedule.value&&(schedule.value<schedule.min||schedule.value>schedule.max))return error('Use an allowed schedule.');
        const invalid = [...ctx.el.querySelectorAll('input,select,textarea')].find(input => !['dispatcher_assignment','responsible_dispatcher_id'].includes(input.name) && !input.disabled && !input.checkValidity());
        if (invalid) { invalid.closest('details').open = true; invalid.reportValidity(); return; }
        const missingDispatcher = [entry].find(e => !dispatcherOptions(e.data.truck_type).some(([value]) => value === e.data.dispatcher_assignment));
        if(missingDispatcher){const card=ctx.el.querySelector(`[data-entry="${missingDispatcher.id}"]`), select=card.querySelector('[name=dispatcher_assignment]'), feedback=card.querySelector('[data-dispatcher-error]');card.open=true;select.classList.add('is-invalid');select.setAttribute('aria-invalid','true');feedback.hidden=false;feedback.style.display='block';select.focus();return error('Choose an available dispatcher shift for every trip entry.');}
        if(!accountOptions(entry.data).length)return error('No active Dispatcher account exists for the selected group and shift. Ask Admin to configure an authorized account.');
        if(!accountOptions(entry.data).some(a=>String(a.id)===String(entry.data.responsible_dispatcher_id)))return error('Choose a responsible Dispatcher account explicitly before adding this entry.');
        for (const e of [entry]) {
          if (!e.route || String(e.route.id) !== String(e.data.client_route_id)) return error('Select an approved route for every trip entry.');
          if (e.data.scheduled_at < UI.nowManila(-5) || e.data.scheduled_at > UI.nowManila(365 * 1440)) return error('Use an allowed schedule.');
          const c = clients.find(c => String(c.id) === String(e.data.client_id));
          if (c?.rate_types != null && !c.rate_types.split('|').includes(e.data.truck_type)) return error(`${c.company_name} has no existing billing rate for ${e.data.truck_type}. Billing must set it before trip creation.`);
        }
        entry.queued=true;editingId=null;editingBackup=null;add();
        // Single-request path reuses the batch review, conflict checks, and
        // idempotent submission logic instead of introducing a second API flow.
        if (reviewImmediately) ctx.el.querySelector('[data-review-batch]')?.click();
      };
      ctx.el.querySelector('[data-review-batch]').onclick=()=>{
        if(!batch().length||busy)return;
        if(dirty(current()))return error('Add or clear the current entry before reviewing the planning batch.');
        // Preserve row identity and overrides when returning to planning; drop only removed rows.
        drafts = batch().flatMap(e => Array.from({ length: +e.data.quantity }, (_, index) => {
          const old = drafts.find(x => x.entry === e.id && x.index === index);
          const supported = ['booking_reference', 'consignee', ...(e.data.truck_type === 'Car Carrier' ? ['units_planned'] : ['shipment_number']), ...(e.data.truck_type === 'Container' ? ['container_number', 'container_size', 'seal_number'] : [])];
          const overrides = Object.fromEntries(Object.entries(old?.overrides || {}).filter(([name]) => supported.includes(name)));
          return { key: old?.key || UI.uuid(), entry: e.id, index, overrides, data: { ...e.data, quantity: undefined,
            pickup_point: e.route.origin_label, delivery_point: e.route.destination_label, distance_km: e.route.distance_km,
            route_option: e.route.route_option || '', pickup_lat: e.route.origin_lat, pickup_lng: e.route.origin_lng, delivery_lat: e.route.destination_lat, delivery_lng: e.route.destination_lng } };
        })); renderReview();
      };
      update();
    };
    const renderReview = () => {
      document.querySelector('.oh-trip-planner-modal')?.classList.add('oh-trip-review-mode');
      ctx.el.innerHTML = `<div class="oh-planner">${stages(2)}<div class="alert alert-danger" data-plan-error role="alert" hidden></div><form data-review-form><div class="oh-review-head"><h2>Review &amp; Create</h2><b>${drafts.length} Individual Trips</b></div><div class="table-responsive"><table class="data-table"><thead><tr><th>Client / Route</th><th>Truck Type</th><th>Required Trucks</th><th>Scheduled Dispatch</th><th>Assigned Dispatcher</th></tr></thead><tbody>${batch().map(e=>`<tr><td>${UI.esc(clientName(e.data.client_id))}<div>${UI.esc(e.route?.origin_label)} → ${UI.esc(e.route?.destination_label)}</div></td><td>${UI.esc(e.data.truck_type)}</td><td>${+e.data.quantity}</td><td>${this.datetime(e.data.scheduled_at)}</td><td>${UI.esc(dispatcherName(e.data))}</td></tr>`).join('')}</tbody></table></div><div data-review-entries></div><p data-submission-note role="status">Create the reviewed trips for the selected Dispatcher group/shift. The Dispatcher will manually select each Truck and its fixed Driver after creation. Individual shipment details remain editable below.</p><div class="oh-form-footer oh-trip-planner-actions"><button type="button" class="btn btn-outline-secondary" data-back ${attempted ? 'disabled' : ''}>Back to Planning</button>${attempted && !uncertain ? '<button type="button" class="btn btn-outline-secondary" data-start-another>Start Another Plan</button>' : ''}<button type="submit" class="btn btn-primary" data-confirm>Create Trips</button></div></form></div>`;
      const list = ctx.el.querySelector('[data-review-entries]');
      batch().forEach((e, i) => {
        const section = document.createElement('section'); section.className = 'oh-panel';
        section.innerHTML = `<div class="oh-panel-head"><h3>Planning Entry ${i + 1} · ${e.data.quantity} Required Truck(s)</h3></div><div class="oh-panel-body"><p><b>${UI.esc(clientName(e.data.client_id))} · ${UI.esc(e.data.truck_type)}</b><br>${UI.esc(e.route.origin_label)} → ${UI.esc(e.route.destination_label)}<br>${this.datetime(e.data.scheduled_at)}<br><span data-review-dispatcher>${UI.esc(dispatcherName(e.data))} / ${UI.esc(accountName(e.data))}</span><br>${UI.esc(e.data.delivery_details || 'No delivery instructions.')}</p><div data-drafts></div></div>`;
        list.append(section);
        drafts.filter(x => x.entry === e.id).forEach(x => {
          const row = document.createElement('details'); row.className = 'oh-draft'; row.open = !x.result?.trip_id;
          const fields = [ ['booking_reference', 'Booking Reference', 150], ['consignee', 'Consignee / Brand', 120],
            ...(e.data.truck_type === 'Car Carrier' ? [['units_planned', 'Cargo Units', 100]] : [['shipment_number', 'Shipment Number', 60]]),
            ...(e.data.truck_type === 'Container' ? [['container_number', 'Container Number', 60], ['container_size', 'Container Size', 20], ['seal_number', 'Seal Number', 60]] : []),
          ];
          row.innerHTML = `<summary>Draft Trip ${drafts.indexOf(x) + 1} <span data-row-result>${x.result ? UI.esc(x.result.result) + ' · ' + UI.esc(x.result.trip_ref || x.result.message || '') : 'Awaiting confirmation'}</span></summary><div class="oh-form-grid">${UI.fields(fields.map(([name, label, max]) => ({ name, label, col: 6, type: name === 'units_planned' ? 'number' : 'text', min: 1, attrs: name === 'units_planned' ? 'max="100" step="1"' : `maxlength="${max}"`, value: x.overrides[name] || '' })))}</div>${x.result?.trip_id ? `<button type="button" class="btn btn-link" data-oh-trip="${+x.result.trip_id}">View ${UI.esc(x.result.trip_ref)} · ${UI.esc(x.result.trip_status)}</button>` : ''}`;
          section.querySelector('[data-drafts]').append(row);
          row.querySelectorAll('[name]').forEach(input => { const old = input.id; input.id = `${x.key}-${input.name}`; row.querySelectorAll('label').forEach(l => { if (l.htmlFor === old) l.htmlFor = input.id; }); input.disabled = !!x.result?.trip_id || uncertain; input.oninput = () => x.overrides[input.name] = input.value; });
        });
      });
      this.bindTrips(ctx.el);
      ctx.el.querySelector('[data-back]').onclick = renderPlanning;
      ctx.el.querySelector('[data-start-another]')?.addEventListener('click', async () => {
        if (drafts.some(x => !x.result?.trip_id) && !await UI.confirmProceed('Start Another Plan', 'Discard the failed drafts? Created trips will remain in Trips.', 'Start Another Plan')) return;
        try { sessionStorage.removeItem(recoveryKey); } catch { /* Storage is optional. */ }
        entries = []; drafts = []; attempted = false; uncertain = false; add();
      });
      const confirm = ctx.el.querySelector('[data-confirm]');
      confirm.disabled = drafts.every(x => x.result?.trip_id);
      if (attempted) ctx.el.querySelector('[data-submission-note]').textContent = uncertain ? 'The server response was interrupted. Retry these same drafts to safely retrieve existing results; shared values are locked.' : 'Created trips are locked. Retry failed drafts with their original request keys. Start another plan from Trip Planning when finished.';
      ctx.el.querySelector('form').onsubmit = async event => {
        event.preventDefault(); if (busy || confirm.disabled) return;
        const pending = drafts.filter(x => !x.result?.trip_id);
        busy = true; ctx.el.dataset.planBusy = 'true'; attempted = true; confirm.disabled = true; confirm.textContent = 'Creating Trips…';
        uncertain = true; remember();
        ctx.el.querySelectorAll('input,button').forEach(input => input.disabled = true);
        try {
          const response = await App.api('Trips.create_batch', { rows: pending.map(x => ({ ...x.data, ...x.overrides, request_key: x.key, row_no: drafts.indexOf(x) + 1 })) });
          if (!Array.isArray(response.results) || pending.some(x => !response.results.some(r => r.request_key === x.key))) throw new Error('Incomplete submission response. Retry to retrieve the results safely.');
          response.results.forEach(r => { const x = drafts.find(x => x.key === r.request_key); if (x) x.result = r; });
          uncertain = false; remember(); renderReview();
          ctx.el.querySelector('[data-submission-note]').textContent = response.message || 'Submission results are shown for each trip.';
        } catch (ex) {
          // A validated 400 is the existing batch endpoint's preflight refusal: no new rows were created.
          // Network/5xx outcomes stay locked until a retry reconciles the original keys.
          const preflight = ex.status === 400;
          uncertain = !preflight;
          if (preflight && drafts.every(x => !x.result?.trip_id)) attempted = false;
          remember();
          renderReview(); error(ex.message + (preflight ? ' Correct the entries before retrying.' : ' Retry with the same request keys.'));
        }
        finally { busy = false; ctx.el.dataset.planBusy = 'false'; }
      };
    };
    try {
      const saved = JSON.parse(sessionStorage.getItem(recoveryKey) || 'null');
      if (saved?.attempted && Array.isArray(saved.entries) && Array.isArray(saved.drafts) && saved.drafts.length && saved.drafts.length <= 20) {
        entries = saved.entries; drafts = saved.drafts; attempted = true; uncertain = !!saved.uncertain;
        renderReview(); return;
      }
    } catch { /* Ignore invalid/unsupported local recovery data. */ }
    add();
  },
};

// Install presentation only after the shared modules have registered their existing pages.
(() => {
  const shell = App.shell;
  App.shell = function() {
    shell.call(this);
    document.body.classList.toggle('operations-head', OpsHead.is());
    if (!OpsHead.is()) return;
    const allowed = new Map(this.me.nav.map(n => [n.id, n]));
    const item = (id, label, icon, query = {}, section = '') => allowed.has(id) ? `<a class="nav-item-link" href="${OpsHead.link(id, query)}" data-nav="${id}" ${section ? `data-ops-section="${section}"` : ''}><i class="bi ${icon}" aria-hidden="true"></i><span>${label}</span></a>` : '';
    const sections = [ ['Overview', [item('dashboard', 'Dashboard', 'bi-grid-1x2')]], ['Trip Operations', [item('trips', 'Trips', 'bi-signpost-split', { tab: 'active' }, 'management'), item('dispatcher-monitoring', 'Dispatch Monitoring', 'bi-person-workspace'), item('funds', 'Fund Approvals', 'bi-cash-coin')]], ['Masterlists', [item('trucks', 'Trucks', 'bi-truck'), item('crew', 'Drivers & Helpers', 'bi-person-badge'), item('clients', 'Clients', 'bi-building'), item('routes', 'Client Routes', 'bi-signpost-2')]], ['Monitoring & Records', [item('trips', 'Incidents & Repairs', 'bi-wrench-adjustable', { tab: 'incidents' }, 'incidents'), item('reports', 'Reports', 'bi-bar-chart-line'), item('documents', 'Documents', 'bi-folder2-open')]] ];
    // Former extras were Mechanics and Corrective Maintenance, both read-only for this role.
    // Operational coordination remains under Trips > Incidents. Backend access policy is unchanged.
    // This role-specific shell runs AFTER App.shell: render the same native
    // collapsible groups instead of overwriting them with static headings.
    // Retain the Operations Head deep links and data-ops-section selectors.
    const groupIcons = {
      'Overview': 'bi-grid-1x2',
      'Trip Operations': 'bi-signpost-split',
      'Masterlists': 'bi-truck',
      'Monitoring & Records': 'bi-clipboard-data'
    };
    document.querySelector('.sidebar-nav').innerHTML = sections.map(([label, links]) => {
      const entries = links.filter(Boolean);
      if (!entries.length) return '';
      return '<details class="head-nav-group ops-head-nav-group" data-sidebar-group="'+UI.esc(label)+'">'
        +'<summary><i class="bi '+groupIcons[label]+'" aria-hidden="true"></i><span>'+UI.esc(label)+'</span>'
        +'<i class="bi bi-chevron-down head-nav-chevron" aria-hidden="true"></i></summary>'
        +'<div class="head-nav-items">'+entries.join('')+'</div></details>';
    }).join('');
    // Collapse and flyout behavior is owned by the shared executive sidebar
    // controller in core.js; do not restore the old hide-entire-sidebar toggle.
  };
  const drawer = UI.drawer;
  UI.drawer = (...args) => {
    const previous = document.querySelector('.offcanvas.app-drawer');
    previous?.querySelector('.oh-drawer-footer')?.remove(); previous?.classList.remove('oh-trip-drawer');
    return drawer(...args);
  };
  const openTrip = TripUI.open;
  TripUI.open = async function(id) {
    if (OpsHead.is() && !TripUI.data) {
      try { TripUI.data = await App.data('trips'); } catch (ex) { return UI.toast(ex.message, 'error'); }
    }
    return openTrip.call(this, id);
  };
  // The accounting Funds page is the single source of truth for fund-request tabs,
  // actions and reusable record components. Do not replace it with the legacy
  // Operations Head-specific renderer (which had obsolete Pending/Approved/etc tabs).
  const originalFundsRender = App.pages.funds.render;
  App.pages.funds.render = function(ctx) {
    if (!OpsHead.is()) return originalFundsRender.call(this, ctx);
    ctx.setTitle('Fund Approvals', 'Review and approve dispatcher fund requests.');
    const result = originalFundsRender.call(this, ctx);
    // Preserve older deep links to an individual request without duplicating the page.
    if (ctx.query.open) OpsHead.openFund(UI.takeQuery(ctx, 'open').open);
    return result;
  };
  for (const [page, render] of Object.entries({ dashboard: ctx => OpsHead.dashboard(ctx), trips: ctx => OpsHead.trips(ctx), routes: ctx => OpsHead.routes(ctx), crew: ctx => OpsHead.crew(ctx) })) {
    const original = App.pages[page].render;
    App.pages[page].render = function(ctx) { if(!OpsHead.is())return original.call(this,ctx);const titles={trips:'Trips',crew:'Drivers & Helpers',routes:'Client Routes',dashboard:'Dashboard'};ctx.setTitle(titles[page]);return render(ctx); };
  }
  for(const page of ['trucks','clients','documents']){const original=App.pages[page].render;App.pages[page].render=function(ctx){if(OpsHead.is()&&page==='trucks')return OpsHead.trucks(ctx,context=>original.call(this,context));const result=original.call(this,ctx);if(OpsHead.is()){if(page==='trucks'||page==='clients')ctx.setTitle(page==='trucks'?'Trucks':'Clients');if(page==='documents')OpsHead.masterlist(ctx,page);}return result;};}
})();
