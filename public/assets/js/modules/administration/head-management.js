/* Head Management read-only oversight. Actions stay with their owning departments. */
'use strict';
const HeadOversight = {
  panel(title, cols, rows, empty, interactive = true) {
    const records = rows || [];
    if (!interactive) return '<section class="card mb-3"><div class="card-body"><h2 class="h6 mb-3">'+UI.esc(title)+'</h2>'
      +UI.table(cols,records,{showAllColumns:true,viewSwitch:false,empty})+'</div></section>';
    const facetKeys = ['status','truck_type','person_type','availability','severity'];
    const facet = facetKeys.find(key => cols.some(column => column[1] === key));
    const options = facet ? [...new Set(records.map(row => row[facet]).filter(Boolean))].sort() : [];
    const id = 'head-filter-' + (++this.panelId);
    const sortId = id + '-sort';
    return '<section class="card mb-3 head-monitor-panel" data-head-panel>'
      + '<div class="head-monitor-header"><h2 class="h6 mb-0">'+UI.esc(title)+'</h2>'
      + '<span class="head-monitor-subcount" data-head-count>'+records.length+' records</span></div>'
      + '<div class="head-monitor-tools record-workspace-search">'
      + '<div class="head-search-box"><i class="bi bi-search" aria-hidden="true"></i>'
      + '<input type="search" class="form-control head-monitor-search" data-head-search placeholder="Search this list" aria-label="Search '+UI.esc(title)+'"></div>'
      + (facet ? '<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger" aria-controls="'+id+'" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter <span data-head-filter-count></span></button>' : '')
      + '<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger" aria-controls="'+sortId+'" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>'
      + (title === 'Company Trip Records' ? '<button type="button" class="btn btn-sm btn-outline-success" data-head-export><i class="bi bi-download" aria-hidden="true"></i> Export</button>' : '')
      + '<div class="record-workspace-popovers">'
      + (facet ? '<div class="record-workspace-popover" id="'+id+'" hidden><strong>Filter '+UI.esc(title)+'</strong>'
        + '<label>'+UI.esc(facet.replace(/_/g,' '))+'<select class="form-select form-select-sm" data-head-facet>'
        + '<option value="">All</option>'+options.map(value => '<option value="'+UI.esc(value)+'">'+UI.esc(value)+'</option>').join('')+'</select></label>'
        + '<div class="record-workspace-popover-actions"><button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button>'
        + '<button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button></div></div>' : '')
      + '<div class="record-workspace-popover record-workspace-sort-panel" id="'+sortId+'" hidden>'
      + '<strong>Sort records</strong><label class="record-workspace-sort-option"><input type="radio" name="'+sortId+'" value="default" data-record-sort checked> Default order</label>'
      + '<label class="record-workspace-sort-option"><input type="radio" name="'+sortId+'" value="asc" data-record-sort> First column A–Z</label>'
      + '<label class="record-workspace-sort-option"><input type="radio" name="'+sortId+'" value="desc" data-record-sort> First column Z–A</label></div></div>'
      + '</div>'
      + (facet && options.length && options.length <= 5 ? '<div class="record-workspace-category-row head-monitor-categories" data-head-quick-categories>'
        + '<button type="button" class="btn btn-sm btn-primary" data-head-quick-status="">All</button>'
        + options.map(value=>'<button type="button" class="btn btn-sm btn-outline-secondary" data-head-quick-status="'+UI.esc(value)+'">'+UI.esc(value)+'</button>').join('')
        + '</div>' : '')
      + '<div class="head-monitor-table" data-head-panel-table></div></section>';
  },
  panelId: 0,
  csv(rows, cols, name) {
    const quote = value => '"' + String(value ?? '').replace(/"/g,'""')
      .replace(/^[\s\uFEFF]*[=+\-@\t\r]/, match => "'" + match) + '"';
    const csv = '\uFEFF' + [cols.map(col => quote(col[0])).join(','),
      ...rows.map(row => cols.map(col => quote(row[col[1]])).join(','))].join('\r\n');
    const url = URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'mulawin_' + name.toLowerCase().replace(/[^a-z0-9]+/g,'_') + '_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url),1000);
  },
  // The shared renderer hides table headers on empty admin tables. Restore
  // headers and the regular pager here without modifying other departments.
  finishTable(slot) {
    const body = slot.querySelector('[data-tbody]');
    if (!body) return;
    const id = body.dataset.tbody;
    const table = UI._tables[id];
    if (!table) return;
    const rows = UI.tableRows(id);
    const pager = slot.querySelector('[data-tpager]');
    if (!rows.length) {
      const heading = table.columns.map(([label,,format]) =>
        '<th>'+UI.esc(UI.columnLabel(label,format))+'</th>').join('');
      const cols = table.columns.length + (table.hasActions ? 1 : 0);
      body.innerHTML = '<table class="data-table"><thead><tr>'+heading
        +(table.hasActions?'<th class="actions-col">Action</th>':'')
        +'</tr></thead><tbody><tr><td colspan="'+cols+'"><div class="table-empty" role="status">'
        +UI.esc(table.opts.empty || 'No records available.')+'</div></td></tr></tbody></table>';
    }
    if (!pager) return;
    const pages = Math.max(1,Math.ceil(rows.length/table.limit));
    pager.innerHTML = UI.recordPager({total:rows.length,page:table.page+1,pages,pageSize:table.limit});
    const buttons = pager.querySelectorAll('button');
    if (buttons[0]) buttons[0].onclick = () => {table.page--;UI.drawTable(id);this.finishTable(slot);};
    if (buttons[1]) buttons[1].onclick = () => {table.page++;UI.drawTable(id);this.finishTable(slot);};
  },
  mount(root, sections) {
    root.querySelectorAll('[data-head-panel]').forEach((panel, i) => {
      const config = sections[i];
      if (!config) return;
      const [cols, records, empty, tableOptions = {}] = config;
      const rows = records || [];
      const slot = panel.querySelector('[data-head-panel-table]');
      const search = panel.querySelector('[data-head-search]');
      const facet = panel.querySelector('[data-head-facet]');
      const count = panel.querySelector('[data-head-count]');
      const indicator = panel.querySelector('[data-head-filter-count]');
      let filtered = rows;
      let sort = 'default';
      const draw = () => {
        const term = search.value.trim().toLocaleLowerCase();
        const chosen = facet?.value || '';
        const field = ['status','truck_type','person_type','availability','severity']
          .find(key => cols.some(column => column[1] === key));
        filtered = rows.filter(row => (!chosen || row[field] === chosen)
          && (!tableOptions.filterRow || tableOptions.filterRow(row,panel))
          && (!term || cols.some(column => String(row[column[1]] ?? '').toLocaleLowerCase().includes(term))
            || (tableOptions.searchFields || []).some(key => String(row[key] ?? '').toLocaleLowerCase().includes(term))));
        if (sort !== 'default') {
          const key = cols[0][1];
          filtered = [...filtered].sort((a,b) => (sort === 'asc' ? 1 : -1)
            * String(a[key] ?? '').localeCompare(String(b[key] ?? ''),undefined,{numeric:true}));
        }
        slot.innerHTML = UI.table(cols,filtered,{...tableOptions,showAllColumns:true,viewSwitch:false,empty});
        count.textContent = filtered.length === rows.length ? rows.length + ' records' : filtered.length + ' of ' + rows.length + ' records';
        if (indicator) {
          const n = tableOptions.filterCount ? tableOptions.filterCount(panel) : Number(Boolean(chosen));
          indicator.textContent = n ? '('+n+')' : '';
        }
        panel.querySelectorAll('[data-head-quick-status]').forEach(button=>{
          const active=button.dataset.headQuickStatus===chosen;
          button.classList.toggle('btn-primary',active);
          button.classList.toggle('btn-outline-secondary',!active);
          button.setAttribute('aria-pressed',String(active));
        });
        UI.activate(slot);
        this.finishTable(slot);
      };
      search.addEventListener('input',draw);
      panel.querySelector('[data-head-quick-categories]')?.addEventListener('click',event=>{
        const button=event.target.closest('[data-head-quick-status]');
        if(!button||!facet)return;
        facet.value=button.dataset.headQuickStatus;
        draw();
      });
      RecordComponents.bindDropdowns(panel,{
        apply: () => { draw(); panel.querySelector('.record-workspace-filter-trigger')?.click(); },
        reset: () => {
          if (facet) facet.value='';
          panel.querySelectorAll('[data-head-extra-filter]').forEach(input => input.value='');
          draw();
          panel.querySelector('.record-workspace-filter-trigger')?.click();
        },
        sort: value => {sort=value;draw();}
      },{filter:facet?.closest('.record-workspace-popover')?.id || 'head-no-filter',sort:panel.querySelector('.record-workspace-sort-panel').id});
      panel.querySelector('[data-head-export]')?.addEventListener('click', () => {
        if (tableOptions.exportRows) tableOptions.exportRows(filtered);
        else this.csv(filtered,cols,panel.querySelector('h2').textContent);
      });
      draw();
    });
  },
  note() {
    return '<p class="small text-muted mb-3">Company-wide monitoring only. Changes to Trucks, Drivers, Helpers and Mechanics are handled by their assigned departments.</p>';
  }
};
/* Shared Client Management record-table composition for Head oversight pages.
 * Every table is one RecordComponents.panel / mountPanel, not nested custom cards. */
const HeadRecords = {
  render(root, config) {
    const {title, categories, selected, columns, rows, empty, onAction, rowClick, exportRows, searchFields=[], filters=[], sortOptions, alwaysShowTools=false} = config;
    const prefix='head-record-'+(++HeadOversight.panelId);
    const filterId=prefix+'-filter',sortId=prefix+'-sort';
    const hasFilters=filters.length>0;
    const hasRecords=rows.length>0;
    const showTools=hasRecords||alwaysShowTools;
    const sortFields=sortOptions?.length?sortOptions:[
      {key:columns[0]?.[1],label:columns[0]?.[0]||'Record'}];
    const choices=options=>options.map(value=>'<option value="'+UI.esc(value)+'">'+UI.esc(value)+'</option>').join('');
    const toolbar=(showTools&&hasFilters?'<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-filter-trigger" aria-controls="'+filterId+'" aria-expanded="false"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>':'')
      +(showTools?'<button type="button" class="btn btn-sm btn-outline-secondary record-workspace-sort-trigger" aria-controls="'+sortId+'" aria-expanded="false"><i class="bi bi-arrow-down-up" aria-hidden="true"></i> Sort</button>':'')
      +'<div class="record-workspace-popovers">'+(showTools&&hasFilters?'<div class="record-workspace-popover" id="'+filterId+'" hidden><strong>Filter records</strong>'
      +(alwaysShowTools&&!hasRecords?'<p class="record-workspace-empty-help">No records in this category yet. Filters will apply when records become available.</p>':'')
      +filters.map((field,index)=>'<label>'+UI.esc(field.label)
        +(field.type==='date'
          ? '<input type="date" class="form-control form-control-sm" data-head-record-filter="'+index+'">'
          : '<select class="form-select form-select-sm" data-head-record-filter="'+index+'"><option value="">All</option>'+choices(field.options)+'</select>')
        +'</label>').join('')
      +'<div class="record-workspace-popover-actions"><button type="button" class="btn btn-sm btn-outline-secondary" data-record-filter-reset>Clear</button><button type="button" class="btn btn-sm btn-primary" data-record-filter-apply>Apply</button></div></div>':'')
      +(showTools?'<div class="record-workspace-popover record-workspace-sort-panel" id="'+sortId+'" hidden><strong>Sort records</strong>'
      +'<label class="record-workspace-sort-option"><input type="radio" name="'+sortId+'" data-record-sort value="default" checked> Original order</label>'
      +(alwaysShowTools&&!hasRecords?'<p class="record-workspace-empty-help">Choose a sort order for records when available.</p>':'')
      +sortFields.filter(item=>item.key).flatMap(item=>[
        {value:item.key+':asc',label:item.label+' (A–Z)'},
        {value:item.key+':desc',label:item.label+' (Z–A)'}
      ]).map(item=>'<label class="record-workspace-sort-option"><input type="radio" name="'+sortId+'" data-record-sort value="'+UI.esc(item.value)+'"> '+UI.esc(item.label)+'</label>').join('')
      +'</div>':'')+'</div>'
      +(exportRows?'<button type="button" class="btn btn-sm btn-outline-success record-workspace-add" data-head-record-export><i class="bi bi-download" aria-hidden="true"></i> Export</button>':'');
    // Search remains in the shared UI.table, with extra text fields included
    // in the native search via non-rendered searchable columns.
    const table=UI.table(columns,rows,{
      tools:toolbar,showAllColumns:true,viewSwitch:false,empty,
      ...(onAction?{actions:()=>UI.btn('view','View','outline-primary'),onAction,rowClick}:{})
    });
    root.innerHTML=RecordComponents.panel(table,RecordComponents.categories(categories,selected,'data-head-record-category'));
    UI.activate(root);
    RecordComponents.mountPanel(root,{title,addSelector:'.record-workspace-add'});
    const tableBody=root.querySelector('[data-tbody]'),id=tableBody?.dataset.tbody;
    if (!id) return;
    const tableState=UI._tables[id],original=rows.slice();
    const search=root.querySelector('[data-tsearch]');
    const filterInputs=[...root.querySelectorAll('[data-head-record-filter]')];
    let sort='default',filtered=rows.slice();
    // Shared search can include extra underlying row properties without exposing columns.
    const matched=row=>{
      const q=search?.value.trim().toLocaleLowerCase()||'';
      return !q||[...columns.map(col=>col[1]),...searchFields].some(key=>String(row[key]??'').toLocaleLowerCase().includes(q));
    };
    const redraw=()=>{
      filtered=original.filter(row=>filters.every((filter,index)=>
        !filterInputs[index]?.value || (filter.type==='date'
          ? String(row[filter.key]??'').slice(0,10)===filterInputs[index].value
          : String(row[filter.key]??'')===filterInputs[index].value))).filter(matched);
      if(sort!=='default'){
        const [key,direction]=sort.split(':');
        if(sortFields.some(field=>field.key===key)){
          filtered.sort((a,b)=>(direction==='desc'?-1:1)
            *String(a[key]??'').localeCompare(String(b[key]??''),undefined,{numeric:true}));
        }
      }
      tableState.rows=filtered;
      tableState.filter='';
      tableState.page=0;
      UI.drawTable(id);
      HeadOversight.finishTable(root);
    };
    // UI.activate bound its search listener already; register after it so the
    // scoped filter redraw wins, and it does not mutate other tables.
    search?.addEventListener('input',redraw);
    RecordComponents.bindDropdowns(root,{
      apply(){redraw();root.querySelector('.record-workspace-filter-trigger')?.click();},
      reset(){filterInputs.forEach(input=>input.value='');redraw();root.querySelector('.record-workspace-filter-trigger')?.click();},
      sort(value){sort=value;redraw();}
    },{filter:filterId,sort:sortId});
    root.querySelector('[data-head-record-export]')?.addEventListener('click',()=>exportRows(filtered));
    root.querySelectorAll('[data-head-record-category]').forEach(button=>
      button.addEventListener('click',()=>config.changeCategory?.(button.dataset.headRecordCategory)));
    HeadOversight.finishTable(root);
  }
};

/* One category workspace instead of three stacked Head Management tables. */
const HeadFleetWorkspace = {
  categories: [
    {id:'trucks', label:'Trucks', key:'vehicles', empty:'No truck records available.',
      cols:[['Truck / Plate','plate_number'],['Type','truck_type'],['Status','status','badge'],
        ['Driver','driver_name'],['Helper','helper_name'],['Current Trip','active_trip']]},
    {id:'crew', label:'Drivers & Helpers', key:'personnel', empty:'No personnel records available.',
      cols:[['Personnel','full_name'],['Role','person_type'],['Availability','availability','badge'],
        ['Regular Truck','regular_truck'],['Active','active',value=>Number(value)===1?'Yes':'No']]},
    {id:'mechanics', label:'Mechanics', key:'mechanics', empty:'No mechanics recorded.',
      cols:[['Mechanic','full_name'],['Active Jobs','open_jobs'],
        ['Active','active',value=>Number(value)===1?'Yes':'No']]}
  ],
  metrics(summary) {
    const value=key=>summary && summary[key] !== null && summary[key] !== undefined
      ? UI.esc(String(summary[key])) : '—';
    const items=[
      ['Total Trucks',value('total_trucks'),'bi-truck','green','trucks'],
      ['Available Trucks',value('available_trucks'),'bi-check-circle','blue','trucks'],
      ['Active Drivers & Helpers',value('active_crew'),'bi-people','purple','crew'],
      ['Under Maintenance',value('under_maintenance'),'bi-wrench-adjustable','red','trucks']
    ];
    return '<div class="head-trip-kpis head-fleet-kpis">'
      +items.map(([label,amount,icon,tone,category])=>
        '<button type="button" class="head-kpi head-fleet-kpi '+tone+'" data-head-fleet-kpi="'+category+'" aria-label="View '+UI.esc(label)+' records">'
        +'<span class="head-kpi-icon"><i class="bi '+icon+'" aria-hidden="true"></i></span>'
        +'<span class="head-fleet-kpi-value"><span>'+UI.esc(label)+'</span><strong>'+amount+'</strong></span></button>'
      ).join('')+'</div>';
  },
  render(ctx) {
    const data=ctx.data||{};
    const tabs=this.categories.map(category=>({...category,records:Array.isArray(data[category.key])?data[category.key]:[]}));
    ctx.el.innerHTML='<div class="head-fleet-workspace">'
      +'<p class="small text-muted">Company-wide monitoring only. Operational changes belong to their assigned departments.</p>'
      +this.metrics(data.fleet_summary)
      +'<div data-head-fleet-table></div></div>';
    const container=ctx.el.querySelector('[data-head-fleet-table]');
    const select=id=>{
      const category=tabs.find(tab=>tab.id===id)||tabs[0];
      const records=category.records;
      const field=['status','availability','person_type'].find(key=>category.cols.some(col=>col[1]===key));
      HeadRecords.render(container,{
        title:'Fleet & Personnel Records',
        categories:tabs.map(tab=>({value:tab.id,label:tab.label})),
        selected:category.id,
        columns:category.cols,rows:records,empty:category.empty,
        filters:field?[{label:field.replace(/_/g,' '),key:field,
          options:[...new Set(records.map(row=>row[field]).filter(Boolean))].sort()}]:[],
        changeCategory:select
      });
    };
    ctx.el.querySelectorAll('[data-head-fleet-kpi]').forEach(button=>
      button.addEventListener('click',()=>select(button.dataset.headFleetKpi)));
    select('trucks');
    if(Object.keys(data._errors||{}).length)
      UI.toast('Some fleet monitoring information could not be loaded. Check the server log.','error');
  }
};
App.page('head-fleet',{
  title:'Fleet & Personnel Status',
  subtitle:'Read-only truck, driver, helper and mechanic activity.',
  render(ctx) { HeadFleetWorkspace.render(ctx); }
});
App.page('head-approvals',{
  title:'Management Action Center',
  subtitle:'Management decisions and unresolved issues requiring attention.',
  render(ctx) {
    if (UI.canGo('admin_approvals')) UI.tool(ctx,'Department Approvals',()=>App.go('admin_approvals'),'bi-check2-square');
    if (UI.canGo('admin_overview')) UI.tool(ctx,'Department Oversight',()=>App.go('admin_overview'),'bi-grid-1x2');
    const d=ctx.data||{};
    const sections=[
      {key:'rates',label:'Route Rates',rows:d.route_rates||[],
        cols:[['Route','route_ref'],['Client','company_name'],['Origin','origin_label'],
          ['Destination','destination_label'],['Proposed Rate','rate_amount','money']],
        empty:'No route rates pending approval.'},
      {key:'maintenance',label:'Maintenance',rows:d.maintenance||[],
        cols:[['Job','job_ref'],['Truck','plate_number'],['Status','status','badge'],['Problem','problem']],
        empty:'No open maintenance jobs.'},
      {key:'incidents',label:'Incidents',rows:d.incidents||[],
        cols:[['Incident','incident_ref'],['Trip','trip_ref'],['Status','status','badge']],
        empty:'No unresolved incidents.'},
      {key:'funds',label:'Fund Requests',rows:d.funds||[],
        cols:[['Fund','fund_ref'],['Trip','trip_ref'],['Status','status','badge']],
        empty:'No fund requests awaiting approval.'}
    ];
    const metrics=[
      {label:'Route Rates',value:sections[0].rows.length,icon:'bi-signpost-split',color:'blue'},
      {label:'Maintenance Exceptions',value:sections[1].rows.length,icon:'bi-tools',color:'amber'},
      {label:'Open Incidents',value:sections[2].rows.length,icon:'bi-exclamation-triangle',color:'red'},
      {label:'Fund Requests',value:sections[3].rows.length,icon:'bi-wallet2',color:'green'}
    ];
    ctx.el.innerHTML='<div class="head-approvals-workspace">'
      +RecordComponents.kpis(metrics)
      +'<div data-head-approvals-records></div></div>';
    const root=ctx.el.querySelector('[data-head-approvals-records]');
    const select=key=>{
      const group=sections.find(item=>item.key===key)||sections[0];
      const filterDefs=({
        rates:[['Client','company_name'],['Origin','origin_label'],['Destination','destination_label']],
        maintenance:[['Status','status'],['Truck','plate_number']],
        incidents:[['Status','status'],['Trip','trip_ref']],
        funds:[['Status','status'],['Trip','trip_ref']]
      })[group.key]||[];
      const filters=filterDefs.map(([label,key])=>({
        label,key,options:[...new Set(group.rows.map(row=>row[key]).filter(Boolean))].sort()
      }));
      const sortOptions=group.cols.filter(col=>col[1]&&col[1]!=='status')
        .slice(0,3).map(col=>({key:col[1],label:col[0]}));
      HeadRecords.render(root,{
        title:'Management Action Records',
        categories:['maintenance','incidents','funds','rates'].map(key=>sections.find(item=>item.key===key)).filter(Boolean).map(item=>({value:item.key,label:item.label+' ('+item.rows.length+')'})),
        selected:group.key,
        columns:group.cols,rows:group.rows,empty:group.empty,
        filters,sortOptions,alwaysShowTools:true,
        changeCategory:select
      });
    };
    select(['maintenance','incidents','funds','rates'].find(key=>sections.find(item=>item.key===key)?.rows.length)||'maintenance');
    if(Object.keys(d._errors||{}).length)
      UI.toast('Some approval monitoring information could not be loaded. Check server logs.','error');
  }
});

/* The executive trip page is separate from Operations Head's creation and
 * Dispatcher workflows. Only read-only HeadManagement APIs are used. */
const HeadTripsUI = {
  item(value) { return value == null || value === '' ? '—' : UI.esc(String(value)); },
  date(value) { return value ? UI.datetime(value) : '—'; },
  status(value) { return value ? UI.badge(value) : '—'; },
  delayed(trip) {
    return ['Pending Review','Awaiting Resources','Pre-Assigned','Ready for Dispatch'].includes(trip.status)
      && Boolean(trip.scheduled_at)
      && new Date(String(trip.scheduled_at).replace(' ','T')).getTime() < Date.now();
  },
  issue(trip) {
    const issues = [];
    if (this.delayed(trip)) issues.push('Past schedule');
    if (Number(trip.open_incidents) > 0) issues.push(trip.open_incidents + ' open incident(s)');
    if (['For Reschedule', 'On Hold - Incident Reported'].includes(trip.status)) issues.push(trip.status);
    return issues.length ? issues.map(v => UI.badge(v)).join(' ') : '—';
  },
  async details(id) {
    try {
      const result = await App.api('HeadManagement.trip_details', {trip_id:id});
      const data = result || {};
      const t = data.trip;
      if (!t) throw new Error('Trip details are unavailable.');
      const e = v => HeadTripsUI.item(v);
      const events = data.events || [];
      const updates = data.updates || [];
      const incidents = data.incidents || [];
      const detailHtml =
        '<p class="small text-muted">Monitoring only. Trip changes are handled by the Operations Head and assigned Dispatcher.</p>'
        + '<h6>Trip & Route</h6>' + UI.kv([
          ['Status',HeadTripsUI.status(t.status)],
          ['Client',e(t.client)],
          ['Pickup',e(t.pickup_point)],
          ['Destination',e(t.delivery_point)],
          ['Truck type',e(t.truck_type)],
          ['Scheduled departure',HeadTripsUI.date(t.scheduled_at)],
          ['Actual dispatch',HeadTripsUI.date(t.dispatched_at)],
          ['Completed',HeadTripsUI.date(t.completed_at)]
        ])
        + '<h6 class="mt-3">Assigned Resources</h6>' + UI.kv([
          ['Truck',e(t.plate_number)],
          ['Driver',e(t.driver_name)],
          ['Second driver',e(t.driver2_name)],
          ['Helper',e(t.helper_name)],
          ['Responsible Dispatcher',e(t.dispatcher_name)],
          ['Dispatcher shift',e(t.dispatcher_shift)]
        ])
        + '<h6 class="mt-3">Trip Fund & Completion</h6>' + UI.kv([
          ['Fund status',e(t.fund_status)],
          ['Fund amount',t.fund_amount == null ? '—' : UI.money(t.fund_amount)],
          ['Delivery notes',e(t.delivery_notes)]
        ])
        + '<h6 class="mt-3">Activity Timeline</h6>'
        + HeadOversight.panel('Trip events',
          [['Time','created_at','datetime'],['Event','event_type'],['Previous','from_status'],['New','to_status'],['Performed by','actor'],['Notes','notes']],
          events,'No trip events recorded.',false)
        + HeadOversight.panel('Progress & delays',
          [['Time','created_at','datetime'],['Type','update_type'],['Cause','delay_cause'],['Updated by','actor'],['Notes','note']],
          updates,'No progress updates recorded.',false)
        + HeadOversight.panel('Incidents',
          [['Reference','incident_ref'],['Status','status','badge'],['Severity','severity'],['Date and time','incident_at','datetime'],['Issue','problem']],
          incidents,'No incidents reported.',false);
      UI.drawer('Trip ' + e(t.trip_ref), detailHtml, {subtitle:e(t.client)});
    } catch(error) {
      UI.toast(error.message || 'Unable to load trip details.','error');
    }
  },
  columns() {
    return [
      ['Trip / Client','trip_ref',(value,row)=>'<strong>'+UI.esc(value||'—')+'</strong><div class="cell-sub">'+UI.esc(row.client||'—')+'</div>'],
      ['Route / Truck','pickup_point',(value,row)=>'<strong>'+UI.esc(value||'—')+'</strong> → '+UI.esc(row.delivery_point||'—')+'<div class="cell-sub">'+UI.esc(row.plate_number||'—')+'</div>'],
      ['Scheduled Dispatch','scheduled_at','datetime'],
      ['Status','status','badge'],
      ['Issues','_issues',(_,row)=>HeadTripsUI.issue(row)]
    ];
  },
  /** Route preview is a saved road shape, never a live vehicle tracker. */
  mountRoutePreview(root, trip) {
    const box=root.querySelector('[data-head-route-map]');
    if (!box || !trip?.route_geometry) return;
    try {
      UI.routeMap(box,trip,{
        a:[Number(trip.pickup_lat),Number(trip.pickup_lng)],
        b:[Number(trip.delivery_lat),Number(trip.delivery_lng)],
        aLabel:'Pickup: '+trip.pickup_point,
        bLabel:'Destination: '+trip.delivery_point,
        geometry:trip.route_geometry
      });
    } catch(error) {
      box.innerHTML='<p class="small text-muted p-3">Saved road route could not be displayed.</p>';
    }
  },
  /** Only rows already visible in a monitoring tab are exported. No server writes. */
  exportRows(rows) {
    const cols=[
      ['Trip No.','trip_ref'],['Client','client'],['Pickup','pickup_point'],
      ['Destination','delivery_point'],['Truck','plate_number'],['Driver','driver_name'],
      ['Helper','helper_name'],['Dispatcher','dispatcher_name'],['Schedule','scheduled_at'],
      ['Status','status'],['Open incidents','open_incidents']
    ];
    const quote=v=>'"'+String(v??'').replace(/"/g,'""').replace(/^[=+\-@]/,x=>"'"+x)+'"';
    const csv='\uFEFF'+[cols.map(([n])=>quote(n)).join(','),...rows.map(row=>cols.map(([,key])=>quote(row[key])).join(','))].join('\r\n');
    const url=URL.createObjectURL(new Blob([csv],{type:'text/csv;charset=utf-8'}));
    const link=document.createElement('a');link.href=url;link.download='mulawin_trip_monitoring_'+new Date().toISOString().slice(0,10)+'.csv';
    document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  },
  render(ctx) {
    const d=ctx.data||{}, summary=d.summary||{};
    const active=d.active||[],history=d.history||[],incidents=d.incidents||[];
    const count=key=>Number(summary[key]||0);
    const statusOf=t=>t.status==='Completed'?'Completed':
      ['Dispatched','In Transit','On Hold - Incident Reported'].includes(t.status)?'On Trip':
      ['Cancelled','Rejected'].includes(t.status)?'Closed':'Scheduled';
    const buckets=['Completed','On Trip','Scheduled','Closed'];
    const all=[...active,...history];
    const values=buckets.map(label=>all.filter(t=>statusOf(t)===label).length);
    const total=values.reduce((n,v)=>n+v,0);
    const route=d.route_preview;
    const chart=UI.chartHtml({
      title:'Trip Operations Overview',
      type:'doughnut',
      labels:buckets,
      datasets:[{label:'Trips',data:values}],
      how:'Company-wide stored trip records. Delays and incidents can overlap these categories.'
    });
    const mapPanel=UI.card('Saved Road Route Preview',route
      ? '<div class="head-route-map" data-head-route-map></div>'
        +'<div class="head-map-caption"><b>'+UI.esc(route.trip_ref)+'</b> · '
        +UI.esc(route.pickup_point)+' → '+UI.esc(route.delivery_point)
        +'<p class="mb-0 small text-muted">Saved road geometry only — not real-time truck tracking.</p></div>'
      : '<div class="head-route-empty"><i class="bi bi-geo-alt" aria-hidden="true"></i><strong>No saved map route available</strong><span>Routes appear here only when verified road geometry is stored. No GPS location is inferred.</span></div>');
    ctx.el.innerHTML='<div class="head-trip-dashboard">'
      +'<div class="head-trip-intro"><span><i class="bi bi-truck" aria-hidden="true"></i> Company-wide operations overview</span><span class="small text-muted"><i class="bi bi-eye" aria-hidden="true"></i> Read-only · Saved operational records</span></div>'
      +'<div class="head-trip-kpis">'+[
        ['Scheduled / Preparing',count('scheduled'),'bi-calendar-check','blue'],
        ['On Trip / In Transit',count('ongoing'),'bi-truck','green'],
        ['Completed Today',count('completed_today'),'bi-check-circle','purple'],
        ['Delayed / Problem',count('delayed_problem'),'bi-exclamation-triangle','red']
      ].map(([title,value,icon,tone])=>'<div class="head-kpi '+tone+'"><div class="head-kpi-icon"><i class="bi '+icon+'" aria-hidden="true"></i></div><div><span>'+UI.esc(title)+'</span><strong>'+value+'</strong></div></div>').join('')
      +'</div><div class="head-trip-overview"><div class="head-trip-chart">'+chart
      +'<div class="head-chart-total">'+total+' total trip'+(total===1?'':'s')+'</div></div><div class="head-trip-map">'+mapPanel+'</div></div>'
      +'<div data-head-trip-records></div></div>';
    const root=ctx.el.querySelector('[data-head-trip-records]');
    const tabItems=[
      {value:'active',label:'Active & Scheduled ('+active.length+')',rows:active,empty:'No active or scheduled trips.'},
      {value:'history',label:'Trip History',rows:history,empty:'No completed or closed trips.'},
      {value:'incidents',label:'Incidents ('+incidents.filter(x=>x.status!=='Resolved').length+' open)',rows:incidents,empty:'No incidents reported.'}
    ];
    const select=key=>{
      const tab=tabItems.find(item=>item.value===key)||tabItems[0];
      const isIncident=tab.value==='incidents';
      const cols=isIncident?[
        ['Incident','incident_ref'],['Trip','trip_ref'],['Client','client'],
        ['Truck','plate_number'],['Status','status','badge'],
        ['Severity','severity'],['Reported','incident_at','datetime']
      ]:HeadTripsUI.columns();
      const filters=isIncident?[
        {label:'Status',key:'status',options:[...new Set(tab.rows.map(r=>r.status).filter(Boolean))].sort()}
      ]:[
        {label:'Trip status',key:'status',options:[...new Set(tab.rows.map(r=>r.status).filter(Boolean))].sort()},
        {label:'Truck type',key:'truck_type',options:[...new Set(tab.rows.map(r=>r.truck_type).filter(Boolean))].sort()},
        {label:'Scheduled date',key:'scheduled_at',type:'date'}
      ];
      HeadRecords.render(root,{
        title:'Company Trip Records',categories:tabItems,selected:tab.value,
        columns:cols,rows:tab.rows,empty:tab.empty,filters,
        searchFields:isIncident?[]:['client','delivery_point','plate_number','driver_name','helper_name','dispatcher_name'],
        onAction:(_,row)=>HeadTripsUI.details(isIncident?row.trip_id:row.id),
        rowClick:row=>HeadTripsUI.details(isIncident?row.trip_id:row.id),
        exportRows:isIncident?null:rows=>HeadTripsUI.exportRows(rows),
        changeCategory:select
      });
    };
    select(ctx.query?.tab||'active');
    UI.activate(ctx.el);
    if(route) HeadTripsUI.mountRoutePreview(ctx.el,route);
    if(Object.keys(d._errors||{}).length) UI.toast('Some monitoring data could not be loaded. Check the server log.','error');
  }
};
App.page('head-trips',{
  title:'Trip Monitoring',
  subtitle:'Company-wide trip progress, assignments, delays and history — read-only.',
  render(ctx){ HeadTripsUI.render(ctx); }
});
