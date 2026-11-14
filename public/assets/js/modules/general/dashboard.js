/* Mulawin FleetOps V2 — RPM role dashboards.
 * Presentation-only: uses the existing role-filtered Analytics.dashboard payload.
 * No synthetic numbers, new API methods, or permission changes.
 */
'use strict';

const RPMDashboard = {
  roles: {
    admin: { title: 'Head Management Dashboard', detail: 'Fleet, operations, finance and approvals', icon: 'bi-buildings', links: ['reports', 'head-trips', 'head-fleet', 'head-approvals', 'clients', 'billing'] },
    operations_head: { title: 'Operations Head Dashboard', detail: 'Trip oversight, fleet availability and dispatch readiness', icon: 'bi-diagram-3', links: ['trips', 'trucks', 'crew', 'routes', 'reports'] },
    car_carrier_dispatcher: { title: 'Car Carrier Dispatcher Dashboard', detail: 'Car Carrier trips, dispatch preparation and follow-ups', icon: 'bi-truck', links: ['trips', 'funds', 'trucks', 'crew'] },
    container_wingvan_dispatcher: { title: 'Container / Wing Van Dispatcher Dashboard', detail: 'Container and Wing Van trips, dispatch and checklists', icon: 'bi-truck-flatbed', links: ['trips', 'funds', 'trucks', 'crew'] },
    motorpool: { title: 'Motorpool Custodian Dashboard', detail: 'Truck condition, active repairs and mechanics', icon: 'bi-tools', links: ['maint_overview', 'maintenance', 'trucks', 'mechanics', 'maint_history'] },
    purchasing: { title: 'Purchasing Officer Dashboard', detail: 'Parts requests, stock and purchase orders', icon: 'bi-box-seam', links: ['purchasing_overview', 'parts_requests', 'purchase_orders', 'inventory'] },
    finance: { title: 'Finance Dashboard', detail: 'Fund releases, payables, budget and expenses', icon: 'bi-cash-stack', links: ['funds', 'finance', 'purchase_orders', 'reports'] },
    billing: { title: 'Billing & Collection Dashboard', detail: 'Billing preparation, receivables and collections', icon: 'bi-receipt', links: ['billing', 'trips', 'clients', 'routes', 'reports'] },
    audit: { title: 'Audit Dashboard', detail: 'Read-only records, trends and activity review', icon: 'bi-clipboard-data', links: ['reports', 'admin', 'trips', 'maintenance', 'billing', 'finance'] },
  },
  navLabels: {
    trips: 'View trips', trucks: 'Fleet records', crew: 'Personnel', clients: 'Client records',
    'head-trips': 'Trip monitoring', 'head-fleet': 'Fleet & personnel status', 'head-approvals': 'Approvals & exceptions',
    routes: 'Client routes', maintenance: 'Maintenance records', mechanics: 'Mechanics',
    maint_overview: 'Maintenance overview', maint_history: 'Maintenance history', purchasing_overview: 'Purchasing overview', parts_requests: 'Parts requests',
    purchase_orders: 'Purchase orders', inventory: 'Inventory', funds: 'Trip funds', billing: 'Billing & collection',
    finance: 'Finance records', reports: 'Reports', admin: 'Activity / administration',
  },
  navIcons: {
    trips: 'bi-signpost-split', trucks: 'bi-truck', crew: 'bi-people', clients: 'bi-buildings',
    'head-trips': 'bi-signpost-split', 'head-fleet': 'bi-people', 'head-approvals': 'bi-clipboard-check',
    routes: 'bi-geo-alt', maintenance: 'bi-wrench-adjustable', mechanics: 'bi-person-gear',
    maint_overview: 'bi-speedometer2', maint_history: 'bi-clock-history', purchasing_overview: 'bi-speedometer2', parts_requests: 'bi-box-arrow-up',
    purchase_orders: 'bi-cart-check', inventory: 'bi-box-seam', funds: 'bi-wallet2', billing: 'bi-receipt', finance: 'bi-cash-stack',
    reports: 'bi-bar-chart-line', admin: 'bi-person-gear',
  },
  icon(name, fallback = 'bi-graph-up') {
    return /^bi-[a-z0-9-]+$/.test(String(name || '')) ? name : fallback;
  },
  number(card) {
    return card.format === 'money' ? UI.money(card.value)
      : UI.esc(typeof card.value === 'number' ? UI.num(card.value, 2) : card.value);
  },
  tile(c) {
    const go = c.page && UI.canGo(c.page);
    return `<div class="rpm-kpi rpm-kpi-${['green', 'amber', 'red'].includes(c.color) ? c.color : 'normal'}${go ? ' rpm-kpi-link' : ''}"
      ${go ? `data-go="${UI.esc(c.page)}" role="link" tabindex="0"` : ''}>
      <span class="rpm-kpi-icon"><i class="bi ${this.icon(c.icon)}" aria-hidden="true"></i></span>
      <div class="rpm-kpi-body"><div class="rpm-kpi-label">${UI.esc(c.label)}</div>
      <div class="rpm-kpi-value">${this.number(c)}</div></div>
      ${go ? '<i class="bi bi-arrow-up-right rpm-kpi-corner" aria-hidden="true"></i>' : ''}
    </div>`;
  },
  cards(cards) {
    // Keep important facts visible; secondary metrics are one click away.
    const primary = (cards || []).slice(0, 6);
    const rest = (cards || []).slice(6);
    if (!primary.length) return '';
    return `<section class="rpm-compact-metrics" aria-label="Key metrics">
      <div class="rpm-kpi-grid">${primary.map(c => this.tile(c)).join('')}</div>
      ${rest.length ? `<details class="rpm-more-metrics"><summary>Show ${rest.length} more metrics</summary>
        <div class="rpm-kpi-grid">${rest.map(c => this.tile(c)).join('')}</div></details>` : ''}
    </section>`;
  },
  executive(d) {
    const cards=d.cards||[],alerts=d.alerts||[],tables=d.tables||[],exec=d.executive||{};
    const lookup=new Map(cards.map(c=>[c.label,c]));
    const get=label=>lookup.get(label)||null;
    const read=(section,key)=>exec[section]&&!exec._errors?.[section]
      && exec[section][key]!==null && exec[section][key]!==undefined
      ?Number(exec[section][key]):null;
    const approvals=['rates','funds','purchase_orders'].map(key=>read('approvals',key));
    const pending=approvals.every(Number.isFinite)?approvals.reduce((n,v)=>n+v,0):null;
    const activeTrip=get('Ongoing trips');
    const available=get('Available trucks');
    const receivable=get('Outstanding receivables');
    const headline=[
      activeTrip&&{...activeTrip,label:'Active Trips',page:'head-trips'},
      available&&{...available,page:'head-fleet'},
      pending===null?null:{label:'Pending Approvals',value:pending,icon:'bi-clipboard-check',color:'amber',page:'head-approvals'},
      receivable
    ];
    const names=['Active Trips','Available Trucks','Pending Approvals','Outstanding Receivables'];
    const icons=['bi-signpost-split','bi-truck','bi-clipboard-check','bi-cash-stack'];
    const kpi=headline.map((item,i)=>this.tile(item||{label:names[i],value:'Unavailable',icon:icons[i]})).join('');
    const fact=(label,value,fmt)=>value===null||value===undefined?''
      :'<span class="rpm-dept-fact">'+UI.esc(label)+' <strong>'
        +(fmt==='money'?UI.money(value):UI.esc(String(value)))+'</strong></span>';
    const value=label=>get(label)?.value??null;
    let diesel=null;
    if(exec.fuel&&!exec._errors?.fuel&&exec.fuel.value_json){
      try {
        const amount=JSON.parse(exec.fuel.value_json).value;
        if(amount!==null&&amount!==''&&Number.isFinite(Number(amount)))diesel=Number(amount);
      }catch{}
    }
    const departments=[
      {title:'Operations',icon:'bi-signpost-split',page:'head-trips',facts:[
        fact('Ongoing',value('Ongoing trips')),fact('Completed this month',value('Completed trips this month'))]},
      {title:'Fleet & Personnel',icon:'bi-truck',page:'head-fleet',facts:[
        fact('Total trucks',value('Total trucks')),fact('Available drivers',value('Available drivers'))]},
      {title:'Maintenance',icon:'bi-tools',page:'maintenance',facts:[
        fact('Open repairs',value('Open repair requests')),fact('Under maintenance',value('Trucks under corrective maintenance'))]},
      {title:'Client & Commercial',icon:'bi-buildings',page:'clients',facts:[
        fact('Active clients',read('clients','active')),fact('Approved routes',read('routes','approved'))]},
      {title:'Finance',icon:'bi-wallet2',page:'finance',facts:[
        fact('Expenses this month',value('Expenses this month'),'money')]},
      {title:'Billing & Collections',icon:'bi-receipt',page:'billing',facts:[
        fact('Billed this month',value('Billed this month'),'money'),
        fact('Receivables',value('Outstanding receivables'),'money')]},
      {title:'Fuel & Operating Costs',icon:'bi-fuel-pump',page:'fuel-adjustment',facts:[fact('Diesel per liter',diesel,'money')]},
      {title:'Management Control',icon:'bi-shield-check',page:'head-approvals',facts:[
        fact('Pending approvals',pending),fact('Open incidents',read('incidents','open'))]}
    ];
    const departmentsHtml=departments.map(d=>
      '<article class="rpm-dept-card"><div class="rpm-dept-heading">'
      +'<span class="rpm-dept-icon"><i class="bi '+d.icon+'" aria-hidden="true"></i></span>'
      +'<h3>'+UI.esc(d.title)+'</h3></div><div class="rpm-dept-facts">'
      +(d.facts.filter(Boolean).join('')||'<span class="rpm-dept-unavailable">Open module to review current records</span>')
      +'</div>'+(UI.canGo(d.page)?'<button type="button" class="rpm-dept-link" data-go="'+d.page+'">'
      +'View details <i class="bi bi-arrow-up-right" aria-hidden="true"></i></button>':'')+'</article>').join('');
    const performance=(title,labels)=>{
      const items=labels.map(get).filter(Boolean);
      return '<article class="rpm-exec-performance-card"><h3>'+UI.esc(title)+'</h3>'
        +(items.length?items.map(c=>'<div class="rpm-exec-performance-row"><span>'+UI.esc(c.label)
          +'</span><strong>'+this.number(c)+'</strong></div>').join('')
          :'<p class="rpm-dept-unavailable">No verified summary available.</p>')+'</article>';
    };
    const verifiedChart=(title,labels,format)=>{
      const facts=labels.map(get).filter(c=>c&&Number.isFinite(Number(c.value)));
      if(!facts.length||!facts.some(c=>Number(c.value)!==0))return '';
      return UI.chartHtml({title,type:'bar',format,labels:facts.map(c=>c.label),
        datasets:[{label:title,data:facts.map(c=>Number(c.value))}]});
    };
    const chartHtml=[
      verifiedChart('Trip and repair activity',['Ongoing trips','Completed trips this month','Open repair requests']),
      verifiedChart('Billing, collections and expenses',['Billed this month','Collected this month','Expenses this month'],'money')
    ].filter(Boolean).join('');
    const recent=tables.filter(t=>(t.rows||[]).length>0).slice(0,2);
    const recentHtml=recent.length?recent.map(t=>UI.blockTable({...t,limit:5})).join('')
      :'<div class="rpm-exec-empty"><i class="bi bi-inbox" aria-hidden="true"></i>'
       +'<p>No recent operational records in the available summaries.</p></div>';
    const breakdown=this.breakdown(cards,'admin');
    return '<div class="rpm-executive-command">'
      +'<section class="rpm-exec-headline"><h2>Company Overview</h2><p>Company-wide indicators from recorded operations.</p>'
      +'<div class="rpm-exec-kpis">'+kpi+'</div></section>'
      +'<section class="rpm-exec-section"><div class="rpm-exec-section-head"><h2>Department Overview</h2>'
      +'<span>Open each department for detailed records</span></div>'
      +'<div class="rpm-exec-departments">'+departmentsHtml+'</div></section>'
      +'<section class="rpm-exec-section"><div class="rpm-exec-section-head"><h2>Company Performance</h2></div>'
      +'<div class="rpm-exec-performance">'
      +performance('Operations & Maintenance',['Ongoing trips','Completed trips this month','Open repair requests'])
      +performance('Financial Summary',['Billed this month','Collected this month','Expenses this month'])
      +'</div>'+(chartHtml?'<div class="rpm-exec-charts">'+chartHtml+'</div>':'')+'</section>'
      +'<section class="rpm-exec-section rpm-exec-alerts">'+this.alerts(alerts)+'</section>'
      +(breakdown?'<section class="rpm-exec-section rpm-exec-fleet-breakdown">'+breakdown+'</section>':'')
      +'<section class="rpm-exec-section"><div class="rpm-exec-section-head"><h2>Recent Operational Records</h2>'
      +'<span>Latest records provided by authorized modules</span></div>'
      +'<div class="rpm-dashboard-tables">'+recentHtml+'</div></section></div>';
  },
  // A breakdown is shown only when there are genuinely comparable counts.
  breakdown(cards, role) {
    const lookup = new Map((cards || []).map(c => [c.label, c]));
    const get = k => lookup.has(k) ? Number(lookup.get(k).value) : NaN;
    let title, items;
    if (['admin', 'operations_head', 'car_carrier_dispatcher', 'container_wingvan_dispatcher', 'audit'].includes(role)) {
      const total = get('Total trucks'), available = get('Available trucks'), assigned = get('Assigned or on trip'), unavailable = get('Unavailable trucks');
      if (![total, available, assigned, unavailable].every(Number.isFinite) || total < 0 || Math.abs(total - available - assigned - unavailable) > 0.001) return '';
      title = 'Fleet availability';
      items = [['Available', available, '#208b46'], ['Assigned / on trip', assigned, '#68b67e'], ['Unavailable', unavailable, '#e2a443']];
    } else if (role === 'motorpool') {
      const total = get('Total trucks'), available = get('Available trucks');
      if (![total, available].every(Number.isFinite) || total < available || available < 0) return '';
      title = 'Fleet availability'; items = [['Available', available, '#208b46'], ['Other truck statuses', total - available, '#dba443']];
    } else if (role === 'purchasing') {
      const total = get('Parts in inventory'), low = get('Low-stock items');
      if (![total, low].every(Number.isFinite) || total < low || low < 0) return '';
      title = 'Inventory stock check'; items = [['Low-stock items', low, '#dfa53a'], ['Other active items', total - low, '#208b46']];
    } else return '';
    const sum = items.reduce((n, item) => n + item[1], 0);
    let progress = 0;
    const stops = items.map(([, n, color]) => {
      const start = progress; progress += sum ? n * 100 / sum : 0;
      return `${color} ${start.toFixed(3)}% ${progress.toFixed(3)}%`;
    }).join(', ');
    return `<section class="rpm-summary-panel"><h2 class="rpm-panel-title">${UI.esc(title)}</h2>
      <div class="rpm-breakdown"><div class="rpm-donut" role="img" aria-label="${UI.esc(items.map(([l, n]) => `${l}: ${n}`).join(', '))}"
        style="background:conic-gradient(${sum ? stops : '#e6eee8 0% 100%'})"><div class="rpm-donut-center"><b>${UI.esc(sum)}</b><small>${role === 'purchasing' ? 'Active parts' : 'Trucks'}</small></div></div>
      <div class="rpm-breakdown-legend">${items.map(([label, value, color]) => `<div><span class="rpm-swatch" style="background:${color}"></span><span>${UI.esc(label)}</span><strong>${UI.esc(value)}</strong></div>`).join('')}</div></div>
    </section>`;
  },
  alerts(alerts) {
    return UI.actionSummary((alerts||[]).map(a=>({label:a.text,go:a.page,level:a.level})),{title:'Needs attention',empty:'No pending dashboard alerts right now.'});
  },
  links(role) {
    const cfg = this.roles[role];
    return (cfg.links || []).filter(page => UI.canGo(page)).map(page =>
      `<button type="button" data-go="${page}" class="rpm-quick-link"><i class="bi ${this.navIcons[page] || 'bi-grid'}" aria-hidden="true"></i><span>${UI.esc(this.navLabels[page] || page)}</span><i class="bi bi-arrow-up-right rpm-quick-arrow" aria-hidden="true"></i></button>`
    ).join('');
  },
};

