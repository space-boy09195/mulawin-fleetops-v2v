/* Operations Head-only, read-only projection of recorded Dispatcher responsibility and progress. */
'use strict';
const DispatcherMonitorUI = {
  async details(id, groups) {
    let data;try{data=await App.api('Trips.monitor_details',{trip_id:id});}catch(error){return UI.toast(error.message,'error');}
    const t=data.trip,group=groups.find(g=>g.role===t.dispatcher_role&&g.shift===(t.dispatcher_shift||null));
    const history=rows=>UI.activity(rows);
    const drawer=UI.drawer('Trip '+UI.esc(t.trip_ref),
      OpsHead.panel('Dispatcher Responsibility',UI.kv([['Original Group / Shift',UI.e(group?.label||t.dispatcher_role+' · '+(t.dispatcher_shift||'Legacy unspecified shift'))],['Recorded Dispatch Account',UI.e(t.dispatcher_name||'No dispatch account recorded')],['Current Responsible Dispatcher',UI.e(t.responsible_dispatcher_name||'Not yet assigned by Operations Head')]]))+
      OpsHead.panel('Trip Information',UI.kv([['Client',UI.e(t.client)],['Approved Route',UI.e(t.client_route_ref)],['Route',UI.esc(t.pickup_point)+' → '+UI.esc(t.delivery_point)],['Truck Type',UI.e(t.truck_type)],['Truck',UI.e(t.plate_number)],['Fixed Driver',UI.e(t.driver_name)],['Driver 2',UI.e(t.driver2_name)],['Actual Trip Helper',UI.e(t.helper_name)],['Helper Replacement Reason',UI.e(t.helper_substitution_reason)],['Scheduled Dispatch',OpsHead.datetime(t.scheduled_at)],['Trip Status',OpsHead.badge(t.status)],['Recorded Dispatch',OpsHead.datetime(t.dispatched_at)],['Recorded Completion',OpsHead.datetime(t.completed_at)]]))+
      OpsHead.panel('Checklists',data.inspections?.length?UI.table([['Kind','kind'],['Attempt','attempt_number'],['Completed','completed_at',v=>OpsHead.datetime(v)],['Findings','has_not_ok',v=>+v?'NOT OK findings recorded':'All OK recorded']],data.inspections,{plain:true}):'<p>No checklist recorded.</p>')+
      OpsHead.panel('Incidents & Corrective Maintenance',data.incidents?.length?UI.table([['Incident','incident_ref'],['Problem','problem'],['Status','status','badge'],['Maintenance','job_ref'],['Repair Status','job_status']],data.incidents,{plain:true}):'<p>No incident recorded.</p>')+
      OpsHead.panel('Documents',UI.tripDocuments(data.documents||[])+UI.docs(data.documents||[]))+
      OpsHead.panel('Recorded Progress',history(data.updates,'update_type'))+
      OpsHead.panel('Assignment & Activity History',history(data.events,'event_type')),
      {subtitle:'Read-only Dispatcher monitoring'});
    UI.drawerSections(drawer,[['overview','Overview',['Dispatcher Responsibility','Trip Information']],['checks','Checklists',['Checklists']],['incidents','Incidents & Maintenance',['Incidents & Corrective Maintenance']],['documents','Documents',['Documents']],['activity','Recorded Activity',['Recorded Progress','Assignment & Activity History']]],{selector:'.oh-panel',titleSelector:'h2',key:'monitoring-details'});
    UI.activate(drawer);
  },
  render(ctx) {
    if(!OpsHead.is()){ctx.el.innerHTML='<p role="alert">Operations Head access is required.</p>';return;}
    ctx.setTitle('Dispatch Monitoring','Original planning groups/shifts, current responsible accounts, and recorded trip progress.');
    const d=ctx.data,q=ctx.query,groups=d.groups||[];
    if(!Array.isArray(d.rows)||!d.summary){ctx.el.innerHTML='<div class="alert alert-warning" role="alert">Monitoring records are unavailable. <button type="button" class="btn btn-link" data-monitor-retry>Retry</button></div>';ctx.el.querySelector('[data-monitor-retry]').onclick=()=>ctx.reload();return;}
    const terminal=['Completed','Cancelled','Rejected'],history=d.tab==='history';
    const compatible=(status,tab)=>!status||(tab==='history'?terminal.includes(status):status!=='Pending Review'&&!terminal.includes(status));
    const statuses=(d.statuses||[]).filter(status=>compatible(status,d.tab));
    // `page` selects the authorized API screen; monitoring pagination uses its own key.
    const href=next=>{const values={...q,tab:d.tab,...next,trip_page:1};if(!compatible(values.status,values.tab))delete values.status;for(const key of Object.keys(values))if(values[key]==null||values[key]==='')delete values[key];return OpsHead.link('dispatcher-monitoring',values);};
    const roleOptions=[...new Map(groups.map(g=>[g.role,g.label.split(' - ')[0]])).entries()];

    const groupCards=groups.map((g,i)=>'<a class="oh-dispatch-shift-card" '+(q.group===g.role&&q.shift===(g.shift||'legacy')?'aria-current="page" ':'')+'href="'+UI.esc(href({group:g.role,shift:g.shift||'legacy'}))+'"><div class="oh-dispatch-shift-head"><b>'+UI.esc(g.label||('Dispatcher group '+(i+1)))+'</b><span>'+UI.esc(g.shift||'Legacy')+'</span></div><div class="oh-dispatch-shift-stats"><span>Active <b>'+UI.num(g.active)+'</b></span><span>Completed <b>'+UI.num(g.completed)+'</b></span></div></a>').join('');
    const option=(value,label,current)=>'<option value="'+UI.esc(value)+'"'+(String(value)===String(current||'')?' selected':'')+'>'+UI.esc(label)+'</option>';
    const statusOptions=statuses.map(v=>option(v,v,q.status)).join('');
    const controls='<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger" aria-controls="monitor-filter-panel" aria-expanded="false"><i class="bi bi-funnel"></i> Filter</button>'
      +'<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger" aria-controls="monitor-sort-panel" aria-expanded="false"><i class="bi bi-arrow-down-up"></i> Sort</button>'
      +'<div class="record-workspace-popovers"><div id="monitor-filter-panel" class="record-workspace-popover oh-dispatch-filter-panel" hidden><strong>Filter Trips</strong>'
      +'<label>Dispatcher Group<select name="group" class="form-select form-select-sm">'+option('','All groups',q.group)+roleOptions.map(([v,l])=>option(v,l,q.group)).join('')+'</select></label>'
      +'<label>Shift<select name="shift" class="form-select form-select-sm">'+option('','All shifts',q.shift)+[...new Set(groups.map(g=>g.shift||'legacy'))].map(v=>option(v,v,q.shift)).join('')+'</select></label>'
      +'<label>Trip Status<select name="status" class="form-select form-select-sm">'+option('','All statuses',q.status)+statusOptions+'</select></label>'
      +'<div class="record-workspace-date-range"><label>From<input name="from" class="form-control form-control-sm" type="date" value="'+UI.esc(q.from||'')+'"></label><label>To<input name="to" class="form-control form-control-sm" type="date" value="'+UI.esc(q.to||'')+'"></label></div>'
      +'<div class="record-workspace-popover-actions"><button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button><button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button></div></div>'
      +'<div id="monitor-sort-panel" class="record-workspace-popover record-workspace-sort-panel" hidden><strong>Sort Visible Trips</strong>'
      +[['default','Default order'],['newest','Newest scheduled'],['oldest','Oldest scheduled'],['client','Client'],['status','Status']].map(([v,l],i)=>'<label class="record-workspace-sort-option"><input type="radio" data-record-sort name="monitor_sort" value="'+v+'" '+(!i?'checked':'')+'> '+l+'</label>').join('')+'</div></div>';
    const originalRows=d.rows||[];
    const rowHTML=t=>'<tr data-monitor-row data-scheduled="'+UI.esc(t.scheduled_at||'')+'" data-client="'+UI.esc(t.client||'')+'" data-status="'+UI.esc(t.status||'')+'"><td><b>'+UI.esc(t.trip_ref)+'</b><div>'+UI.esc(t.client)+'</div></td><td>'+UI.esc(t.pickup_point)+' → '+UI.esc(t.delivery_point)+'</td><td>'+UI.esc(t.truck_type)+'<div>'+UI.esc(t.plate_number||'Awaiting assignment')+'</div></td><td>'+(history?(t.status==='Completed'&&t.completed_at?OpsHead.datetime(t.completed_at):'Closure date not recorded')+'<div class="cell-sub">Scheduled: '+OpsHead.datetime(t.scheduled_at)+'</div>':OpsHead.datetime(t.scheduled_at))+'</td><td>'+OpsHead.badge(t.status)+'</td><td><button type="button" class="btn btn-sm btn-outline-success" data-monitor-trip="'+(+t.id)+'">View</button></td></tr>';
    const metrics=RecordComponents.kpis([
      {label:'Total Assigned Trips',value:d.summary.total??'—'},
      {label:'Active Trips',value:d.summary.active??'—',color:'green'},
      {label:'Completed Trips',value:d.summary.completed??'—',color:'blue'},
      {label:'Needs Attention',value:d.summary.needs_attention??d.summary.attention??'—',color:'amber'}
    ]);
    ctx.el.innerHTML='<div class="oh-dispatch-workspace">'+metrics
      +'<section class="oh-dispatch-shifts"><div class="oh-dispatch-section-head"><h2>Dispatcher Shift Overview</h2><span>'+UI.num(groups.length)+' groups / shifts</span></div><div class="oh-dispatch-shift-grid">'+(groupCards||'<p class="small text-muted">No dispatcher groups configured.</p>')+'</div></section>'
      +RecordComponents.tabs([{value:'active',label:'Active Trips'},{value:'history',label:'Trip History'}],d.tab,'data-monitor-view')
      +(q.group||q.shift?'<p class="small"><a data-clear-group href="'+UI.esc(href({group:undefined,shift:undefined}))+'">Clear Group</a></p>':'')
      +'<section class="record-workspace-panel oh-dispatch-records"><div class="record-workspace-heading"><div><h2>'+(history?'Trip History Records':'Active Trip Records')+'</h2><p class="record-workspace-count">'+UI.num(d.total)+' matching records</p></div>'
      +'</div><div class="record-workspace-search"><input data-monitor-search aria-label="Search monitored trips" class="form-control" type="search" value="'+UI.esc(q.search||'')+'" placeholder="Search trip, client, route or truck">'+controls+'</div>'
      +'<div class="oh-dispatch-table-wrap"><table class="data-table"><thead><tr><th>Trip / Client</th><th>Route</th><th>Type / Truck</th><th>'+(history?'Completion / Closure':'Scheduled Dispatch')+'</th><th>Status</th><th>Action</th></tr></thead><tbody data-monitor-tbody>'
      +(originalRows.map(rowHTML).join('')||'<tr><td colspan="6" class="table-empty">No trips match these filters.</td></tr>')+'</tbody></table></div>'
      +'<div class="oh-dispatch-pager">'+UI.recordPager({total:d.total,page:d.page,pages:d.pages,pageSize:d.pageSize||12,previous:'data-monitor-prev',next:'data-monitor-next'})+'</div></section></div>';
    const root=ctx.el.querySelector('.oh-dispatch-workspace');
    root.querySelectorAll('[data-monitor-view]').forEach(button=>button.addEventListener('click',()=>{
      const next={...q,tab:button.dataset.monitorView,trip_page:1};
      if(!compatible(next.status,next.tab))delete next.status;
      App.go('dispatcher-monitoring',next);
    }));
    root.querySelector('[data-monitor-prev]')?.addEventListener('click',()=>App.go('dispatcher-monitoring',{...q,trip_page:d.page-1}));
    root.querySelector('[data-monitor-next]')?.addEventListener('click',()=>App.go('dispatcher-monitoring',{...q,trip_page:d.page+1}));
    root.querySelectorAll('[data-monitor-trip]').forEach(button=>button.addEventListener('click',()=>this.details(button.dataset.monitorTrip,groups)));
    const search=root.querySelector('[data-monitor-search]');
    const doSearch=()=>{const value=search.value.trim();if(value===(q.search||''))return;const next={...q,trip_page:1};if(value)next.search=value;else delete next.search;App.go('dispatcher-monitoring',next);};
    search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();doSearch();}});
    search.addEventListener('search',doSearch);
    RecordComponents.bindDropdowns(root,{
      apply(panel){const next={...q,tab:d.tab,trip_page:1};for(const key of ['group','shift','status','from','to']){const value=panel.querySelector('[name="'+key+'"]')?.value||'';if(value)next[key]=value;else delete next[key];}if(next.from&&next.to&&next.from>next.to){UI.toast('End date must be on or after start date.','error');return;}App.go('dispatcher-monitoring',next);},
      reset(){const next={...q,tab:d.tab,trip_page:1};for(const key of ['group','shift','status','from','to'])delete next[key];App.go('dispatcher-monitoring',next);},
      sort(key){const tbody=root.querySelector('[data-monitor-tbody]');const ordered=originalRows.slice();if(key==='newest'||key==='oldest')ordered.sort((a,b)=>key==='newest'?String(b.scheduled_at||'').localeCompare(String(a.scheduled_at||'')):String(a.scheduled_at||'').localeCompare(String(b.scheduled_at||'')));else if(key==='client'||key==='status')ordered.sort((a,b)=>String(a[key]||'').localeCompare(String(b[key]||''),undefined,{numeric:true}));tbody.innerHTML=ordered.map(rowHTML).join('');tbody.querySelectorAll('[data-monitor-trip]').forEach(button=>button.addEventListener('click',()=>DispatcherMonitorUI.details(button.dataset.monitorTrip,groups)));}
    },{filter:'monitor-filter-panel',sort:'monitor-sort-panel'});
  }
};
App.page('dispatcher-monitoring',{title:'Dispatch Monitoring',render:ctx=>DispatcherMonitorUI.render(ctx)});
