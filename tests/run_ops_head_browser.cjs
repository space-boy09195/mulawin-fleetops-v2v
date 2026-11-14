/* Local, database-free Chrome verification. Uses only Node built-ins and the repository fixtures. */
'use strict';
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');
const root = path.resolve(__dirname, '..');
const storage = path.join(root, 'storage');
const reports = path.join(__dirname, 'reports');
const profile = path.join(storage, 'ops-head-browser-' + Date.now());
const chromePath = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let chrome, socket;
const pending = new Map(); let next = 0;
const report = [];
const pricingOnly = process.argv.includes('--pricing-only');
const planningOnly = process.argv.includes('--planning-only');
const turnoverOnly = process.argv.includes('--turnover-only');
const monitoringOnly = process.argv.includes('--monitoring-only');
const analyticsOnly = process.argv.includes('--analytics-only');
const componentsOnly = process.argv.includes('--components-only');
const tablesOnly = process.argv.includes('--tables-only');
const mapsOnly = process.argv.includes('--maps-only');
const selectedFixture = process.argv.find(arg => arg.startsWith('--fixture='))?.slice('--fixture='.length);
if (selectedFixture && !/^[a-z0-9_-]+\.html$/.test(selectedFixture)) throw new Error('Invalid fixture filename.');
const send = (method, params = {}, sessionId) => new Promise((resolve, reject) => {
  const id = ++next;
  const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timed out: ' + method)); }, 20000);
  pending.set(id, { resolve: value => { clearTimeout(timer); resolve(value); }, reject: error => { clearTimeout(timer); reject(error); } });
  socket.send(JSON.stringify({ id, method, params, ...(sessionId ? {sessionId} : {}) }));
});
(async () => {
  try {
    const endpoint = await new Promise((resolve, reject) => {
      chrome = spawn(chromePath, ['--headless', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', '--user-data-dir=' + profile, 'about:blank'], { windowsHide: true, stdio: ['ignore', 'ignore', 'pipe'] });
      const timer = setTimeout(() => reject(new Error('Chrome did not expose its local debugging endpoint.')), 20000);
      chrome.once('error', error => { clearTimeout(timer); reject(error); });
      chrome.stderr.on('data', data => { const match = String(data).match(/DevTools listening on (ws:\/\/127\.0\.0\.1:\d+\/[^\s]+)/); if (match) { clearTimeout(timer); resolve(match[1]); } });
      chrome.once('exit', code => { clearTimeout(timer); reject(new Error('Chrome exited before startup: ' + code)); });
    });
    socket = new WebSocket(endpoint);
    await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
    socket.onmessage = event => { const response = JSON.parse(event.data), request = pending.get(response.id); if (!request) return; pending.delete(response.id); response.error ? request.reject(new Error(response.error.message)) : request.resolve(response.result); };
    const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
    const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
    const evaluate = async expression => {
      const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true }, sessionId);
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.text + ': ' + r.exceptionDetails.exception?.description);
      return r.result.value;
    };
    await send('Page.enable', {}, sessionId);
    const navigate = async (file, marker) => {
      await send('Page.navigate', { url: pathToFileURL(path.join(__dirname, file.split('?')[0])).href + (file.includes('?') ? '?' + file.split('?')[1] : '') }, sessionId);
      for (let i = 0; i < 200; i++) {
        const text = await evaluate('document.querySelector("#results")?.textContent || ""');
        if (text.includes(marker)) return text;
        await sleep(50);
      }
      const status = await evaluate('document.querySelector("#results")?.textContent || "No fixture status"');
      throw new Error('Fixture did not complete: ' + file + '\n' + status);
    };
    fs.mkdirSync(reports,{recursive:true});
    for (const file of (selectedFixture?[selectedFixture]:mapsOnly?['routes_ui.html']:pricingOnly?['pricing_ui.html']:planningOnly?['ops_head_ui.html']:turnoverOnly?['dispatcher_turnover_ui.html']:monitoringOnly?['dispatcher_monitoring_ui.html']:tablesOnly?['standard_data_table_ui.html']:componentsOnly?['shared_components_ui.html','system_components_ui.html','standard_data_table_ui.html']:analyticsOnly?['ops_analytics_ui.html']:['dispatcher_turnover_ui.html','standard_data_table_ui.html','shared_components_ui.html', 'system_components_ui.html', 'operations_ui.html', 'routes_ui.html', 'ops_head_ui.html', 'dispatcher_monitoring_ui.html', 'trip_approvals_ui.html', 'dispatcher_ui.html', 'ops_reports_ui.html', 'ops_analytics_ui.html'])) {
      const result = await navigate(file, 'RESULT:');
      if(file==='dispatcher_ui.html'){fs.mkdirSync(reports,{recursive:true});fs.writeFileSync(path.join(reports,'dispatcher-refinement-checks.txt'),result+'\n');}
      const summary = result.match(/RESULT: \d+ passed, \d+ failed/)?.[0];
      report.push(file + ': ' + summary);
      if (!summary || !summary.endsWith('0 failed')) {
        fs.writeFileSync(path.join(reports,path.basename(file,'.html')+'-failures.txt'),result+'\n');
        process.exitCode=1;
      }
    }
    if(selectedFixture||mapsOnly||pricingOnly){process.stdout.write(report.join('\n')+'\n');return;}
    if(planningOnly){
      for(const width of [390,820,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:1100,deviceScaleFactor:1,mobile:width<600},sessionId);
        await navigate('ops_head_ui.html?preview=planner-filled','LAYOUT ');
        if(await evaluate('document.documentElement.scrollWidth')>width+1)throw new Error('Planning page overflows at '+width+'px');
        const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,'planning-account-'+width+'.png'),Buffer.from(data,'base64'));
        report.push('PASS explicit account planning layout at '+width+'px');
      }
      process.stdout.write(report.join('\n')+'\n');return;
    }
    if(turnoverOnly){
      for(const width of [390,820,1440]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:1100,screenWidth:width,screenHeight:1100,deviceScaleFactor:1,mobile:width<600},sessionId);
        const result=await navigate('dispatcher_turnover_ui.html','RESULT:');if(!result.includes('0 failed'))throw new Error(result);
        const layout=await evaluate('(()=>{const drawer=document.querySelector(".app-drawer"),r=drawer.getBoundingClientRect();return {scroll:document.documentElement.scrollWidth,left:r.left,right:r.right,overflow:drawer.scrollWidth>drawer.clientWidth+1,dialogs:[...document.querySelectorAll(".modal.show")].map(m=>({title:m.querySelector(".modal-title").textContent,shown:bootstrap.Modal.getInstance(m)?._isShown,transition:bootstrap.Modal.getInstance(m)?._isTransitioning}))};})()');
        if(layout.scroll>width+1||layout.left<0||layout.right>width+1||layout.overflow||layout.dialogs.length)throw new Error('Turnover layout '+width+': '+JSON.stringify(layout));
        const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,'turnover-'+width+'.png'),Buffer.from(data,'base64'));
        report.push('PASS turnover forms, summary and history interactions at '+width+'px; no page or drawer overflow');
      }
      process.stdout.write(report.join('\n')+'\n');fs.writeFileSync(path.join(reports,'turnover-ui.txt'),report.join('\n')+'\n');return;
    }
    if(monitoringOnly){
      for(const [view,width] of [['list',1440],['list',820],['list',390],['history',1440],['history',820],['history',390],['drawer',1440],['drawer',390]]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:1100,screenWidth:width,screenHeight:1100,deviceScaleFactor:1,mobile:width<600},sessionId);
        await navigate('trip_approvals_ui.html?preview='+view,'PREVIEW READY');
        await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
        const layout=await evaluate('(()=>{const table=document.querySelector(".oh-monitor-records"),drawer=document.querySelector(".app-drawer.show"),r=drawer?.getBoundingClientRect();return {scroll:document.documentElement.scrollWidth,left:r?.left,right:r?.right,overflow:drawer?drawer.scrollWidth>drawer.clientWidth+1:false,standard:!!table?.matches(".fleet-data-table"),headers:table?.querySelectorAll("th").length,footers:table?.querySelectorAll(".oh-pagination").length,grid:!!table?.querySelector("[data-rpm-view]"),eye:!!table?.querySelector(".fleet-view .bi-eye")};})()');
        if(layout.scroll>width+1||layout.left<0||layout.right>width+1||layout.overflow||!layout.standard||layout.headers!==6||layout.footers!==1||layout.grid||!layout.eye)throw new Error('Monitoring '+view+' at '+width+'px: '+JSON.stringify(layout));
        const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,'monitoring-'+view+'-'+width+'.png'),Buffer.from(data,'base64'));
        report.push('PASS monitoring '+view+' '+width+'px: standard List-only table, one footer, readable drawer and no page overflow');
      }
      process.stdout.write(report.join('\n')+'\n');fs.writeFileSync(path.join(reports,'monitoring-workflow.txt'),report.join('\n')+'\n');return;
    }
    if(tablesOnly){
      for(const width of [390,820,1190]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,screenWidth:width,screenHeight:1000,deviceScaleFactor:1,mobile:width<600},sessionId);
        const result=await navigate('standard_data_table_ui.html','RESULT:');if(!result.includes('0 failed'))throw new Error(result);
        const layout=await evaluate('(()=>{const c=document.querySelector(".fleet-data-table"),w=c.querySelector(".table-wrap"),b=c.querySelector(".fleet-view");return {document:document.documentElement.scrollWidth,width:c.getBoundingClientRect().width,viewHeight:b.getBoundingClientRect().height,scrollable:w.scrollWidth>w.clientWidth,footer:c.querySelector(".table-pager").textContent};})()');
        if(layout.document>width+1||layout.width>width||layout.viewHeight<32||!layout.footer.includes('Showing 1'))throw new Error('Standard table layout at '+width+': '+JSON.stringify(layout));
        const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,'standard-table-'+width+'.png'),Buffer.from(data,'base64'));
        report.push('PASS approved table surface, View button and integrated footer at '+width+'px; no page overflow');
      }
      process.stdout.write(report.join('\n')+'\n');fs.writeFileSync(path.join(reports,'standard-tables.txt'),report.join('\n')+'\n');return;
    }
    if(componentsOnly){
      for(const width of [390,820,1366]){
        await send('Emulation.setDeviceMetricsOverride',{width,height:1000,screenWidth:width,screenHeight:1000,deviceScaleFactor:1,mobile:width<600},sessionId);
        const result=await navigate('system_components_ui.html','RESULT:');
        if(!result.includes('0 failed'))throw new Error('Cross-module checks failed at '+width+'px: '+result);
        await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
        const layout=await evaluate('(()=>{const d=document.querySelector(".app-drawer"),r=d.getBoundingClientRect();return {document:document.documentElement.scrollWidth,left:r.left,right:r.right,overflow:d.scrollWidth>d.clientWidth+1};})()');
        if(layout.document>width+1||layout.left<0||layout.right>width+1||layout.overflow)throw new Error('Shared components overflow at '+width+'px: '+JSON.stringify(layout));
        report.push('PASS shared component interactions and audit drawer at '+width+'px; no horizontal overflow');
      }
      process.stdout.write(report.join('\n')+'\n');fs.writeFileSync(path.join(reports,'shared-components.txt'),report.join('\n')+'\n');return;
    }
    if(process.argv.includes('--fixtures-only')){
      process.stdout.write(report.join('\n')+'\n');
      fs.writeFileSync(path.join(reports,'ops-head-fixtures.txt'),report.join('\n')+'\n');
      return;
    }
    if(!analyticsOnly){
    for(const [name,width,height] of [['route-request-desktop',1440,1100],['route-request-tablet',820,1100],['route-request-mobile',390,844]]){
      await send('Emulation.setDeviceMetricsOverride',{width,height,screenWidth:width,screenHeight:height,deviceScaleFactor:1,mobile:width<600},sessionId);
      await navigate('routes_ui.html?preview=1','ROUTE PREVIEW READY');
      await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
      const layout=await evaluate('(()=>{const modal=document.querySelector(".modal-content"),r=modal.getBoundingClientRect(),form=modal.querySelector("form")||modal.closest("form");return {width:innerWidth,scroll:document.documentElement.scrollWidth,left:r.left,right:r.right,overflow:modal.scrollWidth>modal.clientWidth+1,map:!!document.querySelector(".leaflet-container"),steps:document.querySelectorAll(".rpm-route-steps b").length,option:document.querySelector("[name=route_option]")?.type,distance:document.querySelector("[data-client-km]").textContent};})()');
      if(layout.scroll>width+1||layout.left<0||layout.right>width+1||layout.overflow||!layout.map||layout.steps!==3||layout.option!=='select-one'||layout.distance!=='50.00 km')throw new Error(name+' route form layout failed: '+JSON.stringify(layout));
      const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,name+'.png'),Buffer.from(data,'base64'));
      report.push('PASS '+name+' layout '+width+'px; real map and visible routing option');
    }
    for (const [name, view, width, height] of [['desktop', 'dashboard', 1440, 1000], ['planner-mobile', 'planner', 390, 1100], ['planner-desktop','planner-filled',1440,1100], ['planner-tablet','planner-filled',820,1150], ['planner-review-mobile','planner-review',390,1100], ['trips-mobile', 'active', 390, 1000], ['incidents-mobile', 'incidents', 390, 1000], ['trucks-master-desktop','trucks-masterlist',1440,1000], ['trucks-master-tablet','trucks-masterlist',820,1000], ['trucks-master-mobile','trucks-masterlist',390,1000], ['trucks-availability-desktop','trucks-availability',1440,1000], ['trucks-availability-tablet','trucks-availability',820,1000], ['trucks-availability-mobile','trucks-availability',390,1000]]) {
      await send('Emulation.setDeviceMetricsOverride', {width, height, screenWidth: width, screenHeight: height, deviceScaleFactor: 1, mobile: width < 600}, sessionId);
      await navigate('ops_head_ui.html?preview=' + view, 'LAYOUT ');
      const layout = await evaluate('({width:innerWidth, scroll:document.documentElement.scrollWidth, viewport:document.documentElement.clientWidth})');
      if (layout.scroll > layout.width + 1 || layout.width !== width) throw new Error(name + ' overflow / incorrect viewport: ' + JSON.stringify(layout));
      if(view==='dashboard'){
        const dashboard=await evaluate('({cards:document.querySelectorAll(".oh-dashboard-summary > .oh-metric").length,menus:document.querySelectorAll(".sidebar-nav .nav-item-link").length,sidebar:getComputedStyle(document.querySelector(".sidebar")).backgroundColor,primary:getComputedStyle(document.querySelector(".btn-primary")||document.querySelector(".sidebar")).backgroundColor})');
        if(dashboard.cards!==4||dashboard.menus!==11||dashboard.sidebar!=='rgb(25, 106, 64)')throw new Error('Operations Head dashboard/sidebar scope failed: '+JSON.stringify(dashboard));
      }
      if(view.startsWith('planner')&&view!=='planner-review'){
        const fields=await evaluate('(()=>{const qty=document.querySelector("[name=quantity]").getBoundingClientRect(),dispatcher=document.querySelector("[name=dispatcher_assignment]").getBoundingClientRect();return {quantity:{top:qty.top,bottom:qty.bottom,left:qty.left,right:qty.right},dispatcher:{top:dispatcher.top,bottom:dispatcher.bottom,left:dispatcher.left,right:dispatcher.right},total:document.querySelector("[data-total]").textContent};})()');
        if(fields.dispatcher.top<fields.quantity.bottom)throw new Error(name+' numbered sections overlap: '+JSON.stringify(fields));
        if(view==='planner-filled'&&fields.total!=='Total Required Trucks: 9')throw new Error(name+' did not retain independent entry quantities');
        const planning=await evaluate('({sections:document.querySelectorAll(".oh-numbered-section").length,stages:document.querySelectorAll(".oh-planning-stages [aria-current=step]").length,timezone:document.querySelector(".oh-planner").textContent.includes("PHT")||document.querySelector(".oh-planner").textContent.includes("Asia/Manila"),columns:getComputedStyle(document.querySelector(".oh-planning-columns")).gridTemplateColumns.split(" ").length})');
        if(planning.sections!==3||planning.stages!==1||planning.timezone||planning.columns!==(width>1100?2:1))throw new Error(name+' planning sections/layout failed: '+JSON.stringify(planning));
      }
      const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }, sessionId);
      fs.mkdirSync(reports, {recursive: true});
      fs.writeFileSync(path.join(reports, 'ops-head-' + name + '.png'), Buffer.from(data, 'base64'));
      report.push('PASS ' + name + ' layout ' + width + 'px; no document overflow');
    }
    for(const [name,view,width,height] of [['monitoring-desktop','list',1440,1000],['monitoring-mobile','list',390,1000],['monitoring-history','history',820,1100],['monitoring-drawer-desktop','drawer',1440,1000],['monitoring-drawer-mobile','drawer',390,1000],['driver-form-desktop','driver-form',1440,1000],['helper-form-mobile','helper-form',390,844]]) {
      await send('Emulation.setDeviceMetricsOverride',{width,height,screenWidth:width,screenHeight:height,deviceScaleFactor:1,mobile:width<600},sessionId);
      await navigate('trip_approvals_ui.html?preview='+view,'PREVIEW READY');
      await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
      const layout=await evaluate('(()=>{const el=document.querySelector(".modal.show .modal-content")||document.querySelector(".app-drawer.show"),r=el?.getBoundingClientRect();return {scroll:document.documentElement.scrollWidth,left:r?.left,right:r?.right,overflow:el?el.scrollWidth>el.clientWidth+1:false};})()');
      if(layout.scroll>width+1||layout.left<0||layout.right>width+1||layout.overflow)throw new Error(name+' inaccessible layout: '+JSON.stringify(layout));
      const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,'ops-head-'+name+'.png'),Buffer.from(data,'base64'));report.push('PASS '+name+' layout '+width+'px; no document overflow');
    }
    for (const [name,view,width,height,role] of [
      ['dispatcher-dashboard-desktop','dashboard',1440,1100,'cw'],['dispatcher-dashboard-wide','dashboard',1920,1000,'cc'],['dispatcher-dashboard-tablet','dashboard',820,1100,'cw'],['dispatcher-dashboard-mobile','dashboard',390,1100,'cc'],
      ['dispatcher-operations-mobile','operations',390,1100,'cw'],['dispatcher-request-desktop','requests',1440,1100,'cw'],['dispatcher-request-mobile','requests',390,1100,'cc'],['dispatcher-funds-desktop','funds',1440,1000,'cw'],['dispatcher-drawer-mobile','drawer',390,1000,'cc'],
      ['dispatcher-filters-desktop','filters',1440,1000,'cw'],['dispatcher-filters-mobile','filters',390,844,'cc'],['dispatcher-filters-tablet','filters',820,1000,'cw'],['dispatcher-filters-small-mobile','filters',390,568,'cw'],
      ['dispatcher-fund-form-desktop','fund-form',1440,1000,'cw'],['dispatcher-fund-form-mobile','fund-form',390,844,'cc'],['dispatcher-incident-form-desktop','incident-form',1440,1000,'cc'],['dispatcher-incident-form-mobile','incident-form',390,844,'cw'],
      ['dispatcher-documents-mobile','documents',390,844,'cw'],['dispatcher-availability-tablet','availability',820,1000,'cc'],['dispatcher-request-records-desktop','request-records',1440,1000,'cc'],['dispatcher-diesel-mobile','diesel',390,844,'cw'],['dispatcher-incidents-desktop','incidents',1440,1000,'cw'],
      ['dispatcher-history-desktop','history',1440,1000,'cw'],['dispatcher-history-tablet','history',820,1000,'cc'],['dispatcher-history-empty-desktop','history-empty',1440,1000,'cc'],['dispatcher-history-empty-mobile','history-empty',390,844,'cw'],['dispatcher-availability-desktop','availability',1440,1000,'cw'],
      ['dispatcher-availability-laptop','availability',1100,900,'cw'],['dispatcher-personnel-desktop','availability-personnel',1440,1000,'cw'],['dispatcher-personnel-tablet','availability-personnel',820,1000,'cc'],['dispatcher-availability-mobile','availability',390,844,'cw'],['dispatcher-personnel-mobile','availability-personnel',390,844,'cc'],['dispatcher-availability-empty','availability-empty',1440,1000,'cw'],['dispatcher-availability-filters','availability-filters',820,1000,'cw'],['dispatcher-attendance-desktop','attendance',1440,1000,'cw'],['dispatcher-attendance-mobile','attendance',390,844,'cc'],['dispatcher-assignment-desktop','assignment-form',1440,1000,'cw'],['dispatcher-assignment-mobile','assignment-form',390,844,'cc'],['dispatcher-trip-pills-desktop','drawer',1440,1000,'cw'],['dispatcher-trip-fuel-mobile','drawer-fuel',390,844,'cw'],['dispatcher-availability-tabs-desktop','availability-tabs',1440,1000,'cw'],['dispatcher-availability-tabs-mobile','availability-tabs-personnel',390,844,'cc']]) {
      await send('Emulation.setDeviceMetricsOverride',{width,height,screenWidth:width,screenHeight:height,deviceScaleFactor:1,mobile:width<600},sessionId);
      await navigate('dispatcher_ui.html?preview='+view+'&role='+role,'DISPATCHER PREVIEW READY');
      if(view.startsWith('drawer'))for(let attempt=0;attempt<60;attempt++){if(await evaluate('(()=>{const el=document.querySelector(".ds-trip-drawer");return !!el&&Math.abs(el.getBoundingClientRect().right-innerWidth)<1;})()'))break;await sleep(50);}
      if(view.endsWith('-form'))await evaluate('new Promise(resolve=>setTimeout(resolve,350))');
      if(view==='filters'||view==='availability-filters'){
        await send('Page.bringToFront',{},sessionId);
        await evaluate('(()=>{const p=document.querySelector(".ds-filter-panel");if(p.hidden)document.querySelector(".ds-filter-toggle").click();p.focus();})()');
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27},sessionId);await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27},sessionId);
        const closed=await evaluate('document.querySelector(".ds-filter-panel").hidden && document.activeElement.matches(".ds-filter-toggle")');if(!closed)throw new Error(name+' Escape/focus return failed: '+await evaluate('JSON.stringify({hidden:document.querySelector(".ds-filter-panel").hidden,active:document.activeElement.tagName+"."+document.activeElement.className})'));
        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,text:'\r',unmodifiedText:'\r'},sessionId);await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13},sessionId);
        const keyboard=await evaluate('(()=>{const p=document.querySelector(".ds-filter-panel"),s=getComputedStyle(document.activeElement),rgb=s.outlineColor.match(/[\\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}),l=rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;return {open:!p.hidden,focus:p.contains(document.activeElement),width:parseFloat(s.outlineWidth),color:s.outlineColor,style:s.outlineStyle,ratio:1.05/(l+.05),element:document.activeElement.outerHTML};})()');if(!keyboard.open||!keyboard.focus||keyboard.width<2||keyboard.ratio<3)throw new Error(name+' keyboard reopening / visible focus contrast failed: '+JSON.stringify(keyboard));
        report.push('PASS '+name+' native Escape/Enter and keyboard focus contrast');
      }
      const layout=await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth,cards:document.querySelectorAll(".ds-summary-card").length,columns:document.querySelector(".ds-summary")?getComputedStyle(document.querySelector(".ds-summary")).gridTemplateColumns.split(" ").length:0,drawer:(()=>{const el=document.querySelector(".ds-trip-drawer");return el?{right:el.getBoundingClientRect().right,footer:el.querySelector(".ds-drawer-footer").getBoundingClientRect().bottom}:null;})()})');
      if(layout.width!==width||layout.scroll>width+1)throw new Error(name+' overflow: '+JSON.stringify(layout));
      if(view==='dashboard'&&(layout.cards!==4||layout.columns!==(width<=600?1:2)))throw new Error(name+' summary layout incorrect: '+JSON.stringify(layout));
      if(view.startsWith('drawer')&&(!layout.drawer||Math.abs(layout.drawer.right-width)>1||layout.drawer.footer>height+1))throw new Error(name+' invalid drawer: '+JSON.stringify(layout));
      if(view.startsWith('drawer')){
        const pills=await evaluate('(()=>{const n=document.querySelector(".ds-trip-tabs"),buttons=[...n.children],y=buttons[0].getBoundingClientRect().top;return {count:buttons.length,oneLine:buttons.every(b=>Math.abs(b.getBoundingClientRect().top-y)<1),visible:document.querySelectorAll(".ds-trip-pane:not([hidden])").length,progress:!!document.querySelector(".ds-trip-drawer .wf-steps"),clipped:n.getBoundingClientRect().right>innerWidth+1};})()');
        if(pills.count!==6||!pills.oneLine||pills.visible!==1||pills.progress||pills.clipped)throw new Error(name+' trip-pill layout failed: '+JSON.stringify(pills));
      }
      if(view.startsWith('history')){
        const table=await evaluate('(()=>{const p=document.querySelector(".ds-records"),h=p.querySelector("thead"),f=p.querySelector(".ds-pagination");return {headers:h.querySelectorAll("th").length,headerVisible:getComputedStyle(h).display!=="none",light:getComputedStyle(h.querySelector("th")).backgroundColor.match(/[0-9]+/g).slice(0,3).every(n=>+n>200),elevated:getComputedStyle(p).boxShadow!=="none",footerInside:p.contains(f),rowShadow:getComputedStyle(p.querySelector("tbody tr")).boxShadow,empty:!!p.querySelector(".ds-empty-state")};})()');
        if(table.headers!==5||!table.light||!table.elevated||!table.footerInside||table.rowShadow!=='none'||(view==='history-empty'&&(!table.empty||!table.headerVisible)))throw new Error(name+' inconsistent table surface: '+JSON.stringify(table));
      }
      if(view.startsWith('availability')){
        const master=await evaluate('(()=>{const h=document.querySelector(".ds-availability-head"),r=document.querySelector(".ds-records");return {title:getComputedStyle(h.querySelector(".page-title")).color,badge:!!h.querySelector(".ds-readonly-reference"),combined:!!document.querySelector(".ds-availability-tabs"),records:!!r,footer:!!r?.querySelector(".table-pager"),edit:[...r.querySelectorAll("[data-act]")].some(b=>b.dataset.act!=="view"),clipped:[...document.querySelectorAll(".ds-filterbar input,.ds-filterbar button,.table-tools select,.table-pager button")].filter(el=>el.offsetParent!==null).some(el=>{const b=el.getBoundingClientRect();return b.left<0||b.right>innerWidth+1;})};})()');
        if(master.title!=='rgb(33, 75, 52)'||master.badge||!master.combined||!master.records||!master.footer||master.edit||master.clipped)throw new Error(name+' master-list layout failed: '+JSON.stringify(master));
      }
      if(view==='filters'||view==='availability-filters'||view.endsWith('-form')){
        const bounds=await evaluate('(()=>{const el=document.querySelector(".ds-filter-panel:not([hidden])")||document.querySelector(".modal.show .modal-content");if(!el)return null;const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,overflow:el.scrollWidth>el.clientWidth+1,clipped:[...el.querySelectorAll("input,select,textarea,button")].filter(x=>x.offsetParent!==null).some(x=>{const b=x.getBoundingClientRect();return b.left<r.left-1||b.right>r.right+1;})};})()');
        if(!bounds||bounds.left<0||bounds.right>width+1||(view.endsWith('-form')&&(bounds.top<0||bounds.bottom>height+1))||bounds.overflow||bounds.clipped)throw new Error(name+' inaccessible controls: '+JSON.stringify(bounds));
      }
      const {data}=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId);fs.writeFileSync(path.join(reports,name+'.png'),Buffer.from(data,'base64'));report.push('PASS '+name+' layout '+width+'px; no document overflow');
    }
    for(const [name,view,width,height] of [['reports-trips-desktop','trips',1440,1100],['reports-trucks-tablet','trucks',820,1100],['reports-incidents-mobile','incidents',390,1100],['reports-trips-mobile','trips',390,1100],['reports-empty-desktop','empty',1440,1000]]){
      await send('Emulation.setDeviceMetricsOverride',{width,height,screenWidth:width,screenHeight:height,deviceScaleFactor:1,mobile:width<600},sessionId);
      await navigate('ops_reports_ui.html?preview='+view,'REPORT PREVIEW READY');
      const layout=await evaluate('(()=>{const nav=document.querySelector(".oh-report-tabs"),tabs=[...nav.querySelectorAll("[role=tab]")],rows=document.querySelectorAll("tbody tr[data-ri]");return {width:innerWidth,scroll:document.documentElement.scrollWidth,rows:rows.length,tabs:tabs.length,wrapped:tabs.some(t=>Math.abs(t.getBoundingClientRect().top-tabs[0].getBoundingClientRect().top)>1),selected:tabs.filter(t=>t.getAttribute("aria-selected")==="true").length,cards:document.querySelectorAll(".oh-report-cards article").length,charts:document.querySelectorAll(".oh-report-chart canvas").length,size:!!document.querySelector("[data-rpm-size]"),header:getComputedStyle(document.querySelector(".data-table th")||document.querySelector(".oh-report-records")).backgroundColor,navScroll:nav.scrollWidth>nav.clientWidth,mainOrder:[...document.querySelector(".oh-reports").children].map(e=>e.className)};})()');
      if(layout.scroll>width+1||layout.rows>10||layout.tabs!==3||layout.wrapped||layout.selected!==1||layout.cards!==4||layout.charts!==1||layout.size)throw new Error(name+' report layout failed: '+JSON.stringify(layout));
      await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId).then(({data})=>fs.writeFileSync(path.join(reports,'ops-head-'+name+'.png'),Buffer.from(data,'base64')));
      report.push('PASS '+name+' layout '+width+'px; horizontal tabs and no document overflow');
    }
    }
    for(const [name,view,width,height] of [['analytics-fleet-wide','fleet-full',1920,1100],['analytics-fleet-empty','fleet',1440,1100],['analytics-fleet-laptop','fleet-full',1366,900],['analytics-trips-laptop','trips',1280,1000],['analytics-fleet-mobile','fleet',390,1100]]){
      await send('Emulation.setDeviceMetricsOverride',{width,height,screenWidth:width,screenHeight:height,deviceScaleFactor:1,mobile:width<600},sessionId);
      await navigate('ops_analytics_ui.html?preview='+view,'ANALYTICS PREVIEW READY');
      const layout=await evaluate('(()=>{const h=document.querySelector(".oh-analytics-head"),charts=[...document.querySelectorAll(".oh-analytics .chart-panel")],tabs=[...document.querySelectorAll(".oh-analytics-workspace > [role=tablist] > [data-tab]")],metrics=[...document.querySelectorAll(".oh-analytics .metric")];return {width:innerWidth,scroll:document.documentElement.scrollWidth,header:getComputedStyle(h).position,print:h.querySelectorAll("button").length,tabs:tabs.map(t=>({top:t.getBoundingClientRect().top,active:t.classList.contains("active")})),charts:charts.map(c=>({top:c.getBoundingClientRect().top,left:c.getBoundingClientRect().left,height:c.getBoundingClientRect().height})),metrics:metrics.length,overflow:metrics.some(m=>m.scrollWidth>m.clientWidth+1),empty:[...document.querySelectorAll(".chart-panel .table-empty")].map(e=>e.getBoundingClientRect().height)};})()');
      if(layout.scroll>width+1||layout.header!=='static'||layout.print!==2||layout.tabs.length!==2||layout.tabs.some(t=>Math.abs(t.top-layout.tabs[0].top)>1)||layout.tabs.filter(t=>t.active).length!==1||layout.charts.length!==(view==='trips'?3:2)||layout.metrics!==(view==='trips'?7:4)||layout.overflow)throw new Error(name+' analytics layout failed: '+JSON.stringify(layout));
      if(width>1100&&Math.abs(layout.charts[0].top-layout.charts[1].top)>2)throw new Error(name+' related charts are not side by side');
      if(view==='fleet'&&layout.empty.some(h=>h<230))throw new Error(name+' empty charts lost their balanced height');
      await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:false},sessionId).then(({data})=>fs.writeFileSync(path.join(reports,'ops-head-'+name+'.png'),Buffer.from(data,'base64')));
      report.push('PASS '+name+' layout '+width+'px; original analytics and no page overflow');
    }
    await navigate('ops_analytics_ui.html?preview=fleet','ANALYTICS PREVIEW READY');
    await evaluate('document.querySelectorAll(".oh-analytics-head .page-actions button")[1].click()');
    await sleep(150);
    const print=await evaluate('({text:document.querySelector("#printArea").textContent,images:document.querySelectorAll("#printArea img").length})');
    if(!print.text.includes('Trips per client')||!print.text.includes('Maintenance expenses')||!print.text.includes('Highest downtime')||!print.text.includes('Trip records (delivery and return)'))throw new Error('Analytics Print all omitted original content');
    await send('Emulation.setEmulatedMedia',{media:'print'},sessionId);
    const printLayout=await evaluate('(()=>{const a=document.querySelector("#printArea");return {display:getComputedStyle(a).display,overflow:[...a.querySelectorAll(".pr-chart img")].some(i=>{const b=i.getBoundingClientRect(),p=i.parentElement.getBoundingClientRect();return b.width>p.width+1;})};})()');
    if(printLayout.display==='none'||printLayout.overflow)throw new Error('Original analytics print layout hides content or clips charts: '+JSON.stringify(printLayout));
    const pdf=await send('Page.printToPDF',{printBackground:true,preferCSSPageSize:true},sessionId);fs.writeFileSync(path.join(reports,'ops-head-analytics-print-all.pdf'),Buffer.from(pdf.data,'base64'));report.push('PASS original analytics Print all generated a PDF with original report sections');
    await send('Emulation.setEmulatedMedia',{media:'screen'},sessionId);
    if(!analyticsOnly){
    const downloadDir=path.join(reports,'operations-reports-download-'+Date.now());fs.mkdirSync(downloadDir,{recursive:true});
    await send('Browser.setDownloadBehavior',{behavior:'allow',downloadPath:downloadDir});
    await navigate('ops_reports_ui.html?preview=trips&last=1&download=1','REPORT PREVIEW READY');
    await evaluate('document.querySelector("[data-report-export]").click()');
    let csvFile;
    for(let attempt=0;attempt<100;attempt++){csvFile=fs.readdirSync(downloadDir).find(f=>f.endsWith('.csv'));if(csvFile)break;await sleep(50);}
    if(!csvFile)throw new Error('Export Report did not produce a real CSV download');
    const csv=fs.readFileSync(path.join(downloadDir,csvFile),'utf8');
    if(!csv.includes('REPORT-TRIP-1')||!csv.includes('REPORT-TRIP-37')||csv.trim().split('\r\n').length!==38)throw new Error('Report download did not include all 37 matching records from page four');
    report.push('PASS real Reports CSV download: 37 matching records exported from page four');
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]},sessionId);
    await navigate('dispatcher_ui.html?preview=dashboard','DISPATCHER PREVIEW READY');
    const card=await evaluate('(()=>{const r=document.querySelector(".ds-summary-card").getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2};})()');
    await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:card.x,y:card.y},sessionId);
    const reduced=await evaluate('(()=>{const c=document.querySelector(".ds-summary-card"),s=getComputedStyle(c);return c.matches(":hover")&&s.transitionDuration==="0s"&&s.transform==="none";})()');
    if(!reduced)throw new Error('Reduced-motion card hover did not suppress movement');
    report.push('PASS Dispatcher reduced-motion preference; no card hover movement or transition');
    }
    process.stdout.write(report.join('\n') + '\n');
    fs.writeFileSync(path.join(reports, 'ops-head-browser.txt'), report.join('\n') + '\n');
  } catch (error) { process.stderr.write(report.join('\n') + '\nFAIL ' + error.stack + '\n'); process.exitCode = 1; }
  finally {
    if (socket?.readyState === WebSocket.OPEN) { try { await send('Browser.close'); } catch {} socket.close(); }
    if (chrome && chrome.exitCode === null) { await sleep(500); if (chrome.exitCode === null) chrome.kill(); }
    await sleep(300);
    // Delete only the generated profile, after verifying the absolute path remains inside this checkout.
    const checked = path.resolve(profile);
    if (checked.startsWith(path.resolve(storage) + path.sep) && /^ops-head-browser-\d+$/.test(path.basename(checked))) {
      try { fs.rmSync(checked, {recursive: true, force: true, maxRetries: 3, retryDelay: 200}); } catch { process.stderr.write('Generated browser profile remains at ' + checked + '\n'); }
    }
  }
})();