App.page('dashboard', {
  title: 'Dashboard',
  subtitle: 'Your current records and pending work.',
  render(ctx) {
    const d = ctx.data || {};
    const role = App.me?.user?.role || 'audit';
    const cfg = RPMDashboard.roles[role] || RPMDashboard.roles.audit;
    // The app shell already provides a page header: use it rather than duplicate it.
    const pageTitle = document.querySelector('.page-title');
    const pageSubtitle = document.querySelector('.page-subtitle');
    if (pageTitle) pageTitle.textContent = cfg.title;
    if (pageSubtitle) pageSubtitle.textContent = cfg.detail;
    const time = new Intl.DateTimeFormat('en-PH', {
      timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short', hour12: true,
    }).format(new Date());
    const stamp = document.createElement('span');
    stamp.className = 'small text-muted align-self-center';
    stamp.textContent = 'Updated ' + time + ' PHT';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm btn-outline-success';
    btn.innerHTML = '<i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh';
    btn.onclick = () => ctx.reload();
    ctx.tools.append(stamp, btn);
    if (role === 'operations_head' || UI.isDispatcher()) {
      const create = document.createElement('button'); create.type = 'button'; create.className = 'btn btn-sm btn-primary';
      create.innerHTML = '<i class="bi bi-plus-lg" aria-hidden="true"></i> New Trip';
      create.onclick = () => App.go('trips', { new: 1 }); ctx.tools.append(create);
    }

    const cards = d.cards || [], alerts = d.alerts || [], charts = d.charts || [], tables = d.tables || [];
    if (role === 'admin') {
      ctx.el.classList.add('rpm-dashboard','rpm-executive-dashboard');
      ctx.el.innerHTML=RPMDashboard.executive(d);
      UI.activate(ctx.el);
      return;
    }
    const breakdown = RPMDashboard.breakdown(cards, role);
    const summary = RPMDashboard.alerts(alerts);
    // One primary table, capped to five rows, avoids a wall of records on arrival.
    const primaryTable = tables.length ? UI.blockTable({ ...tables[0], limit: 5 }) : '';
    const otherTables = tables.slice(1);
    const moreTables = otherTables.length ? `<details class="rpm-more-records">
      <summary>Show ${otherTables.length} more record ${otherTables.length === 1 ? 'section' : 'sections'}</summary>
      <div class="rpm-dashboard-tables">${otherTables.map(t => UI.blockTable({ ...t, limit: 5 })).join('')}</div>
    </details>` : '';
    const moreCharts = charts.length ? `<details class="rpm-more-records">
      <summary>Show ${charts.length} chart${charts.length === 1 ? '' : 's'}</summary>
      <div class="chart-grid">${charts.slice(0, 2).map(UI.chartHtml).join('')}</div>
    </details>` : '';
    ctx.el.classList.add('rpm-dashboard', 'rpm-dashboard-compact');
    ctx.el.innerHTML = `<div class="rpm-dashboard-content">
      <div class="rpm-dashboard-kpis">${RPMDashboard.cards(cards)}</div>
      <div class="rpm-dashboard-overview${breakdown ? '' : ' rpm-no-breakdown'}">
        ${summary}${breakdown}
      </div>
      ${primaryTable ? `<section class="rpm-dashboard-records"><h2 class="rpm-area-title">Recent records</h2>
        <div class="rpm-dashboard-tables">${primaryTable}</div>${moreTables}</section>` : ''}
      ${moreCharts}
      <section class="rpm-dashboard-shortcuts"><h2 class="rpm-area-title">Quick access</h2>
        <div class="rpm-quick-grid">${RPMDashboard.links(role)}</div></section>
    </div>`;
    UI.activate(ctx.el);
  },
});
