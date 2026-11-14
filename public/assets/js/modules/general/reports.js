/* assets/js/modules/general/reports.js — REPORTS (descriptive and diagnostic analytics, Objective 5)
   Trips (with % change vs the previous period), fleet downtime and maintenance cost,
   money (billed, collected, expenses, receivables aging, profit per trip) and inventory.
   Daily / weekly / monthly / custom periods, one filter modal, CSV per table and printing.
   Purchasing Reports (page purchasing_reports) reuses this screen with the inventory section only.
   Backend: backend/Modules/General/Analytics.php (reports) */
'use strict';
const ReportsPage = {
  title: 'Reports',
  subtitle: 'Summaries computed from the system records. Choose the period, filter, then print or download.',
  render(ctx) {
    const d = ctx.data, q = ctx.query, fo = d.filterOptions;
    const iso = (x) => { const y = new Date(x); y.setMinutes(y.getMinutes() - y.getTimezoneOffset()); return y.toISOString().slice(0, 10); };
    const now = new Date(), mon = new Date(now); mon.setDate(now.getDate() - ((now.getDay() + 6) % 7));
    const presets = [['Today', iso(now), iso(now)], ['This week', iso(mon), iso(now)], ['This month', iso(new Date(now.getFullYear(), now.getMonth(), 1)), iso(now)],
      ['Last month', iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), iso(new Date(now.getFullYear(), now.getMonth(), 0))], ['This year', iso(new Date(now.getFullYear(), 0, 1)), iso(now)]];
    const per = d.period;
    const presetBar = `<div class="d-flex flex-wrap gap-2 align-items-center mb-2 no-print"><span class="small text-muted">Period:</span>${presets.map(([l, f, t]) =>
      `<button type="button" class="btn btn-sm ${per.from === f && per.to === t ? 'btn-primary' : 'btn-outline-secondary'}" data-preset="${f}|${t}">${l}</button>`).join('')}
      <span class="small ms-2"><b>${UI.date(per.from)} – ${UI.date(per.to)}</b> <span class="text-muted">(compared with ${UI.date(per.prevFrom)} – ${UI.date(per.prevTo)})</span></span></div>`;
    const purchasing = ctx.page === 'purchasing_reports';   // Purchasing Reports: inventory and purchasing only
    const periodFields = [{ name: 'from', label: 'Start date (custom period)', type: 'date', col: 6 }, { name: 'to', label: 'End date', type: 'date', col: 6 }];
    const filters = purchasing ? UI.filterModal(ctx, periodFields, { hint: 'Choose a start and end date.' }) : UI.filterModal(ctx, [
      ...periodFields,
      { name: 'truck_type', label: 'Truck type', type: 'select', options: UI.list(App.me.truckTypes), col: 6 },
      { name: 'status', label: 'Trip status', type: 'select', options: UI.list(d.statuses), col: 6 },
      { name: 'client_id', label: 'Client', type: 'select', options: UI.opts(fo.clients, c => c.company_name), col: 6 },
      { name: 'vehicle_id', label: 'Truck / plate', type: 'select', options: UI.opts(fo.trucks, v => v.plate_number), col: 6 },
      { name: 'person_id', label: 'Driver / helper', type: 'select', options: UI.opts(fo.people, p => p.full_name + ' (' + p.person_type + ')'), col: 6 },
      { name: 'dispatcher_id', label: 'Dispatcher', type: 'select', options: UI.opts(fo.dispatchers, u => u.full_name), col: 6 }],
      { note: 'Truck type, status, client, truck, driver and dispatcher filters apply to the trip report.', hint: '' });

    // Head Management uses the same report aggregates with a compact
    // executive layout. Other authorized roles keep their existing view.
    if (App.me?.user?.role === 'admin') {
      const sections=d.sections||{};
      const keys=Object.keys(sections);
      ctx.el.classList.add('head-report-dashboard');
      const heading=document.querySelector('#pageContent .page-title');
      const subtitle=document.querySelector('#pageContent .page-subtitle');
      if(heading)heading.textContent='Management Reports';
      if(subtitle)subtitle.textContent='Company-wide performance, trends and operational insights.';
      if(!keys.length){ctx.el.innerHTML='<div class="table-empty">No reports are available for your role.</div>';return;}
      const labels={trips:'Trips',fleet:'Fleet & Maintenance',money:'Money',stock:'Inventory'};
      const preferences={
        trips:['Total trips','Completed trips','Cancelled trips','Trips for reschedule'],
        fleet:['Maintenance records','Completed','Downtime (truck-days)','Maintenance expenses'],
        money:['Billed (invoices)','Collected','Expenses','Outstanding receivables'],
        stock:['Stock-in (units)','Stock-out (units)','Adjustments (net units)','Total inventory value now']
      };
      const primary=key=>{
        const cards=sections[key].cards||[];
        const picks=preferences[key]||[];
        return picks.map(name=>cards.find(card=>card.label===name)).filter(Boolean).slice(0,4);
      };
      const formatCard=card=>{
        if(card.format==='money')return UI.money(Number(card.value||0));
        return UI.esc(String(card.value??'—'));
      };
      const printDetails='<details class="head-report-print no-print"><summary class="btn btn-sm btn-outline-secondary">'
        +'<i class="bi bi-printer" aria-hidden="true"></i> Print report <i class="bi bi-chevron-down" aria-hidden="true"></i>'
        +'</summary><div class="head-report-print-menu">'
        +'<button type="button" data-print-current>Print this category</button>'
        +'<button type="button" data-print-all>Print all categories</button></div></details>';
      ctx.el.innerHTML='<div class="head-report-config"><div class="head-report-config-head">'
        +'<div><h2>Report Configuration</h2><p>Choose a reporting period and refine the underlying records.</p></div>'
        +printDetails+'</div>'
        +'<div class="head-report-period-toolbar"><span class="head-report-field-label">Reporting period</span>'
        +'<div class="head-report-preset-buttons no-print">'
        +presets.map(([label,from,to])=>'<button type="button" class="btn btn-sm '
          +(per.from===from&&per.to===to?'btn-primary':'btn-outline-secondary')
          +'" data-preset="'+from+'|'+to+'">'+UI.esc(label)+'</button>').join('')
        +'</div></div>'
        +'<div class="head-report-config-bottom"><div class="head-report-date-summary">'
        +'<span class="head-report-date-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span>'
        +'<div><span class="head-report-field-label">Selected date range</span>'
        +'<strong>'+UI.date(per.from)+' – '+UI.date(per.to)+'</strong>'
        +'<small>Compared with '+UI.date(per.prevFrom)+' – '+UI.date(per.prevTo)+'</small></div></div>'
        +'<div class="head-report-config-filters">'+filters+'</div></div></div>'
        +'<section class="head-report-performance"><div class="head-report-section-title"><div>'
        +'<h2>Performance Overview</h2><p>Four primary indicators for the selected report category.</p></div>'
        +'<span data-head-report-period>'+UI.date(per.from)+' – '+UI.date(per.to)+'</span></div>'
        +'<div data-head-report-kpis></div></section>'
        +'<section class="head-report-details"><div class="head-report-details-heading"><div>'
        +'<h2>Report Details</h2><p>Category-specific charts, additional metrics, records and evidence-based observations.</p>'
        +'</div></div><div class="head-report-category-tabs" role="tablist" aria-label="Report categories">'
        +keys.map((key,index)=>'<button type="button" role="tab" class="head-report-category-tab'+(index===0?' active':'')
          +'" data-head-report-tab="'+UI.esc(key)+'" aria-selected="'+(index===0)+'">'+UI.esc(labels[key]||sections[key].title||key)+'</button>').join('')
        +'</div><div data-head-report-detail-content></div></section>';
      ctx.el.querySelectorAll('[data-preset]').forEach(button=>button.onclick=()=>{
        const [from,to]=button.dataset.preset.split('|');
        App.go('reports',{...q,from,to});
      });
      let current=keys[0];
      const reportMeta=()=>({title:'Mulawin FleetOps report',
        period:UI.date(per.from)+' – '+UI.date(per.to),
        filters:Object.entries(q).filter(([key,value])=>value&&!['from','to'].includes(key)).map(([key,value])=>key+': '+value).join(', ')});
      ctx.el.querySelector('[data-print-current]').onclick=()=>UI.printReport([sections[current]],reportMeta());
      ctx.el.querySelector('[data-print-all]').onclick=()=>UI.printReport(keys.map(key=>sections[key]),reportMeta());
      const draw=key=>{
        current=key;
        const section=sections[key],cards=section.cards||[],top=primary(key);
        const used=new Set(top);
        const remaining=cards.filter(card=>!used.has(card));
        ctx.el.querySelector('[data-head-report-kpis]').innerHTML=UI.statusStrip(top);
        ctx.el.querySelectorAll('[data-head-report-tab]').forEach(button=>{
          const active=button.dataset.headReportTab===key;
          button.classList.toggle('active',active);
          button.setAttribute('aria-selected',String(active));
          button.tabIndex=active?0:-1;
        });
        const findings=(d.insights&&d.insights[key])||[];
        const insightHtml='<section class="head-report-insights"><div class="head-report-subheading">'
          +'<i class="bi bi-lightbulb" aria-hidden="true"></i><h3>Insights & Observations</h3></div>'
          +(findings.length?'<div class="head-report-insight-grid">'+findings.map(item=>
            '<div class="head-report-insight"><small>'+UI.esc(item.type==='diagnostic'?'Diagnostic signal':item.type==='descriptive'?'Descriptive finding':'Method / limitation')+'</small>'
            +'<strong>'+UI.esc(item.title)+'</strong><p>'+UI.esc(item.text)+'</p></div>').join('')+'</div>'
            :'<p class="head-report-insight-empty">No reliable observations are available for this period. Try a wider date range.</p>')
          +'<p class="head-report-method">Diagnostic signals identify records to examine. They do not prove causes or predict future outcomes.</p></section>';
        const chartHasValues=chart=>(chart.datasets||[]).some(dataset=>
          (dataset.data||[]).some(value=>Number.isFinite(Number(value))&&Number(value)!==0));
        const usefulCharts=(section.charts||[]).filter(chart=>Array.isArray(chart.labels)
          && chart.labels.length>0 && chartHasValues(chart));
        const usefulTables=(section.tables||[]).filter(table=>(table.rows||[]).length>0);
        const hasReportData=usefulCharts.length>0||usefulTables.length>0;
        const remainingHtml=remaining.length?'<section class="head-report-secondary"><div class="head-report-subheading">'
          +'<i class="bi bi-clipboard-data" aria-hidden="true"></i><h3>Additional Indicators</h3></div>'
          +'<div class="head-report-secondary-grid">'+remaining.map(card=>
            '<div class="head-report-secondary-item"><span>'+UI.esc(card.label)+'</span><strong>'+formatCard(card)+'</strong>'
            +(card.hint?'<small>'+UI.esc(card.hint)+'</small>':'')+'</div>').join('')+'</div></section>':'';
        const noDataHtml='<div class="head-report-no-data" role="status">'
          +'<span class="head-report-no-data-icon"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></span>'
          +'<div><h3>No report records for this period</h3>'
          +'<p>There are no recorded chart or table results for this report category and selected filters. Choose a wider period or adjust the filters to explore available records.</p></div></div>';
        const detail=ctx.el.querySelector('[data-head-report-detail-content]');
        detail.innerHTML=remainingHtml
          +(hasReportData?insightHtml:'')
          +(hasReportData&&section.note?'<p class="head-report-note"><i class="bi bi-info-circle" aria-hidden="true"></i> '+UI.esc(section.note)+'</p>':'')
          +(hasReportData?'<div class="head-report-blocks">'
            +UI.blocks({...section,cards:[],charts:usefulCharts,tables:usefulTables},{maxCharts:3,key:'rep-'+key})+'</div>'
            :noDataHtml);
        UI.activate(detail);
      };
      ctx.el.querySelectorAll('[data-head-report-tab]').forEach(button=>button.onclick=()=>draw(button.dataset.headReportTab));
      draw(current);
      return;
    }

    ctx.el.innerHTML = presetBar + filters;
    ctx.el.querySelectorAll('[data-preset]').forEach(b => b.onclick = () => { const [f, t] = b.dataset.preset.split('|'); App.go(ctx.page, { ...q, from: f, to: t }); });
    const keys = Object.keys(d.sections);
    if (!keys.length) { ctx.el.insertAdjacentHTML('beforeend', '<div class="table-empty">No reports for your role.</div>'); return; }
    const meta = () => ({ title: purchasing ? 'Mulawin FleetOps purchasing report' : 'Mulawin FleetOps report', period: UI.date(per.from) + ' – ' + UI.date(per.to),
      filters: Object.entries(q).filter(([k, v]) => v && !['from', 'to'].includes(k)).map(([k, v]) => k + ': ' + v).join(', ') });
    const p1 = document.createElement('button'); p1.className = 'btn btn-sm btn-outline-secondary'; p1.innerHTML = '<i class="bi bi-printer"></i> Print this tab';
    const p2 = document.createElement('button'); p2.className = 'btn btn-sm btn-outline-secondary'; p2.innerHTML = '<i class="bi bi-printer-fill"></i> Print all';
    let current = keys[0];
    p1.onclick = () => UI.printReport([d.sections[current]], meta());
    p2.onclick = () => UI.printReport(keys.map(k => d.sections[k]), meta());
    ctx.tools.append(p1, p2);
    const box = document.createElement('div'); ctx.el.append(box);
    UI.tabs(box, keys.map(k => [k, d.sections[k].title, () => { current = k; const s = d.sections[k];
      // FLEETOPS_ACCESS_ANALYTICS_V1: explanatory observations based only on report aggregates.
      const findings = (d.insights && d.insights[k]) || [];
      const insightHtml = findings.length ? `<section class="panel mb-3" aria-label="Report insights">
        <div class="panel-head"><h2 class="panel-title"><i class="bi bi-lightbulb" aria-hidden="true"></i> Descriptive and diagnostic insights</h2></div>
        <div class="panel-body"><div class="row g-3">${findings.map(item => `<div class="col-12 col-lg-6"><div class="h-100 p-3 border rounded-3">
          <div class="small text-muted mb-1">${item.type === 'diagnostic' ? 'Diagnostic signal' : item.type === 'descriptive' ? 'Descriptive finding' : 'Method / limitation'}</div>
          <div class="fw-semibold mb-1">${UI.esc(item.title)}</div><p class="small mb-0">${UI.esc(item.text)}</p>
        </div></div>`).join('')}</div>
        <p class="small text-muted mt-3 mb-0">Diagnostic signals identify records to examine. They do not prove causes or predict future outcomes.</p></div>
      </section>` : '';
      return insightHtml + (s.note ? `<div class="small text-muted mb-2"><i class="bi bi-info-circle"></i> ${UI.esc(s.note)}</div>` : '') + UI.blocks(s, { maxCharts: 3, key: 'rep-' + k }); }]));
  },
};
App.page('reports', ReportsPage);
// PURCHASING_ANALYTICS_V1: 'purchasing_reports' has its own analytics screen (maintenance/purchasing.js).
