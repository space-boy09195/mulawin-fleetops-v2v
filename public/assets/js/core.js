/* =====================================================================
   assets/js/core.js — app shell, router and shared screen helpers
   =====================================================================
   - App.start()        sign in (password + email code) and the main shell
   - App.page(id, def)  each module file registers its pages here
   - App.api(name, d)   POST to the backend (Module.action)
   - UI.*               tables, forms, modals, filters, drawers, badges, charts, map, signature
   MODULE ISOLATION: every page loads its own data. If one module file
   (assets/js/modules/<department>/*.js) or its backend fails, only that page shows
   an error; the sidebar and the other pages keep working.
   ===================================================================== */
'use strict';

const UI = {};
const App = {
  pages: {}, me: null, csrf: '', current: null, timer: null,

  /** Called by module files: App.page('trips', {title, subtitle, render}) */
  page(id, def) { this.pages[id] = def; },

  // ---------------------------------------------------------------- API
  async request(url, opts = {}) {
    let res, data;
    try { res = await fetch(url, { credentials: 'same-origin', ...opts }); }
    catch { throw new Error('Cannot reach the server. Check that Apache (XAMPP) is running.'); }
    try { data = await res.json(); }
    catch { throw new Error('The server returned an invalid answer. Check the PHP error log.'); }
    if (res.status === 401 && this.me) { this.me = null; clearInterval(this.timer); this.loginScreen(data.error); throw new Error(data.error || 'Please log in.'); }
    if (!res.ok || !data.ok) { const error = new Error(data.error || 'Request failed.'); error.status = res.status; throw error; }
    return data;
  },
  async api(action, payload = {}) {
    const r = await this.request('index.php?api=' + encodeURIComponent(action), {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf }, body: JSON.stringify(payload) });
    return r.data || {};
  },
  async data(page, query = {}) {
    const qs = new URLSearchParams({ api: 'data', page, ...query });
    return (await this.request('index.php?' + qs.toString())).data;
  },
  /** POST with files: normal fields go in "payload" (JSON), files keep their input names. */
  async apiForm(action, payload, form, extraFiles = {}) {
    const fd = new FormData();
    fd.append('payload', JSON.stringify(payload || {}));
    if (form) form.querySelectorAll('input[type=file][name]').forEach(inp => [...inp.files].forEach(f => fd.append(inp.name + (inp.multiple ? '[]' : ''), f)));
    Object.entries(extraFiles).forEach(([name, file]) => file && fd.append(name, file, file.name || name + '.png'));
    const r = await this.request('index.php?api=' + encodeURIComponent(action), { method: 'POST', headers: { 'X-CSRF-Token': this.csrf }, body: fd });
    return r.data || {};
  },
  async upload(formData) {
    formData.append('csrf', this.csrf);
    return (await this.request('index.php?api=document_upload', { method: 'POST', body: formData })).data;
  },

  // ---------------------------------------------------------------- start
  async start() {
    try {
      const w = await this.request('index.php?api=whoami');
      this.csrf = w.csrf;
      if (w.signedIn) return await this.loadMe();
      this.loginScreen();
    } catch (e) { this.loginScreen(e.message); }
  },
  async loadMe() {
    const me = await this.request('index.php?api=me');
    this.me = me; this.csrf = me.csrf;
    if (me.user?.setup_required) return this.passwordScreen(true);
    this.shell();
    window.onhashchange = () => this.route();
    this.route();
    clearInterval(this.timer);
    this.timer = setInterval(() => this.refreshBell(), 30000);
  },
  async postAuth(api, body) {
    const w = await this.request('index.php?api=whoami'); this.csrf = w.csrf;
    return this.request('index.php?api=' + api, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf: this.csrf }) });
  },

  /** Two-column sign-in frame used by the password and recovery screens. */
  authFrame(inner) {
    document.getElementById('app').innerHTML = `
    <div class="auth-page"><div class="auth-shell">
      <section class="auth-side" aria-hidden="true">
        <div class="auth-side-brand"><span class="auth-logo-frame"><img src="assets/img/logo.png" alt="" class="auth-logo" onerror="this.remove()"></span>
          <div><div class="auth-side-company">R.P. Mulawin Trucking Services</div><div class="auth-side-product">Mulawin FleetOps</div></div></div>
        <div class="auth-side-copy"><h2>Fleet operations and financial monitoring</h2>
          <p>Manage trips, fleet, maintenance, purchasing, billing and company records in one secure system.</p></div>
      </section>
      <main class="auth-main"><div class="auth-card" id="authCard">${inner}</div>
        <p class="auth-foot">Mulawin FleetOps &copy; ${new Date().getFullYear()} · Authorized personnel only</p></main>
    </div></div>`;
    return document.getElementById('authCard');
  },
  authAlert(message, ok) {
    return message ? `<div class="alert alert-${ok ? 'success' : 'danger'} auth-alert" role="alert">${UI.esc(message)}</div>` : '';
  },

  loginScreen(message = '', username = '', ok = false) {
    this.authFrame(`
      <div class="auth-head"><h1>Sign in</h1><p>Enter your username or email and password.</p></div>
      ${this.authAlert(message, ok)}
      <form id="loginForm" novalidate>
        <div class="mb-3"><label class="form-label" for="lgU">Username or email</label>
          <input class="form-control" id="lgU" name="username" autocomplete="username" value="${UI.esc(username)}" required></div>
        <div class="mb-4"><label class="form-label" for="lgP">Password</label>
          <div class="input-group"><input class="form-control" type="password" id="lgP" name="password" autocomplete="current-password" required>
          <button class="btn btn-outline-secondary" type="button" id="lgEye" aria-label="Show password"><i class="bi bi-eye"></i></button></div></div>
        <button class="btn btn-primary w-100 auth-btn" type="submit">Sign in</button>
        <div class="auth-links"><span>Forgot your password? Contact the Admin or authorized IT support.</span></div>
      </form>`);
    document.getElementById(username ? 'lgP' : 'lgU').focus();
    document.getElementById('lgEye').onclick = (e) => { const p = document.getElementById('lgP'); p.type = p.type === 'password' ? 'text' : 'password'; e.currentTarget.innerHTML = `<i class="bi bi-eye${p.type === 'password' ? '' : '-slash'}"></i>`; };
    document.getElementById('loginForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = Object.fromEntries(new FormData(e.target));
      if (!f.username || !f.password) return this.loginScreen('Enter your username and password.', f.username || '');
      const btn = e.target.querySelector('button[type=submit]'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Checking credentials…';
      try { await this.postAuth('login', f); location.hash = ''; await this.loadMe(); }
      catch (err) { this.loginScreen(err.message, f.username || ''); }
    };
  },

  /** Password visibility is temporary and local to these two account-security forms. */
  securityPasswordEyes(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll('input[type="password"]').forEach(input => {
      if (input.parentElement?.classList.contains('input-group')) return;
      const group = document.createElement('div');
      group.className = 'input-group';
      input.parentNode.insertBefore(group, input);
      group.appendChild(input);
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'btn btn-outline-secondary';
      toggle.setAttribute('aria-label', 'Show password');
      toggle.setAttribute('aria-pressed', 'false');
      toggle.innerHTML = '<i class="bi bi-eye" aria-hidden="true"></i><span class="ms-1">Show</span>';
      toggle.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        toggle.innerHTML = visible
          ? '<i class="bi bi-eye-slash" aria-hidden="true"></i><span class="ms-1">Hide</span>'
          : '<i class="bi bi-eye" aria-hidden="true"></i><span class="ms-1">Show</span>';
      });
      group.appendChild(toggle);
    });
  },

  /** Security S2: users prove ownership of a replacement email before it becomes active. */
  emailScreen(message = '', stage = 'request') {
    const fields = stage === 'request' ? UI.fields([
      { name: 'current_password', label: 'Current password', type: 'password', required: true, col: 12 },
      { name: 'new_email', label: 'New email address', type: 'email', required: true, col: 12 }
    ]) : UI.fields([{ name: 'code', label: '6-digit code sent to the NEW email', required: true, col: 12, attrs: 'inputmode="numeric" maxlength="6" pattern="[0-9]{6}"' }]);
    this.authFrame(`<div class="auth-head"><h1>Change email address</h1><p>Verify that you own the new email before using it for account security.</p></div>
      ${this.authAlert(message, stage === 'confirm')}
      <form id="emailChangeForm" novalidate>${fields}<button class="btn btn-primary w-100 mt-3" type="submit">${stage === 'request' ? 'Send verification code' : 'Verify email'}</button>
      <button class="btn btn-link w-100 mt-2" type="button" id="emailCancel">Back to dashboard</button></form>`);
    this.securityPasswordEyes('emailChangeForm');
    document.getElementById('emailCancel').onclick = () => this.loadMe();
    document.getElementById('emailChangeForm').onsubmit = async (e) => {
      e.preventDefault(); const f = Object.fromEntries(new FormData(e.target));
      if (!await UI.confirmProceed('Confirm email change', stage === 'request' ?
          'Send a verification code to the new email address?' :
          'Verify the code and change your account email address?',
          stage === 'request' ? 'Send code' : 'Change email')) return;
      const btn = e.target.querySelector('button[type=submit]'); btn.disabled = true;
      try {
        if (stage === 'request') {
          const r = await this.api('Admin.email_change_request', f);
          this.emailScreen(r.message || 'Enter the code.', 'confirm');
        } else { await this.api('Admin.email_change_confirm', { code: f.code }); await this.loadMe(); UI.toast('Email verified and updated.'); }
      } catch(err) { this.emailScreen(err.message, stage); }
    };
  },

  /**
   * Password change. First login / after an Admin reset (firstSetup): temporary password + new password, no email code;
   * the server blocks every other screen and API until this succeeds. Normal change: current password + code emailed to the registered address.
   */
  passwordScreen(firstSetup = false, message = '', stage = 'request') {
    const intro = firstSetup ? 'Your temporary password must be replaced before you can use FleetOps.' : 'Confirm your current password, then verify the code sent to your registered email.';
    const fields = stage === 'request' ? UI.fields([
      { name: 'current_password', label: firstSetup ? 'Temporary password' : 'Current password', type: 'password', required: true, col: 12, attrs: 'autocomplete="current-password"' },
      { name: 'new_password', label: 'New password', type: 'password', required: true, col: 12, attrs: 'autocomplete="new-password"', help: (this.me?.passwordMin || 8) + '+ characters with letters and numbers.' },
      { name: 'confirm', label: 'Confirm new password', type: 'password', required: true, col: 12, attrs: 'autocomplete="new-password"' }
    ]) : UI.fields([{ name: 'code', label: '6-digit email verification code', required: true, col: 12, attrs: 'inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}"' }]);
    const submitLabel = firstSetup ? 'Save new password' : (stage === 'request' ? 'Send verification code' : 'Verify and save password');
    this.authFrame(`<div class="auth-head"><h1>${firstSetup ? 'Set up your account' : 'Change password'}</h1><p>${UI.esc(intro)}</p></div>
      ${this.authAlert(message, stage === 'confirm')}
      <form id="securePwForm" novalidate>${fields}
        <button class="btn btn-primary w-100 mt-3" type="submit">${submitLabel}</button>
        ${firstSetup ? '<button class="btn btn-link w-100 mt-2" type="button" id="pwLogout">Sign out</button>' : '<button class="btn btn-link w-100 mt-2" type="button" id="pwCancel">Back to dashboard</button>'}
      </form>`);
    this.securityPasswordEyes('securePwForm');
    document.getElementById('pwCancel')?.addEventListener('click', () => this.loadMe());
    document.getElementById('pwLogout')?.addEventListener('click', () => this.logout());
    document.getElementById('securePwForm').onsubmit = async (e) => {
      e.preventDefault();
      const f = Object.fromEntries(new FormData(e.target));
      if (stage === 'request' && f.new_password !== f.confirm) return this.passwordScreen(firstSetup, 'New passwords do not match.', 'request');
      if (!firstSetup && !await UI.confirmProceed('Confirm password change', stage === 'request' ?
          'Send a verification code before changing your password?' :
          'Verify the code and save your new password?',
          stage === 'request' ? 'Send code' : 'Change password')) return;
      const btn = e.target.querySelector('button[type=submit]'); btn.disabled = true;
      try {
        if (firstSetup) {
          await this.api('Admin.password_change_force', f);
          location.hash = ''; // land on the role's own start page
          await this.loadMe();
          UI.toast('Your password was updated.');
        } else if (stage === 'request') {
          const r = await this.api('Admin.password_change_request', f);
          this.passwordScreen(firstSetup, r.message || 'Enter the code from your email.', 'confirm');
        } else {
          await this.api('Admin.password_change_confirm', { code: f.code });
          await this.loadMe();
          UI.toast('Your password was updated.');
        }
      } catch (err) { this.passwordScreen(firstSetup, err.message, stage); }
    };
  },

  async logout() {
    try { await this.request('index.php?api=logout', { method: 'POST', headers: { 'X-CSRF-Token': this.csrf } }); } catch { }
    clearInterval(this.timer); this.me = null; location.hash = ''; this.start();
  },

  // ---------------------------------------------------------------- shell
  shell() {
    const me = this.me, u = me.user;
    const initials = (u.full_name || u.username).split(/[\s,]+/).filter(Boolean).map(s => s[0]).slice(0, 2).join('').toUpperCase();
    // Role-focused presentation. The server's me.nav remains the sole source of
    // permitted pages; hiding a link does NOT change authorization or routing.
    const mainPages = {
      admin: ['dashboard', 'admin', 'trips', 'reports', 'trucks'],
      operations_head: ['dashboard', 'trips', 'trucks', 'crew', 'clients', 'routes', 'funds'],
      car_carrier_dispatcher: ['dashboard', 'trips', 'funds'],
      container_wingvan_dispatcher: ['dashboard', 'trips', 'funds'],
      // MOTORPOOL_WORKSPACE_PHASE1: the Motorpool workspace in work order (server me.nav still decides what is allowed).
      motorpool: ['maintenance_overview', 'maintenance', 'mp_parts_requests', 'mechanics', 'mechanic_attendance', 'maint_history', 'trucks'],
      purchasing: ['purchasing_overview', 'parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_reports'],
      finance: ['dashboard', 'funds', 'finance', 'purchase_orders', 'purchasing_reports'],
      billing: ['dashboard', 'billing', 'clients', 'routes'],
      audit: ['dashboard', 'reports', 'admin'],
    };
    const shortNames = {
      trips: 'Trips', trucks: 'Trucks', crew: 'Drivers', clients: 'Clients',
      routes: 'Client routes', mechanics: 'Mechanics', maintenance: 'Maintenance', maintenance_overview: 'Maintenance overview',
      funds: 'Trip funds', billing: 'Billing',
      finance: 'Finance', reports: 'Reports', admin: 'Users & settings',
      maint_history: 'Maintenance history', mp_parts_requests: 'Parts requests', mechanic_attendance: 'Mechanic attendance',
      purchasing_overview: 'Purchasing overview', parts_requests: 'Parts requests', purchase_orders: 'Purchase orders',
      inventory: 'Inventory', stock_movements: 'Stock movements', purchasing_reports: 'Purchasing reports',
    };
    // Role-specific menu wording (labels only).
    const roleShortNames = { motorpool: { maintenance: 'Repair management', trucks: 'Truck masterlist', mechanics: 'Mechanic masterlist' } };
    const nameOf = (id) => (roleShortNames[u.role] || {})[id] || shortNames[id];
    const order = mainPages[u.role] || ['dashboard'];
    const allowed = me.nav.filter(n => n.id !== 'notifications'); // bell already links to notifications
    const primary = order.map(id => allowed.find(n => n.id === id)).filter(Boolean);
    const primaryIds = new Set(primary.map(n => n.id));
    const extra = allowed.filter(n => !primaryIds.has(n.id));
    const link = n => `<a href="#${n.id}" class="nav-item-link" data-nav="${n.id}"><i class="bi ${n.icon}" aria-hidden="true"></i><span>${UI.esc(n.id === 'admin' && u.role === 'audit' ? 'Activity log' : n.id === 'funds' && ['car_carrier_dispatcher', 'container_wingvan_dispatcher'].includes(u.role) ? 'My Trip Funds' : nameOf(n.id) || n.label)}</span></a>`;
    const operationsRole = ['operations_head', 'car_carrier_dispatcher', 'container_wingvan_dispatcher'].includes(u.role);
    const tripLink = (label, icon, query) => `<a href="#trips?${query}" class="nav-item-link" data-nav="trips" data-trip-section="${query}"><i class="bi ${icon}" aria-hidden="true"></i><span>${label}</span></a>`;
    let nav;
    if (u.role === 'admin') {
      // The server sends the authoritative executive menu in business-flow
      // order. Preserve its labels and sections instead of re-sorting it into
      // the old Admin shortcuts + More tools groups.
      const sectionIcons = {
        'Operations Oversight': 'bi-activity',
        'Client & Commercial': 'bi-buildings',
        'Financial Oversight': 'bi-graph-up-arrow',
        'Management Control': 'bi-shield-check',
      };
      nav = '';
      let section = null;
      for (const item of allowed) {
        if (item.section !== section) {
          if (section !== null) nav += '</div></details>';
          section = item.section;
          if (section) {
            nav += `<details class="head-nav-group"><summary><i class="bi ${sectionIcons[section] || 'bi-folder2'}" aria-hidden="true"></i><span>${UI.esc(section)}</span><i class="bi bi-chevron-down head-nav-chevron" aria-hidden="true"></i></summary><div class="head-nav-items">`;
          }
        }
        nav += `<a href="#${item.id}" class="nav-item-link" data-nav="${item.id}"><i class="bi ${item.icon}" aria-hidden="true"></i><span>${UI.esc(item.label)}</span></a>`;
      }
      if (section !== null) nav += '</div></details>';
    } else if (u.role === 'operations_head') {
      // Collapsible, role-specific sidebar: render only entries authorized by
      // the server-provided navigation, preserving every existing route target.
      const groups = [
        {name:'Overview',icon:'bi-grid-1x2',ids:['dashboard']},
        {name:'Trip Operations',icon:'bi-signpost-split',ids:['trips','dispatcher-monitoring','funds']},
        {name:'Masterlists',icon:'bi-truck',ids:['trucks','crew','routes','mechanics']},
        {name:'Monitoring & Records',icon:'bi-clipboard-data',ids:['maintenance','reports','documents']}
      ];
      const used = new Set();
      const labels = {
        'dispatcher-monitoring':'Dispatch Monitoring',
        'funds':'Fund Approvals',
        'trucks':'Trucks',
        'crew':'Drivers & Helpers',
        'routes':'Client Routes',
        'maintenance':'Incidents & Repairs'
      };
      const renderGroup = group => {
        const entries=group.ids.map(id=>allowed.find(item=>item.id===id))
          .filter(item=>item && !used.has(item.id));
        if(!entries.length)return '';
        entries.forEach(item=>used.add(item.id));
        return '<details class="head-nav-group ops-head-nav-group" data-sidebar-group="'+UI.esc(group.name)+'">'
          +'<summary><i class="bi '+group.icon+'" aria-hidden="true"></i><span>'+UI.esc(group.name)+'</span>'
          +'<i class="bi bi-chevron-down head-nav-chevron" aria-hidden="true"></i></summary>'
          +'<div class="head-nav-items">'+entries.map(item=>
            '<a href="#'+item.id+'" class="nav-item-link" data-nav="'+item.id+'">'
            +'<i class="bi '+item.icon+'" aria-hidden="true"></i><span>'+UI.esc(labels[item.id]||item.label)+'</span></a>'
          ).join('')+'</div></details>';
      };
      nav=groups.map(renderGroup).join('');
      const remaining=allowed.filter(item=>!used.has(item.id));
      if(remaining.length)nav+=renderGroup({name:'Other Tools',icon:'bi-grid-3x3-gap',ids:remaining.map(item=>item.id)});
    } else {
      nav = `<div class="nav-section">My workspace</div>${operationsRole ? primary.filter(n => n.id === 'dashboard').map(link).join('') + '<div class="nav-section">Operations</div>' + tripLink('New Trip', 'bi-plus-circle', 'new=1') + tripLink('Active Trips', 'bi-signpost-split', 'tab=active') + tripLink('Trip History', 'bi-clock-history', 'tab=history') + tripLink('Incidents', 'bi-exclamation-triangle', 'tab=incidents') + primary.filter(n => !['dashboard', 'trips'].includes(n.id)).map(link).join('') : primary.map(link).join('')}`;
      if (extra.length) nav += `<details class="sidebar-more" id="sidebarMore"><summary><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span>More tools</span><i class="bi bi-chevron-down sidebar-more-chevron" aria-hidden="true"></i></summary><div class="sidebar-more-items">${extra.map(link).join('')}</div></details>`;
    }
    // WS_SHELL_V1: Maintenance and Accounting roles (Motorpool, Purchasing, Finance, Billing, Audit) get one grouped
    // workspace menu with the profile at the bottom. Links come only from me.nav (server-side permissions); nothing is added.
    const ws = UI.wsNav(u.role);
    const profileMenu = `<div class="dropdown-header">${UI.esc(u.username)} · ${UI.esc(u.email || '')}</div>
                <button class="dropdown-item" id="mySig"><i class="bi bi-pen"></i> My e-signature${u.has_signature ? '' : ' <span class="badge-s amber">not set</span>'}</button>
                <button class="dropdown-item" id="chgPw"><i class="bi bi-key"></i> Change password</button>
                <button class="dropdown-item" id="chgEmail"><i class="bi bi-envelope-check"></i> Change email</button>
                <div class="dropdown-divider"></div>
                <button class="dropdown-item text-danger" id="logoutBtn"><i class="bi bi-box-arrow-right"></i> Log out</button>`;
    if (ws) {
      const wsLink = (n) => `<a href="#${n.id}" class="nav-item-link" data-nav="${n.id}" title="${UI.esc(n.label)}"><i class="bi ${UI.esc(n.icon)}" aria-hidden="true"></i><span>${UI.esc(n.label)}</span></a>`;
      nav = ws.groups.map(g => `<div class="nav-group"><div class="nav-section">${UI.esc(g.title)}</div>${g.items.map(wsLink).join('')}</div>`).join('');
    }
    // UI CORRECTION: account controls stay in the top bar for every role; the workspace sidebar is navigation only.
    const sideFoot = ws ? '' : `<div class="sidebar-foot">${UI.esc(u.role_label)}${u.shift ? ' · ' + UI.esc(u.shift) + ' shift' : ''}</div>`;
    document.body.classList.toggle('ws-body', !!ws);   // drawers and modals live outside #appShell
    document.body.classList.toggle('ref-body', !!(ws && ws.exclusive));   // REF_FIDELITY_V1: Motorpool / Purchasing drawers
    const executiveSidebar = ['admin','operations_head'].includes(u.role);
    const collapseKey = executiveSidebar ? 'executive-sidebar-collapsed-' + u.role : 'ws-sidebar-collapsed';
    let collapsed = false;
    try { collapsed = (!!ws || executiveSidebar) && localStorage.getItem(collapseKey) === '1'; } catch { /* storage unavailable */ }
    document.getElementById('app').innerHTML = `
    <div class="app-shell${ws ? ' ws-app' : ''}${executiveSidebar ? ' executive-sidebar' : ''}${ws && ws.exclusive ? ' ref-app' : ''}${collapsed ? ' sb-collapsed' : ''}" id="appShell">
      <aside class="sidebar" id="appSidebar" aria-label="Main menu">
        <div class="sidebar-top"><a href="#${this.homePage()}" class="sidebar-brand">${UI.logoTag('sidebar-logo')}<span class="brand-text"><span class="brand-product">${ws ? 'MULAWIN' : 'Mulawin FleetOps'}</span><span class="brand-company">${ws ? (ws.exclusive ? 'FleetOps' : 'FleetOps · ' + UI.esc(ws.dept)) : 'R.P. Mulawin Trucking'}</span></span></a>
          ${ws || executiveSidebar ? `<button type="button" class="sb-collapse sb-desktop" id="sbCollapse" aria-label="${collapsed ? 'Expand' : 'Collapse'} the menu" title="${collapsed ? 'Expand' : 'Collapse'} the menu" aria-pressed="${collapsed}"><i class="bi bi-layout-sidebar-inset" aria-hidden="true"></i></button>
            <button type="button" class="sb-collapse sb-mobile" id="sbClose" aria-label="Close the menu"><i class="bi bi-x-lg" aria-hidden="true"></i></button>` : ''}</div>
        <nav class="sidebar-nav">${nav}</nav>
        ${sideFoot}
      </aside>
      <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
      <div class="main">
        <header class="topbar no-print">
          <button class="icon-btn d-lg-none" id="menuBtn" type="button" aria-label="Open the menu"><i class="bi bi-list"></i></button>
          <div class="crumbs" id="crumbs"></div>
          <div class="topbar-actions">
            <div class="dropdown">
              <button class="icon-btn bell" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-label="Notifications" id="bellBtn"><i class="bi bi-bell"></i><span class="bell-count d-none" id="bellBadge">0</span></button>
              <div class="dropdown-menu dropdown-menu-end bell-menu">
                <div class="bell-head"><span>Notifications</span><button type="button" class="btn btn-link btn-sm p-0" id="bellReadAll">Mark all as read</button></div>
                <div id="bellList" class="bell-list"></div>
                <a href="#notifications" class="bell-foot" id="bellAll">View all notifications</a>
              </div>
            </div>
            <div class="dropdown">
              <button class="profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account settings for ${UI.esc(u.full_name)}"><span class="avatar">${UI.esc(initials)}</span>
                <span class="profile-text"><span class="profile-name">${UI.esc(u.full_name)}</span><span class="profile-role">${UI.esc(u.role_label)}${ws && u.shift ? ' · ' + UI.esc(u.shift) + ' shift' : ''}</span></span><i class="bi bi-chevron-down"></i></button>
              <div class="dropdown-menu dropdown-menu-end">${profileMenu}</div>
            </div>
          </div>
        </header>
        <main class="content" id="pageContent"></main>
      </div>
    </div><div id="printArea"></div>`;
    // RPM STICKY PAGE NAV V1 START: keep the page heading visible and offer an accessible way back.
    if (this._rpmScrollNavigation) window.removeEventListener('scroll', this._rpmScrollNavigation);
    this._rpmScrollNavigation = () => {
      const y = window.scrollY || document.documentElement.scrollTop || 0;
      document.querySelector('#pageContent .page-head')?.classList.toggle('rpm-page-head-scrolled', y > 90);
      const topButton = document.getElementById('rpmBackToTop');
      if (topButton) { topButton.hidden = y <= 90; topButton.setAttribute('aria-label', 'Back to top'); }
    };
    window.addEventListener('scroll', this._rpmScrollNavigation, { passive: true });
    // The page header is recreated on every route, so delegate its Top button.
    document.getElementById('pageContent').addEventListener('click', (event) => {
      if (!event.target.closest('#rpmBackToTop')) return;
      const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: 0, behavior: reduced ? 'instant' : 'smooth' });
    });
    this._rpmScrollNavigation();
    // RPM STICKY PAGE NAV V1 END
    const sb = document.getElementById('appSidebar'), bd = document.getElementById('sidebarBackdrop');
    document.getElementById('menuBtn').onclick = () => { sb.classList.add('open'); bd.classList.add('show'); };
    bd.onclick = () => { sb.classList.remove('open'); bd.classList.remove('show'); };
    document.getElementById('sbClose')?.addEventListener('click', () => bd.onclick());
    document.getElementById('sbCollapse')?.addEventListener('click', (e) => {
      const shell = document.getElementById('appShell'), on = shell.classList.toggle('sb-collapsed'), b = e.currentTarget;
      b.setAttribute('aria-pressed', String(on)); b.title = (on ? 'Expand' : 'Collapse') + ' the menu'; b.setAttribute('aria-label', b.title);
      try { localStorage.setItem(collapseKey, on ? '1' : '0'); } catch { /* storage unavailable */ }
      if (executiveSidebar) document.querySelector('.executive-sidebar-flyout')?.remove();
    });
    this._executiveSidebarAbort?.abort();
    this._executiveSidebarAbort = new AbortController();
    sb.addEventListener('keydown', (e) => { if (e.key === 'Escape' && sb.classList.contains('open')) { bd.onclick(); document.getElementById('menuBtn')?.focus(); } });
    // Shared collapsed group flyout for Head Management and Operations Head.
    // Defer until the Operations-specific shell has finished rebuilding its menu.
    if (executiveSidebar) queueMicrotask(() => {
      if (document.getElementById('appSidebar') !== sb) return;
      document.querySelector('.executive-sidebar-flyout')?.remove();
      const nav = sb.querySelector('.sidebar-nav');
      const closeFlyout = () => document.querySelector('.executive-sidebar-flyout')?.remove();
      const openFlyout = (group, summary) => {
        closeFlyout();
        const flyout = document.createElement('div');
        flyout.className = 'executive-sidebar-flyout';
        flyout.setAttribute('role', 'navigation');
        const label = summary.querySelector('span')?.textContent || 'Navigation';
        flyout.setAttribute('aria-label', label);
        const heading = document.createElement('div');
        heading.className = 'executive-sidebar-flyout-heading';
        heading.textContent = label;
        const links = document.createElement('div');
        links.className = 'executive-sidebar-flyout-links';
        group.querySelectorAll('.head-nav-items a').forEach(link => {
          const copy = link.cloneNode(true);
          copy.removeAttribute('id');
          copy.addEventListener('click', closeFlyout);
          links.append(copy);
        });
        flyout.append(heading,links);
        document.body.append(flyout);
        const rect = summary.getBoundingClientRect();
        const width = flyout.offsetWidth;
        flyout.style.left = Math.min(rect.right + 9, window.innerWidth - width - 8) + 'px';
        flyout.style.top = Math.max(8, Math.min(rect.top, window.innerHeight - flyout.offsetHeight - 8)) + 'px';
      };
      nav.addEventListener('click', (event) => {
        const summary = event.target.closest('.head-nav-group > summary');
        if (summary && document.getElementById('appShell')?.classList.contains('sb-collapsed') && window.matchMedia('(min-width: 992px)').matches) {
          event.preventDefault();
          const group = summary.parentElement;
          const existing = document.querySelector('.executive-sidebar-flyout');
          const same = existing?.getAttribute('aria-label') === (summary.querySelector('span')?.textContent || 'Navigation');
          if (same) closeFlyout(); else openFlyout(group, summary);
          summary.setAttribute('aria-expanded', String(!same));
          return;
        }
        if (event.target.closest('a')) closeFlyout();
      });
      document.addEventListener('pointerdown', (event) => {
        if (!document.getElementById('appShell')?.classList.contains('executive-sidebar')) return;
        if (!event.target.closest('.executive-sidebar-flyout, .head-nav-group > summary')) closeFlyout();
      }, { signal: this._executiveSidebarAbort?.signal });
      const escapeFlyout = (event) => { if(event.key === 'Escape') closeFlyout(); };
      document.addEventListener('keydown', escapeFlyout, { signal: this._executiveSidebarAbort?.signal });
      window.addEventListener('resize', closeFlyout, { signal: this._executiveSidebarAbort?.signal });
      window.addEventListener('hashchange', closeFlyout, { signal: this._executiveSidebarAbort?.signal });
    });
    document.getElementById('logoutBtn').onclick = () => UI.confirm('Log out', 'Log out of Mulawin FleetOps?', () => this.logout(), { submit: 'Log out' });
    document.getElementById('chgPw').onclick = () => this.passwordScreen();
    document.getElementById('chgEmail').onclick = () => this.emailScreen();
    document.getElementById('mySig').onclick = () => UI.signatureModal();
    document.getElementById('bellReadAll').onclick = async () => { if (!await UI.confirmProceed('Mark notifications as read', 'Mark all notifications as read?', 'Mark all as read')) return; try { this.applyBell(await this.api('Admin.notifications_read', {})); } catch (e) { UI.toast(e.message, 'error'); } };
    document.getElementById('bellAll').onclick = () => bootstrap.Dropdown.getInstance(document.getElementById('bellBtn'))?.hide();
    this.renderBell();
  },

  renderBell() {
    const me = this.me; const badge = document.getElementById('bellBadge'); if (!badge) return;
    badge.textContent = me.unread > 99 ? '99+' : me.unread; badge.classList.toggle('d-none', !me.unread);
    const list = document.getElementById('bellList');
    list.innerHTML = me.notifications.length ? me.notifications.map(n => `
      <div class="bell-item ${n.state === 'Unread' ? 'unread' : ''}" data-nid="${n.id}">
        <div class="bell-item-main" data-nopen="${n.id}" role="button" tabindex="0"><div class="bell-cat">${UI.esc(n.category)}</div><div class="bell-msg">${UI.esc(n.message)}</div>
          <div class="bell-time">${UI.datetime(n.created_at)}</div></div>
        <button type="button" class="btn btn-sm btn-light bell-resolve" data-nresolve="${n.id}" title="Mark as resolved"><i class="bi bi-check2"></i></button>
      </div>`).join('') : '<div class="bell-empty"><i class="bi bi-bell-slash"></i><span>No active notifications.</span></div>';
    list.querySelectorAll('[data-nopen]').forEach(el => el.onclick = async () => {
      const n = me.notifications.find(x => String(x.id) === el.dataset.nopen);
      bootstrap.Dropdown.getInstance(document.getElementById('bellBtn'))?.hide();
      await this.notificationState(n.id, 'Read');
      UI.modal(UI.esc(n.category), `<p class="mb-2">${UI.esc(n.message)}</p><div class="small text-muted">Received ${UI.datetime(n.created_at)}</div>`, null, { size: '',
        extraFooter: `<button type="button" class="btn btn-outline-success btn-sm" data-r>Mark as resolved</button>${n.page && UI.canGo(n.page) ? '<button type="button" class="btn btn-primary btn-sm" data-o>Open</button>' : ''}`,
        onShow: (w) => {
          w.querySelector('[data-r]').onclick = async () => { if (!await UI.confirmProceed('Resolve notification', 'Mark this notification as resolved?', 'Resolve')) return; const r = await this.notificationState(n.id, 'Resolved'); if (r) UI.toast(r.message); bootstrap.Modal.getInstance(w).hide(); };
          w.querySelector('[data-o]')?.addEventListener('click', () => { bootstrap.Modal.getInstance(w).hide(); this.go(n.page); });
        } });
    });
    list.querySelectorAll('[data-nresolve]').forEach(b => b.onclick = async (e) => { e.stopPropagation(); if (!await UI.confirmProceed('Resolve notification', 'Mark this notification as resolved?', 'Resolve')) return; const r = await this.notificationState(b.dataset.nresolve, 'Resolved'); if (r) UI.toast(r.message); });
  },
  /** Applies bell data returned by a notification action (keeps the bell in step at once). */
  applyBell(r) {
    if (!r || !this.me || !r.items) return;
    this.me.notifications = r.items; this.me.unread = r.unread; this.renderBell();
  },
  async notificationState(id, state) {
    try { const r = await this.api('Admin.notifications_read', { id, state }); this.applyBell(r); return r; }
    catch (e) { UI.toast(e.message, 'error'); return null; }
  },
  async refreshBell() {
    if (!this.me) return;
    try { const me = await this.request('index.php?api=me'); this.me.notifications = me.notifications; this.me.unread = me.unread; this.renderBell(); } catch { }
  },

  // ---------------------------------------------------------------- router (#page?x=y)
  /** Landing page per role. The server's me.nav decides what exists; this only picks the first page to show. */
  homePage() {
    const landing = { motorpool: 'maintenance_overview', purchasing: 'purchasing_overview', finance: 'finance_overview', billing: 'billing_overview', audit: 'audit_overview' }[this.me?.user?.role] || 'dashboard';
    const nav = this.me?.nav || [];
    return nav.some(n => n.id === landing) ? landing : (nav[0]?.id || 'dashboard');
  },
  legacyPurchasingPage() {
    return { motorpool: 'mp_parts_requests', purchasing: 'parts_requests' }[this.me?.user?.role] || 'purchase_orders';
  },
  go(page, query = {}) {
    const qs = new URLSearchParams(query).toString();
    const hash = '#' + page + (qs ? '?' + qs : '');
    if (location.hash === hash) this.route(); else location.hash = hash;
  },
  async route() {
    if (!this.me) return;
    let [page, qs] = (location.hash.slice(1) || this.homePage()).split('?');
    // Old notification / bookmark links to the former combined "Parts & Purchasing" page.
    if (page === 'purchasing') { page = this.legacyPurchasingPage(); history.replaceState(null, '', '#' + page + (qs ? '?' + qs : '')); }
    const query = Object.fromEntries(new URLSearchParams(qs || ''));
    // WS_NAV_STATE_V1: on workspace pages, ?open= / ?new= / ?report= are one-shot requests (open this record / form once).
    // They are taken out of the URL and the query before the page renders, so tab, filter and card links built from the
    // query never carry them, and Back / Forward / Reload never reopen a record the user already closed.
    // Operations pages keep their own query handling (e.g. trips?new is a page state there).
    const once = {};
    if (UI.WS_PAGES.has(page)) ['open', 'new', 'report'].forEach(k => { if (query[k] !== undefined) { once[k] = query[k]; delete query[k]; } });
    const canon = '#' + page + (Object.keys(query).length ? '?' + new URLSearchParams(query) : '');
    if (Object.keys(once).length) history.replaceState(null, '', canon);
    const entry = (this.me.routes || this.me.nav).find(n => n.id === page);
    const nav = entry ? {...entry,label:UI.operationsLabel(entry.label)} : null;
    // FLEETOPS_ACCESS_ANALYTICS_V1: render an explicit denial without requesting restricted page data.
    // The server independently enforces Access::canOpen() and returns HTTP 403 for forbidden data.
    if (!nav) {
      this.current = { page: '_access_denied', query: {} };
      document.querySelectorAll('[data-nav]').forEach(a => a.classList.remove('active'));
      document.querySelectorAll('.offcanvas.app-drawer').forEach(o => {
        bootstrap.Offcanvas.getInstance(o)?.hide(); o.remove();
      });
      document.getElementById('appSidebar')?.classList.remove('open');
      document.getElementById('sidebarBackdrop')?.classList.remove('show');
      document.title = 'Access denied · Mulawin FleetOps';
      const crumbs = document.getElementById('crumbs');
      if (crumbs) crumbs.textContent = 'Access denied';
      const content = document.getElementById('pageContent');
      if (content) {
        content.innerHTML = `<section class="panel" role="alert" aria-live="polite" style="max-width:680px;margin:32px auto">
          <div class="panel-body" style="padding:28px">
            <h1 class="page-title"><i class="bi bi-shield-lock me-2" aria-hidden="true"></i>Access denied</h1>
            <p class="text-muted mt-2">Your account does not have permission to open this page. Contact your administrator if you need access.</p>
            <button type="button" class="btn btn-primary mt-2" id="accessReturnHome"><i class="bi bi-house me-1" aria-hidden="true"></i> Return to my home page</button>
          </div></section>`;
        content.querySelector('#accessReturnHome')?.addEventListener('click', () => this.go(this.homePage()));
      }
      return;
    }
    this.current = { page, query };
    const routeState = this.current;
    document.querySelectorAll('[data-nav]').forEach(a => {
      const tripSection = query.new || query.batch ? 'new=1' : 'tab=' + (query.tab || (query.q || query.status || query.from ? 'history' : 'active'));
      const active = a.dataset.nav === page && (a.dataset.dispatchSection
        ? a.dataset.dispatchSection === (page === 'trucks' ? 'trucks' : page === 'crew' ? (query.view === 'attendance' ? 'attendance' : 'personnel') : query.new || query.batch || ['requests','new-request'].includes(query.view) ? 'requests' : query.tab === 'incidents' || query.view === 'incidents' ? 'incidents' : query.tab === 'history' ? 'history' : 'management')
        : a.dataset.opsSection
        ? a.dataset.opsSection === (query.view === 'approvals' ? 'approvals' : query.new || query.batch ? 'planning' : query.tab === 'incidents' ? 'incidents' : 'management')
        : (!a.dataset.tripSection || a.dataset.tripSection === tripSection));
      a.classList.toggle('active', active);
      if (active) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    // Expand the supporting-tools group only when the current route is inside it.
    const secondary = document.querySelector('#sidebarMore [data-nav="' + page + '"]');
    if (secondary) document.getElementById('sidebarMore').open = true;
    // Native details keep manual expand/collapse behavior. On direct links or
    // page changes, reveal the section containing the active Head page.
    const headGroup = [...document.querySelectorAll('.head-nav-group')]
      .find(group => [...group.querySelectorAll('[data-nav]')].some(link => link.dataset.nav === page));
    if (headGroup) headGroup.open = true;
    document.getElementById('appSidebar').classList.remove('open'); document.getElementById('sidebarBackdrop').classList.remove('show');
    if (this._rpmScrollNavigation) requestAnimationFrame(this._rpmScrollNavigation);
    const wsItem = UI.wsNavItem(page);   // WS_SHELL_V1: workspace group + short label, same as the menu
    const crumbSec = wsItem ? wsItem.group : nav.section, crumbLabel = wsItem ? wsItem.label : nav.label;
    document.getElementById('crumbs').innerHTML = `${crumbSec ? `<span class="crumb-sec">${UI.esc(crumbSec)}</span><i class="bi bi-chevron-right"></i>` : ''}<span class="crumb-page">${UI.esc(crumbLabel)}</span>`;
    document.title = crumbLabel + ' · Mulawin FleetOps';
    const el = document.getElementById('pageContent');
    // STAGE_B_WORKSPACE_UI: compact table styling only on Maintenance and Accounting pages (Operations pages unchanged).
    el.classList.toggle('ws-scope', UI.WS_PAGES.has(page) || (page === 'admin' && UI.role('audit')));
    el.classList.toggle('ref-scope', UI.REF_PAGES.has(page) && document.body.classList.contains('ref-body'));
    const def = this.pages[page];
    if (!def) {
      el.innerHTML = `<div class="page-head"><div><h1 class="page-title">${UI.esc(nav.label)}</h1></div></div>
        <div class="module-down"><i class="bi bi-exclamation-triangle"></i><div>This page's screen file (assets/js/modules/${UI.esc(nav.js)}.js) did not load. The other pages still work. Reload the page or check the file.</div></div>`;
      return;
    }
    el.innerHTML = `<div class="page-head"><div class="page-head-text"><h1 class="page-title" id="pageTitle">${UI.esc(UI.operationsLabel(def.title || nav.label))}</h1>${def.subtitle ? `<p class="page-subtitle" id="pageSubtitle">${UI.esc(def.subtitle)}</p>` : ''}</div>
        <div class="page-actions no-print" id="pageTools"></div>
        <button type="button" id="rpmBackToTop" class="rpm-back-to-top no-print" aria-label="Back to top" title="Back to top" hidden><span>Back to top</span></button></div>
        <div id="pageBody" class="page-body"><div class="loading-block"><span class="spinner-border spinner-border-sm"></span> Loading…</div></div>`;
    // A drawer or dialog belongs to the list it was opened from: close it when the user moves to another page, or
    // (workspace pages) to another tab / filter of the same page. A plain reload of the same view keeps it.
    const moved = this._lastPage !== page || (UI.WS_PAGES.has(page) && canon !== this._lastCanon);
    if (moved) this.navSeq = (this.navSeq || 0) + 1;
    if (moved && UI.WS_PAGES.has(page)) document.querySelectorAll('.modal.show').forEach(m => bootstrap.Modal.getInstance(m)?.hide());
    if (moved) {
      document.querySelectorAll('.offcanvas.app-drawer').forEach(o => { bootstrap.Offcanvas.getInstance(o)?.hide(); o.remove(); });
      document.querySelectorAll('.offcanvas-backdrop').forEach(x => x.remove());
      document.body.style.removeProperty('overflow'); document.body.style.removeProperty('padding-right');
    }
    this._lastCanon = canon;
    if (this._lastPage !== page) {
      window.scrollTo(0, 0);
      // A details drawer belongs to its page: close it when the user moves to another page.
      document.querySelectorAll('.offcanvas.app-drawer').forEach(o => { bootstrap.Offcanvas.getInstance(o)?.hide(); o.remove(); });
      document.querySelectorAll('.offcanvas-backdrop').forEach(x => x.remove());
      document.body.style.removeProperty('overflow'); document.body.style.removeProperty('padding-right');
    }
    this._lastPage = page;
    const body = document.getElementById('pageBody');
    const ctx = { page, query, once, el: body, tools: document.getElementById('pageTools'), me: this.me, data: null,
      reload: () => this.route(), go: (p, q) => this.go(p, q), setQuery: (q) => this.go(page, { ...query, ...q }),
      setTitle: (t, s) => { t=UI.operationsLabel(t);if(UI.role('operations_head')||UI.isDispatcher()){document.title=t+' \u00b7 Mulawin FleetOps';const crumbs=document.getElementById('crumbs');if(crumbs)crumbs.textContent=t;} document.getElementById('pageTitle').textContent = t; const st = document.getElementById('pageSubtitle'); if (st) st.textContent = s || ''; } };
    try {
      ctx.data = def.noData ? {} : await this.data(page, query);
      if (this.current !== routeState) return;
      body.innerHTML = '';
      await def.render(ctx);
      UI.activate(body);
      if (ctx.data && ctx.data._errors && Object.keys(ctx.data._errors).length) {
        const warnings = [...new Set(Object.values(ctx.data._errors).map(String))];
        // Avoid repeating generic partial-data boilerplate on views which
        // already render a specific unavailable-data state when needed.
        const tripSection = query.tab === 'incidents' ? 'incidents' : query.tab === 'history' ? 'history' : 'active';
        const hasOwnState = page === 'crew' || (page === 'trips'
          && Array.isArray(ctx.data[tripSection]));
        const shown = hasOwnState
          ? warnings.filter(message => !/^This part is temporarily unavailable\. The rest of the page still works\.$/i.test(message.trim()))
          : warnings;
        if (shown.length) body.insertAdjacentHTML('afterbegin', `<div class="alert alert-warning">${shown.map(UI.esc).join('<br>')}</div>`);
      }
    } catch (e) {
      console.error(e);
      if (this.current !== routeState) return;
      body.innerHTML = UI.state(e.status === 403 ? 'denied' : 'error', { title: e.message, message: 'Only this page is affected. You can keep using the other pages.', actionHtml: e.status === 403 ? '' : '<button type="button" class="btn btn-outline-secondary mt-2" data-page-retry>Try again</button>' });
      body.querySelector('[data-page-retry]')?.addEventListener('click', () => ctx.reload());
    }
  },
};

// =====================================================================
//  UI HELPERS
// =====================================================================
/** Pages of the Maintenance (Motorpool, Purchasing) and Accounting (Finance, Billing, Audit) workspaces. */
UI.WS_PAGES = new Set(['maintenance_overview', 'maintenance', 'maint_history', 'mp_parts_requests', 'mechanics', 'mechanic_attendance', 'maintenance_reports',
  'purchasing_overview', 'parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_reports',
  'admin_overview', 'admin_approvals', 'admin_operations', 'admin_maintenance', 'admin_purchasing', 'admin_finance', 'admin_reports', 'admin_analytics',
  'finance_overview', 'finance', 'finance_budgets', 'funds', 'billing_overview', 'billing', 'billing_collections', 'billing_receivables', 'audit_overview', 'audit_logs', 'audit_history', 'audit_reports']);

/** REF_FIDELITY_V1 — Motorpool and Purchasing pages drawn like docs/ui-reference/maintenance_reference.html. */
UI.REF_PAGES = new Set(['maintenance_overview', 'maintenance', 'maint_history', 'mp_parts_requests', 'mechanics', 'mechanic_attendance', 'maintenance_reports',
  'purchasing_overview', 'parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_reports',
  // HEAD_MANAGEMENT_V1: the Admin workspace pages, plus Users & Roles ('admin') and Audit Trail ('audit_logs') for the Admin
  'admin_overview', 'admin_approvals', 'admin_operations', 'admin_maintenance', 'admin_purchasing', 'admin_finance', 'admin_reports', 'admin_analytics', 'admin', 'audit_logs']);
UI.refPage = () => UI.REF_PAGES.has(App.current?.page) && document.body.classList.contains('ref-body');

/* WS_SHELL_V1 — workspace menus of the in-scope roles. Presentation only: a page appears only when the server's
   me.nav (Access::navFor) lists it, so no unauthorized page can be shown. Other permitted pages are grouped below
   under short headings (Fleet & trips, Maintenance, Purchasing, Accounting, Records). Other roles keep the old menu. */
UI.WS_MENUS = {
  motorpool: { dept: 'Motorpool', section: 'Maintenance', exclusive: true, items: [['maintenance_overview', 'Overview', 'bi-speedometer2'], ['maintenance', 'Repair Management', 'bi-wrench-adjustable'],
    ['mp_parts_requests', 'Parts Requests', 'bi-box-seam'], ['mechanics', 'Mechanic Masterlist', 'bi-person-gear'],
    ['mechanic_attendance', 'Mechanic Attendance', 'bi-person-check'], ['maint_history', 'Maintenance History', 'bi-journal-check'], ['maintenance_reports', 'Reports', 'bi-graph-up']] },
  purchasing: { dept: 'Purchasing', section: 'Purchasing', exclusive: true, items: [['purchasing_overview', 'Overview', 'bi-speedometer2'], ['parts_requests', 'Parts Requests', 'bi-box-arrow-up'],
    ['purchase_orders', 'Purchase Orders', 'bi-cart-check'], ['inventory', 'Inventory', 'bi-boxes'], ['stock_movements', 'Stock Movements', 'bi-arrow-left-right'],
    ['purchasing_reports', 'Reports', 'bi-graph-up']] },
  finance: { dept: 'Finance', items: [['finance_overview', 'Overview', 'bi-speedometer2'], ['purchase_orders', 'Approvals', 'bi-check2-square'],
    ['funds', 'Trip Funds', 'bi-cash-coin'], ['finance', 'Payables & Payments', 'bi-wallet2'], ['finance_budgets', 'Budgets & Expenses', 'bi-piggy-bank']] },
  billing: { dept: 'Billing', items: [['billing_overview', 'Overview', 'bi-speedometer2'], ['billing', 'Billing & Invoices', 'bi-receipt'],
    ['billing_collections', 'Collections', 'bi-cash-stack'], ['billing_receivables', 'Receivables', 'bi-hourglass-split'], ['reports', 'Billing Reports', 'bi-bar-chart-line']] },
  // HEAD_MANAGEMENT_V1: the Admin's own sidebar (exclusive: no extra groups). Other pages the Admin may open stay reachable
  // from the Records shortcuts of each monitoring page; permissions are unchanged (me.nav / Access::canOpen).
  admin: { dept: 'Head Management', section: 'Management', exclusive: true, items: [['admin_overview', 'Overview', 'bi-speedometer2'], ['admin_approvals', 'Approvals', 'bi-check2-square'],
    ['admin_operations', 'Operations', 'bi-signpost-split'], ['admin_maintenance', 'Maintenance', 'bi-wrench-adjustable'], ['admin_purchasing', 'Purchasing', 'bi-cart-check'],
    ['admin_finance', 'Financial Oversight', 'bi-cash-coin'], ['admin_reports', 'Executive Reports', 'bi-clipboard-check'], ['admin_analytics', 'Analytics', 'bi-graph-up'],
    ['admin', 'Users & Roles', 'bi-people'], ['audit_logs', 'Audit Trail', 'bi-journal-text']] },
  audit: { dept: 'Audit', items: [['audit_overview', 'Overview', 'bi-speedometer2'], ['audit_logs', 'Audit Logs', 'bi-journal-text'],
    ['audit_history', 'Transaction History', 'bi-clock-history'], ['audit_reports', 'Audit Reports', 'bi-clipboard-data']] },
};
UI.WS_GROUPS = [
  ['Fleet & trips', ['trips', 'trucks', 'crew', 'clients', 'routes']],
  ['Maintenance', ['maintenance_overview', 'maintenance', 'mp_parts_requests', 'mechanics', 'mechanic_attendance', 'maint_history', 'maintenance_reports']],
  ['Purchasing', ['parts_requests', 'purchase_orders', 'inventory', 'stock_movements', 'purchasing_reports']],
  ['Accounting', ['funds', 'finance', 'finance_budgets', 'billing', 'billing_collections', 'billing_receivables']],
];
/** Grouped menu for the role, or null when the role keeps the standard menu. */
UI.wsNav = (role) => {
  if (role === 'admin' || role === 'operations_head') return null; // Keep the approved executive and Operations sidebars.
  const menu = UI.WS_MENUS[role]; if (!menu || !App.me) return null;
  const allowed = (App.me.nav || []).filter(n => n.id !== 'notifications');
  const byId = Object.fromEntries(allowed.map(n => [n.id, n]));
  const used = new Set();
  const main = menu.items.filter(([id]) => byId[id]).map(([id, label, icon]) => (used.add(id), { id, label, icon }));
  const groups = [{ title: menu.section || menu.dept, main: true, items: main }];
  // REF_FIDELITY_V1: Motorpool and Purchasing show ONLY their approved workspace items (no Fleet & trips / Records groups).
  // Presentation only: other permitted pages (e.g. a truck record linked from a repair) stay reachable through
  // in-page links, because UI.canGo and the server still use me.nav / Access::canOpen.
  if (menu.exclusive) return { dept: menu.dept, exclusive: true, groups };
  const rest = allowed.filter(n => !used.has(n.id));
  UI.WS_GROUPS.forEach(([title, ids]) => {
    const items = rest.filter(n => ids.includes(n.id) && !used.has(n.id));
    items.forEach(n => used.add(n.id));
    if (items.length) groups.push({ title: title === menu.dept ? title + ' records' : title, items: items.map(n => ({ id: n.id, label: n.label, icon: n.icon })) });
  });
  const records = rest.filter(n => !used.has(n.id));
  if (records.length) groups.push({ title: 'Records', items: records.map(n => ({ id: n.id, label: n.label, icon: n.icon })) });
  return { dept: menu.dept, groups };
};
/** {group, label} of a page in the current workspace menu (for the breadcrumb); null outside workspace menus. */
UI.wsNavItem = (page) => {
  const ws = App.me && UI.wsNav(App.me.user.role); if (!ws) return null;
  for (const g of ws.groups) { const it = g.items.find(n => n.id === page); if (it) return { group: g.main ? ws.dept : g.title, label: it.label }; }
  return null;
};
/**
 * Quick actions row for workspace overviews. items: [{label, icon, go: 'page?x=y'} | {label, icon, act: 'key', primary}]
 * A "go" item is shown only when the user may open the page; "act" items are bound by the page (data-qa="key").
 */
UI.quickBar = (items) => {
  const shown = (items || []).filter(it => it && (it.act || UI.canGo(it.go)));
  if (!shown.length) return '';
  return `<div class="mo-quickbar" role="group" aria-label="Quick actions"><span class="mo-quickbar-title">Quick actions</span><div class="mo-quickbar-row">${shown.map(it =>
    `<button type="button" class="btn btn-sm ${it.primary ? 'btn-primary' : 'btn-outline-secondary'}" ${it.act ? `data-qa="${UI.esc(it.act)}"` : `data-go="${UI.esc(it.go)}"`}><i class="bi ${UI.esc(it.icon)}" aria-hidden="true"></i> ${UI.esc(it.label)}</button>`).join('')}</div></div>`;
};

/* UI_REFERENCE_V1 — building blocks of the approved Motorpool / Purchasing wireframe (docs/ui-reference).
   Presentation only: every value and link comes from the page's authorized data; links show only when UI.canGo allows. */
/** Status tabs that change the URL (?key=value). Other filters are kept; a one-time ?open= never travels with them. */
UI.statusTabs = (page, q, key, tabs, active) => `<div class="line-tabs ws-status-tabs no-print" role="tablist">${tabs.map(([v, label, n]) => {
  const next = { ...q, [key]: v }; delete next.open;
  const qs = new URLSearchParams(Object.fromEntries(Object.entries(next).filter(([, x]) => x !== '' && x != null))).toString();
  const on = String(v) === String(active);
  return `<button type="button" role="tab" aria-selected="${on}" class="tab ${on ? 'active' : ''}" data-go="${UI.esc(page + (qs ? '?' + qs : ''))}">${UI.esc(label)}${n === undefined || n === null ? '' : ` <span class="tab-count">${UI.num(n)}</span>`}</button>`; }).join('')}</div>`;
/** Overview / report panel: title, optional count, optional lightbulb suggestion (tipHtml), optional "See all" link. */
UI.wsPanel = (title, icon, body, o = {}) => {
  const go = o.go && UI.canGo(o.go);
  return `<section class="panel ws-panel${o.cls ? ' ' + o.cls : ''}"><div class="panel-head"><h2 class="panel-title"><i class="bi ${UI.esc(icon)}" aria-hidden="true"></i>${UI.esc(title)}${o.count === undefined ? '' : ` <span class="text-muted fw-normal">${UI.num(o.count)}</span>`}</h2>
    <div class="panel-tools">${o.tipHtml ? `<button type="button" class="ws-tip-btn" data-ws-tip aria-expanded="false" aria-haspopup="dialog" aria-label="Suggestion: ${UI.esc(title)}"><i class="bi bi-lightbulb" aria-hidden="true"></i> Suggestion</button>` : ''}${go ? `<button type="button" class="ws-seeall" data-go="${UI.esc(o.go)}">${UI.esc(o.goLabel || 'See all')} <i class="bi bi-arrow-right" aria-hidden="true"></i></button>` : ''}</div></div>
    ${o.tipHtml ? `<template class="ws-tip-tpl">${o.tipHtml}</template>` : ''}
    <div class="panel-body flush">${body}</div>${o.foot ? `<div class="ws-panel-foot">${o.foot}</div>` : ''}</section>`;
};
/* POPOVER_SUGGESTIONS_V1 — the lightbulb opens ONE floating popover attached to <body> (position: fixed), so no card
   resizes and no overflow container clips it. Hover opens it on hover-capable devices; click / tap / Enter / Space
   pins it. It closes on Escape, outside click, page navigation, or when another suggestion opens. No backdrop. */
UI.tipPop = { el: null, btn: null, pinned: false, timer: 0, rect: null };
UI.closeTip = (focusBack = false) => {
  const s = UI.tipPop; clearTimeout(s.timer);
  if (s.el) s.el.remove();
  if (s.btn) { s.btn.setAttribute('aria-expanded', 'false'); s.btn.classList.remove('active'); if (focusBack) s.btn.focus(); }
  s.el = null; s.btn = null; s.pinned = false; s.rect = null;
};
UI.placeTip = () => {
  const { el, btn, rect } = UI.tipPop; if (!el || !btn) return;
  if (!document.body.contains(btn)) return UI.closeTip();
  const r = rect ? rect() : btn.getBoundingClientRect(), m = 8, vw = document.documentElement.clientWidth, vh = window.innerHeight;
  const w = el.offsetWidth, h = el.offsetHeight;
  // a point anchor (chart) centres the popover on the point; a button anchor aligns it to the button's right edge
  let left = Math.min(Math.max(m, rect ? (r.left + r.right) / 2 - w / 2 : r.right - w), vw - w - m);
  let top = r.bottom + 6;
  if (top + h > vh - m && r.top - h - 6 > m) top = r.top - h - 6;      // flip above when there is no room below
  top = Math.max(m, Math.min(top, vh - h - m));
  el.style.left = left + 'px'; el.style.top = top + 'px';
};
UI.openTip = (btn, pinned) => {
  const s = UI.tipPop;
  if (s.btn === btn) { if (pinned) s.pinned = true; return; }
  UI.closeTip();
  const tpl = btn.closest('.ws-panel')?.querySelector('.ws-tip-tpl'); if (!tpl) return;
  const el = document.createElement('div');
  el.className = 'ws-popover'; el.id = 'wsTipPop'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', btn.getAttribute('aria-label') || 'Suggestion');
  el.innerHTML = `<div class="ws-popover-head"><i class="bi bi-lightbulb" aria-hidden="true"></i> Suggested action<button type="button" class="ws-popover-x" aria-label="Close">&times;</button></div><div class="ws-popover-body">${tpl.innerHTML}</div>`;
  document.body.append(el);
  UI.bindGo(el);
  el.querySelectorAll('[data-go]').forEach(x => x.addEventListener('click', () => UI.closeTip()));
  el.querySelector('.ws-popover-x').onclick = () => UI.closeTip(true);
  el.addEventListener('mouseenter', () => clearTimeout(s.timer));
  el.addEventListener('mouseleave', () => { if (!s.pinned) s.timer = setTimeout(() => UI.closeTip(), 250); });
  Object.assign(s, { el, btn, pinned: !!pinned });
  btn.setAttribute('aria-expanded', 'true'); btn.setAttribute('aria-controls', 'wsTipPop'); btn.classList.add('active');
  UI.placeTip();
};
UI.bindTips = (root) => root.querySelectorAll('[data-ws-tip]').forEach(b => {
  const hover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  b.addEventListener('click', (e) => { e.stopPropagation(); const s = UI.tipPop; if (s.btn === b && s.pinned) UI.closeTip(); else UI.openTip(b, true); });
  if (hover) {
    b.addEventListener('mouseenter', () => { clearTimeout(UI.tipPop.timer); if (UI.tipPop.btn !== b && !UI.tipPop.pinned) UI.openTip(b, false); });
    b.addEventListener('mouseleave', () => { const s = UI.tipPop; if (s.btn === b && !s.pinned) s.timer = setTimeout(() => UI.closeTip(), 250); });
  }
});
/** MOTORPOOL_REPORTS_V2 — the same single floating popover, anchored to a point (e.g. a chart point) of an owner element. */
UI.openPointPop = (owner, rectFn, label, bodyHtml) => {
  UI.closeTip();
  const s = UI.tipPop, el = document.createElement('div');
  el.className = 'ws-popover ws-popover-wide'; el.id = 'wsTipPop'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', label);
  el.innerHTML = `<button type="button" class="ws-popover-x ws-popover-x-abs" aria-label="Close">&times;</button>${bodyHtml}`;
  document.body.append(el);
  UI.bindGo(el);
  el.querySelectorAll('[data-go]').forEach(x => x.addEventListener('click', () => UI.closeTip()));
  el.querySelector('.ws-popover-x').onclick = () => UI.closeTip(true);
  Object.assign(s, { el, btn: owner, pinned: true, rect: rectFn });
  UI.placeTip();
  return el;
};
document.addEventListener('click', (e) => { const s = UI.tipPop; if (s.el && !s.el.contains(e.target) && !e.target.closest('[data-ws-tip]') && !(s.rect && s.btn && s.btn.contains(e.target))) UI.closeTip(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && UI.tipPop.el) { e.stopPropagation(); UI.closeTip(true); } }, true);
window.addEventListener('hashchange', () => UI.closeTip());
window.addEventListener('resize', () => UI.placeTip());
window.addEventListener('scroll', () => UI.placeTip(), true);
/** Short list entry for overview panels: title, sub-line, status chip on the right; the whole row opens `go`. */
UI.wsEntry = (go, title, sub, side) => { const ok = go && UI.canGo(go);
  return `<li class="mo-item${ok ? ' clickable' : ''}"${ok ? ` data-go="${UI.esc(go)}" role="link" tabindex="0"` : ''}><div class="mo-item-main"><div class="mo-item-top">${title}</div>${sub ? `<div class="mo-item-issue">${sub}</div>` : ''}</div><div class="mo-item-side">${side || ''}</div></li>`; };
UI.wsList = (items, empty) => items.length ? `<ul class="mo-list mo-scroll">${items.join('')}</ul>` : `<div class="mo-empty"><i class="bi bi-check2-circle" aria-hidden="true"></i> ${UI.esc(empty)}</div>`;
/** "2026-09" -> "Sep 2026". */
UI.ym = (ym) => { const [y, m] = String(ym).split('-'); return new Date(+y, +m - 1, 1).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' }); };
UI.compactMoney = (v) => { const n = +v || 0, a = Math.abs(n); return '₱' + (a >= 1e6 ? (n / 1e6).toFixed(1) + 'M' : a >= 1e3 ? (n / 1e3).toFixed(1) + 'k' : UI.num(n)); };
/** Grouped vertical bars (CSS only) from recorded values. series: [{label, data: [..]}]; fmt formats one value. */
UI.wsBars = (labels, series, fmt = (v) => UI.num(v), empty = 'No records in this period.') => {
  const max = Math.max(0, ...series.flatMap(s => s.data.map(x => +x || 0)));
  if (!labels.length || !max) return `<div class="mo-empty"><i class="bi bi-bar-chart" aria-hidden="true"></i> ${UI.esc(empty)}</div>`;
  return `<div class="ws-chart"><div class="ws-chart-legend">${series.map((s, i) => `<span><i class="sw-${i}" aria-hidden="true"></i>${UI.esc(s.label)}</span>`).join('')}</div>
    <div class="ws-bars">${labels.map((l, j) => `<div class="ws-barset">${series.map((s, i) => { const v = +s.data[j] || 0;
      return `<div class="ws-bar-col"><span class="ws-bar-v">${UI.esc(fmt(v))}</span><div class="ws-bar sw-${i}" style="height:${Math.round(v / max * 100)}%" role="img" aria-label="${UI.esc(s.label + ', ' + l + ': ' + fmt(v))}" title="${UI.esc(s.label + ' · ' + l + ': ' + fmt(v))}"></div></div>`; }).join('')}
      <span class="ws-bar-label">${UI.esc(l)}</span></div>`).join('')}</div></div>`;
};
/** Ranking bars. rows: [{label, sub, value, display, go}] (value drives the bar; display is the shown figure). */
UI.wsRank = (rows, empty) => {
  if (!rows.length) return `<div class="mo-empty"><i class="bi bi-info-circle" aria-hidden="true"></i> ${UI.esc(empty)}</div>`;
  const max = Math.max(...rows.map(r => +r.value || 0)) || 1;
  return `<div class="ws-rank">${rows.map(r => { const ok = r.go && UI.canGo(r.go);
    return `<div class="ws-rank-row${ok ? ' clickable' : ''}"${ok ? ` data-go="${UI.esc(r.go)}" role="link" tabindex="0"` : ''}><div class="ws-rank-name"><b>${UI.esc(r.label)}</b>${r.sub ? `<span>${UI.esc(r.sub)}</span>` : ''}</div>
      <div class="ws-rank-track" aria-hidden="true"><span style="width:${Math.round((+r.value || 0) / max * 100)}%"></span></div><div class="ws-rank-val">${UI.esc(r.display ?? UI.num(r.value))}</div></div>`; }).join('')}</div>`;
};
/** Report period selector (presets + custom dates), same URL keys as before (?from=&to=). */
UI.wsPeriod = (page, per) => {
  const iso = (x) => { const y = new Date(x); y.setMinutes(y.getMinutes() - y.getTimezoneOffset()); return y.toISOString().slice(0, 10); };
  const now = new Date(), today = iso(now);
  const presets = [['This month', iso(new Date(now.getFullYear(), now.getMonth(), 1))], ['Last 3 months', iso(new Date(now.getFullYear(), now.getMonth() - 2, 1))],
    ['Last 6 months', iso(new Date(now.getFullYear(), now.getMonth() - 5, 1))], ['This year', iso(new Date(now.getFullYear(), 0, 1))]];
  const hit = presets.find(([, f]) => per.from === f && per.to === today);
  return `<div class="ws-filters ws-period no-print" role="group" aria-label="Reporting period"><label class="small text-muted" for="wsPeriodSel">Reporting period</label>
    <select id="wsPeriodSel" class="form-select form-select-sm" data-ws-period>${presets.map(([l, f]) => `<option value="${UI.esc(page + '?' + new URLSearchParams({ from: f, to: today }))}" ${hit && hit[1] === f ? 'selected' : ''}>${l}</option>`).join('')}${hit ? '' : '<option selected value="">Custom dates</option>'}</select>
    <input type="date" class="form-control form-control-sm" data-gf="from" value="${UI.esc(per.from)}" aria-label="Start date" title="Start date">
    <input type="date" class="form-control form-control-sm" data-gf="to" value="${UI.esc(per.to)}" aria-label="End date" title="End date">
    <span class="small text-muted">${UI.date(per.from)} – ${UI.date(per.to)}</span></div>`;
};
UI.bindPeriod = (root) => root.querySelector('[data-ws-period]')?.addEventListener('change', (e) => { if (e.target.value) UI.goTo(e.target.value); });
UI.operationsLabel = label => {
  const role=App.me?.user?.role;if(!['operations_head','car_carrier_dispatcher','container_wingvan_dispatcher'].includes(role))return label;
  const names={'Trip Management':'Trips','Dispatcher Monitoring':'Dispatch Monitoring','Trip Fund Approvals':'Fund Approvals','Truck Masterlist':'Trucks','Truck Master List':'Trucks','Driver & Helper Masterlist':'Drivers & Helpers','Client Masterlist':'Clients','Incidents & Maintenance':'Incidents & Repairs','Plate Number':'Plate No.','Plate number':'Plate No.','Personnel Name':'Name','Personnel Role':'Role','Permanent Truck':'Fixed Truck','Current Availability':'Availability','Operational Status':'Status','View Details':'View','Action Required Summary':'Needs Action','Workflow Progress Timeline':'Progress','Eligibility & Readiness Panel':'Readiness'};
  if(label==='Trip Funds')return role==='operations_head'?'Fund Approvals':'Funds & Fuel';
  if(role==='operations_head'){
    const headNames={'Truck & Crew':'Truck & Personnel','Trip Funds & Fuel':'Funds & Fuel','Incident & Maintenance Coordination':'Incident & Repairs','Incidents & Corrective Maintenance':'Incidents & Repairs','Maintenance Record':'Repair Record','Maintenance Status':'Repair Status','Helper Replacement Reason':'Replacement Reason','Truck type':'Truck Type','Truck & Crew Assignment':'Truck & Personnel','Regular driver':'Fixed Driver','Regular helper':'Regular Helper','Plate / unit':'Plate No. / Unit','Add truck':'Add Truck','Add client':'Add Client','Add Client Profile':'Add Client','Client profile':'Client Profile','Truck / unit number':'Unit No.','MV file no.':'MV File No.','Chassis no.':'Chassis No.','Engine no.':'Engine No.','OR no.':'OR No.','CR no.':'CR No.','New truck':'Add Truck','Vehicle documents':'Truck Documents','Trip history':'Trip History','Status history':'Status History','Maintenance history':'Repair History','Recorded Activity':'Activity','Request Information':'Request','Trip Information':'Trip','Expense Breakdown':'Expenses','Recorded Progress':'Progress','Assignment & Activity History':'Activity','Trip Summary':'Overview','Trip Overview':'Overview','Planning Dispatcher Shift':'Dispatcher Shift','Recorded Approved Rate':'Approved Rate','Delivery Details / Instructions':'Delivery Instructions','View Related Trip':'View Trip','View Fund Request':'View Fund','Approve Request':'Approve','Close Details':'Close','Related Trip':'Trip','Requested By':'Requested By','Request Date':'Requested','Requested Amount':'Amount','Personnel Role':'Role','No authorized availability records match.':'No matching records found.'};
    if(Object.hasOwn(headNames,label))return headNames[label];
  }
  return names[label]||label;
};
UI.esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
UI.money = (v) => (v === null || v === undefined || v === '') ? '—' : '₱' + Number(v).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
UI.num = (v, d = 0) => (v === null || v === undefined || v === '') ? '—' : Number(v).toLocaleString('en-PH', { maximumFractionDigits: d });
UI.date = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' }) : '—';
UI.datetime = (v) => v ? new Date(String(v).replace(' ', 'T')).toLocaleString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
/** Current date and time in the Philippines as YYYY-MM-DDTHH:MM (for datetime-local min/max), whatever the PC timezone. */
UI.nowManila = (addMinutes = 0) => {
  const d = new Date(Date.now() + addMinutes * 60000);
  const p = Object.fromEntries(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).formatToParts(d).map(x => [x.type, x.value]));
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`;
};
/** Random id for one form (duplicate-submit protection). Works on http:// too, where crypto.randomUUID is missing. */
UI.uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
  : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = crypto.getRandomValues(new Uint8Array(1))[0] & 15; return (c === 'x' ? r : (r & 3) | 8).toString(16); });
UI.today = () => UI.nowManila().slice(0, 10);
UI.label = (role) => (App.me && App.me.roles[role]) || role;
UI.role = (...roles) => App.me && roles.includes(App.me.user.role);
UI.isDispatcher = () => UI.role('car_carrier_dispatcher', 'container_wingvan_dispatcher');

/** Status colors: the same meaning everywhere (green = good, blue = in progress, amber = waiting, red = problem). */
UI.badgeColor = (s) => {
  const v = String(s || '');
  if (/^(Available|Completed|Paid|Approved|Released|Liquidated|Received|Active|OK|Resolved|Repaired - Cleared to Continue|Returned to service|Passed|Read)$/.test(v)) return 'green';
  if (/^(Assigned|Pre-Assigned|Ready for Dispatch|Dispatched|In Transit|On Trip|Ready for Release|Ongoing|Invoiced|For Billing|Partially Paid|Partially Released|Partially Received|Stock Out|Pending Payment|Under Inspection|Under Repair|Normal)$/.test(v)) return 'blue';
  if (/^(Pending Approval|Pending Review|Pending Rate|Awaiting Resources|Awaiting verification|Requested|Reported|Not Liquidated|Minor|Adjustment|Incomplete|Unpaid|Closed|High|Unread)$/.test(v)) return 'amber';
  if (/(Under Maintenance|On Hold|For Reschedule|Rejected|Cancelled|Not Available|Unavailable|Major|Overdue|Inactive|Delayed|Insufficient|Critical|Failed)/.test(v)) return 'red';
  return '';
};
UI.badge = (s) => s === null || s === undefined || s === '' ? '—' : `<span class="badge-s ${UI.badgeColor(s)}">${UI.esc(s)}</span>`;
UI.days = (d) => {
  if (d === null || d === undefined || d === '') return '—';
  const n = +d; const c = n < 0 ? 'red' : n <= 30 ? 'amber' : 'green';
  return `<span class="badge-s ${c}">${n < 0 ? 'expired ' + Math.abs(n) + 'd ago' : n === 0 ? 'today' : n + ' days left'}</span>`;
};
UI.fmt = (type, v, row) => {
  if (typeof type === 'function') return type(v, row);
  switch (type) {
    case 'money': return UI.money(v); case 'date': return UI.date(v); case 'datetime': return UI.datetime(v);
    case 'badge': return UI.badge(v); case 'days': return UI.days(v); case 'num': return UI.num(v, 2);
    case 'yes': return +v ? 'Yes' : 'No';
    default: return v === null || v === undefined || v === '' ? '—' : UI.esc(v);
  }
};

/** Toast message (bottom right). type: '' (success), 'error', 'warn', 'info'. */
UI.toast = (msg, type = '') => {
  if (!msg) return;
  const icon = { error: 'bi-x-circle-fill', warn: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' }[type] || 'bi-check-circle-fill';
  const el = document.createElement('div'); el.className = 'app-toast ' + type; el.setAttribute('role', type === 'error' ? 'alert' : 'status');
  el.innerHTML = `<i class="bi ${icon}"></i><span></span><button type="button" class="toast-x" aria-label="Close">&times;</button>`;
  el.querySelector('span').textContent = msg;
  el.querySelector('.toast-x').onclick = () => el.remove();
  document.getElementById('toastZone').append(el); setTimeout(() => el.remove(), type ? 9000 : 5000);
};
/** Shows the result of a backend action (message + warning). */
UI.result = (r) => { UI.toast(r.message || 'Saved.'); if (r.warning) UI.toast(r.warning, 'warn'); };

/** Company logo (redrawn from the reference forms). */
UI.logoTag = (cls = '') => `<img src="assets/img/logo.svg" alt="RPM" class="${cls}">`;

/** A button in the page header (top right). */
UI.tool = (ctx, label, fn, icon = '', cls = 'outline-secondary') => {
  const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-' + cls;
  b.innerHTML = (icon ? `<i class="bi ${icon}"></i> ` : '') + UI.esc(UI.operationsLabel(label)); b.onclick = fn; ctx.tools.append(b); return b;
};

/**
 * Metric strip: one bordered panel divided into segments (label, value, note).
 * items: [{label, value, note, color: green|blue|amber|red, go: 'page?x=y', format: 'money'}]
 */
// Presentation-only icon mapping. Counts, data sources, and navigation are unchanged.
UI.metricVisual = (card) => {
  const label = String(card.label || '').toLowerCase();
  const rules = [
    [/incident|delay|overdue|problem|rejected|critical|failed|unavailable/, 'bi-exclamation-triangle', 'red'],
    [/complete|finished|resolved|approved|collected|paid|success/, 'bi-check-circle', 'purple'],
    [/schedule|pending|awaiting|prepar|review|due/, 'bi-calendar-check', 'blue'],
    [/truck|vehicle|fleet|trip|dispatch|route|delivery/, 'bi-truck', 'green'],
    [/fuel|diesel|liter|consumption/, 'bi-fuel-pump', 'blue'],
    [/driver|helper|personnel|mechanic|employee|staff|user/, 'bi-people', 'purple'],
    [/client|customer|contract/, 'bi-buildings', 'blue'],
    [/maintenance|repair|job order/, 'bi-tools', 'amber'],
    [/inventory|stock|parts|purchase|warehouse/, 'bi-box-seam', 'amber'],
    [/billing|invoice|receipt|collection/, 'bi-receipt', 'purple'],
    [/fund|expense|cost|budget|amount|payment|finance|revenue|rate/, 'bi-wallet2', 'green'],
    [/document|report|record/, 'bi-file-earmark-text', 'blue']
  ];
  const matched = rules.find(([pattern]) => pattern.test(label));
  const icon = /^bi-[a-z0-9-]+$/.test(String(card.icon || '')) ? card.icon : (matched?.[1] || 'bi-bar-chart-line');
  const color = ['green','blue','amber','red','purple'].includes(card.color) ? card.color : (matched?.[2] || 'green');
  return {icon,color};
};
UI.statusStrip = (items) => `<div class="metric-strip metric-strip-icons">${(items || []).map(c => {
  const go = c.go && UI.canGo(c.go);
  const visual = UI.metricVisual(c);
  return `<div class="metric ${UI.esc(c.color || '')} ${go ? 'clickable' : ''} metric-icon-card" ${go ? `data-go="${UI.esc(c.go)}" role="link" tabindex="0"` : ''}>
    <span class="metric-icon metric-icon-${visual.color}" aria-hidden="true"><i class="bi ${visual.icon}"></i></span>
    <div class="metric-content"><div class="metric-label">${UI.esc(UI.operationsLabel(c.label))}</div>
    <div class="metric-value">${c.format === 'money' ? UI.money(c.value) : UI.esc(typeof c.value === 'number' ? UI.num(c.value, 2) : c.value)}</div>
    ${c.note ? `<div class="metric-note">${UI.esc(c.note)}</div>` : ''}</div></div>`; }).join('')}</div>`;
/**
 * STAGE_A_WORKSPACE_UI — KPI tiles for the role workspaces (Motorpool, Purchasing).
 * items: [{label, value, note, icon: 'bi-...', tone: amber|blue|green|red|gray, go: 'page?x=y'}]
 * A tile is a link only when the user may open its page (UI.canGo); otherwise it is plain, non-interactive text.
 */
UI.kpiTiles = (items, cls = '') => `<div class="kpi-tiles${cls ? ' ' + UI.esc(cls) : ''}">${(items || []).map(c => { const go = c.go && UI.canGo(c.go);
  return `<div class="kpi-tile tone-${UI.esc(c.tone || 'gray')}${go ? ' clickable' : ''}"${go ? ` data-go="${UI.esc(c.go)}" role="link" tabindex="0" aria-label="${UI.esc(c.label)}: ${UI.esc(c.value)}"` : ''}>
    <div class="kpi-icon"><i class="bi ${UI.esc(c.icon || 'bi-circle')}"></i></div>
    <div class="kpi-body"><div class="kpi-value">${UI.esc(c.value)}</div><div class="kpi-label">${UI.esc(c.label)}</div>${c.note ? `<div class="kpi-note">${UI.esc(c.note)}</div>` : ''}</div>
    ${go ? '<i class="bi bi-chevron-right kpi-go" aria-hidden="true"></i>' : ''}</div>`; }).join('')}</div>`;
/** Back link to a workspace page; empty when the user cannot open that page. */
UI.crumb = (page, label) => UI.canGo(page) ? `<nav class="ws-crumb" aria-label="Back"><button type="button" class="ws-crumb-link" data-go="${UI.esc(page)}"><i class="bi bi-arrow-left"></i> ${UI.esc(label)}</button></nav>` : '';
/** Dashboard / report cards use the same metric strip. */
UI.cards = (cards) => UI.statusStrip((cards || []).map(c => ({ label: c.label, value: c.value, note: c.hint, icon: c.icon, color: c.color && c.color !== 'gray' ? c.color : '', go: c.page, format: c.format })));
UI.goParts = (spec) => {
  const [main, mem] = String(spec || '').split('#'); const [page, qs] = main.split('?');
  return { page, query: Object.fromEntries(new URLSearchParams(qs || '')), tabs: [...new URLSearchParams(mem || '')] };
};
UI.canGo = (spec) => !!spec && !!App.me && (App.me.routes || App.me.nav || []).some(n => n.id === UI.goParts(spec).page);
UI.goTo = (spec) => {
  const g = UI.goParts(spec);
  g.tabs.forEach(([k, v]) => sessionStorage.setItem('tab:' + g.page + (k === '_' ? '' : ':' + k), v));
  App.go(g.page, g.query);
};

UI.bindGo = (root) => root.querySelectorAll('[data-go]').forEach(x => { x.onclick = () => UI.goTo(x.dataset.go); x.onkeydown = (e) => { if (e.key === 'Enter') UI.goTo(x.dataset.go); }; });

/** Panel with a title row (used by tables, charts and sections). */
UI.card = (title, body, tools = '') => `<section class="panel">${title || tools ? `<div class="panel-head"><h2 class="panel-title">${UI.operationsLabel(title)}</h2><div class="panel-tools">${tools}</div></div>` : ''}<div class="panel-body flush">${body}</div></section>`;

/**
 * Data table with search and paging; bulk CSV export is intentionally unavailable.
 * columns: [[label, key, format]] ; opts: {title, actions(row) -> html, onAction, rowClick(row), empty, tools, limit, plain}
 *  plain = compact table without panel and toolbar (inside drawers).
 */

/* FLEETOPS_SYSTEMWIDE_OVERVIEW_V3
 * Every non-plain UI.table uses an essential-data overview; hidden fields remain
 * in a per-row View panel. Financial/status fields get priority. Nothing is removed
 * from source data and each table keeps its own state and existing API actions.
 */
UI.overviewColumns = (columns, opts = {}) => {
  if (opts.plain || opts.showAllColumns || opts.showDates || columns.length <= 1) return columns;
  const date = c => c[2] === 'date' || c[2] === 'datetime' || /(?:date|time|expiry|expires|last login|last_login)/i.test(String(c[0]) + ' ' + String(c[1]));
  const secondary = c => /^(?:email|phone|contact number|address|e-signature|last login|username|registered|registration|insurance|license expiry|notes|remarks|updated by|created by|display order)$/i.test(String(c[0]).trim());
  const score = (c, i) => {
    const label = String(c[0]).toLowerCase(), key = String(c[1]).toLowerCase();
    if (date(c)) return -200;
    if (secondary(c)) return -35;
    if (/status|availability|active|priority|severity|approval|warning/.test(label+' '+key)) return 110;
    if (/amount|total|balance|cost|price|paid|rate|quantity|qty|units|delays|incident/.test(label+' '+key) || c[2] === 'money') return 95;
    if (/client|customer|truck|driver|crew|role|shift|route|trip|name|type|supplier|category|assigned|reference|invoice|plate|request/.test(label+' '+key)) return 85;
    return 55 - i * 2;
  };
  const candidates = columns.map((c, i) => ({c, i, rank:score(c,i)})).filter(x => x.rank > -100);
  if (!candidates.length) return columns.slice(0, Math.min(3,columns.length));
  const chosen = new Set();
  // Keep the record identity as long as the first column is not itself a date.
  if (!date(columns[0])) chosen.add(0);
  for (const x of [...candidates].sort((a,b) => b.rank - a.rank || a.i - b.i)) {
    if (chosen.size >= 5) break;
    if (x.rank >= 0) chosen.add(x.i);
  }
  if (chosen.size < 2 && candidates.length > 1) for (const x of candidates) {
    if (chosen.size >= Math.min(3,candidates.length)) break;
    chosen.add(x.i);
  }
  return columns.filter((_,i) => chosen.has(i));
};
UI.overviewActions = (id, row) => {
  const t = UI._tables[id];
  let html = t.opts.actions ? (t.opts.actions(row) || '') : '';
  // STAGE_B_WORKSPACE_UI: tables that pass directActions show their (already role/state-filtered) icon buttons in the row,
  // instead of collapsing them behind a "Manage" chooser.
  if (t.opts.directActions) return `<div class="row-icons">${html}</div>`;
  const hasView = /data-act=["'](?:view|trip|details|open)["']/i.test(html);
  if (t.columns.length < t.allColumns.length && !hasView) html += '<button type="button" class="btn btn-sm btn-outline-primary" data-fleet-details><i class="bi bi-eye" aria-hidden="true"></i> View</button>';
  const n = (html.match(/<button\b/gi) || []).length;
  if (n > 1) return `<button type="button" class="btn btn-sm btn-outline-primary fleet-manage-btn" data-fleet-manage aria-label="Manage record"><i class="bi bi-three-dots" aria-hidden="true"></i> Manage</button>`;
  return html;
};
UI.overviewDetails = (id, row) => {
  const t = UI._tables[id];
  if (!t) return;
  const details = t.allColumns.map(([label,key,fmt]) => [UI.esc(UI.columnLabel(label, fmt)), UI.fmt(fmt,row[key],row)]);
  UI.modal('Record details', UI.kv(details), null, {size:'modal-lg'});
};

UI.table = (columns, rows, opts = {}) => {
  const id = opts.id || 'tbl' + Math.random().toString(36).slice(2, 8);
  const visibleColumns = UI.overviewColumns(columns, opts);
  UI._tables[id] = { columns: visibleColumns, allColumns: columns, hasActions: !!opts.actions || (!opts.plain && visibleColumns.length < columns.length), rows: rows || [], opts, limit: opts.limit || (opts.plain ? 8 : 15), page: 0, filter: '' };
  if (opts.plain) return `<div class="plain-table"><div class="table-wrap" data-tbody="${id}"></div><div class="table-pager no-print" data-tpager="${id}"></div></div>`;
  if (UI.refPage()) {   // REF_FIDELITY_V1: search + Reset toolbar, then the bordered table (reference list layout)
    return `<div class="ref-list"><div class="ref-toolbar no-print"><div class="table-search ref-search"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" type="search" placeholder="Search records..." aria-label="Search records" data-tsearch="${id}"></div>
      <button type="button" class="btn ref-reset" data-treset="${id}" title="Clear the search, tab and filters">Reset</button><span class="table-count" data-tcount="${id}"></span>${opts.tools || ''}</div>
      <div class="ref-tablebox"><div class="table-wrap" data-tbody="${id}"></div></div><div class="table-pager no-print" data-tpager="${id}"></div></div>`;
  }
  const head = `<div class="table-tools">
      <div class="table-search"><i class="bi bi-search"></i><input class="form-control" type="search" placeholder="Search this list" aria-label="Search this list" data-tsearch="${id}"></div>
      <div class="table-tools-right"><span class="table-count" data-tcount="${id}"></span>${opts.tools || ''}</div></div>`;
  const html = `${head}<div class="table-wrap" data-tbody="${id}"></div><div class="table-pager no-print" data-tpager="${id}"></div>`;
  return opts.title ? UI.card(opts.title, html) : `<section class="panel"><div class="panel-body flush">${html}</div></section>`;
};
UI._tables = {};
/** Presentation metadata only; callers keep their existing paging, filtering and permissions. */
UI.recordPager = ({total,page=1,pages=1,pageSize=10,previous='data-prev',next='data-next',note=''}) => {
  const first=total?(page-1)*pageSize+1:0,last=total?Math.min(page*pageSize,total):0;
  return `<span class="pager-info">Showing ${first}${total?'\u2013'+last:''} of ${total} records${note?` <small>${UI.esc(note)}</small>`:''}</span><nav class="pager-pages" aria-label="Record pages"><button type="button" class="pg" ${previous} ${page<=1?'disabled':''} aria-label="Previous page">Previous</button><span>Page ${page} of ${pages}</span><button type="button" class="pg" ${next} ${page>=pages?'disabled':''} aria-label="Next page">Next</button></nav>`;
};
/** Decorate existing nodes: never clone records/actions or infer a data scope. */
UI.standardTable = (root, meta = {}) => {
  if(!root)return;
  root.classList.add('fleet-data-table');root.dataset.standardTable='';
  let toolbar=meta.toolbar||root.querySelector(':scope > .fleet-table-toolbar, :scope > .table-tools, :scope > .ds-record-controls');
  if(!toolbar&&meta.total!=null){toolbar=document.createElement('div');toolbar.className='fleet-table-toolbar';root.prepend(toolbar);}
  if(toolbar){toolbar.classList.add('fleet-table-toolbar');
    let count=toolbar.querySelector('[data-tcount], [data-standard-count]')||meta.count;
    if(!count){count=document.createElement('span');count.dataset.standardCount='';toolbar.prepend(count);}
    if(meta.total!=null)count.textContent=meta.total+' record'+(meta.total===1?'':'s');
    const toggle=toolbar.querySelector('.rpm-record-controls');
    // Count, working view switch, then the original search/tools (if this page uses them).
    toolbar.prepend(count);if(toggle)count.after(toggle);
  }
  root.querySelectorAll('th.actions-col .visually-hidden').forEach(label=>{label.classList.remove('visually-hidden');label.textContent='Action';});
  root.querySelectorAll('button').forEach(button=>{
    if(['View','View Details'].includes(button.textContent.trim())){
      button.classList.add('fleet-view');
      if(!button.querySelector('.bi-eye'))button.insertAdjacentHTML('afterbegin','<i class="bi bi-eye" aria-hidden="true"></i>');
    }
  });
};
UI.tableRows = (id) => {
  const t = UI._tables[id]; if (!t) return [];
  const f = t.filter.toLowerCase();
  return f ? t.rows.filter(r => t.allColumns.some(([, k]) => String(r[k] ?? '').toLowerCase().includes(f))) : t.rows;
};
/** Draws one page of a table, with page numbers. */
/** Consistent, formal labels across every table in FleetOps. */
UI.columnLabel = (label, type = '') => {
  label=UI.operationsLabel(label);
  const text = String(label || '');
  if (text === 'When') return 'Date and time';
  if (text === 'Schedule' || text === 'Scheduled') return type === 'datetime' ? 'Scheduled date and time' : 'Scheduled date';
  if (text === 'Date' && type === 'datetime') return 'Date and time';
  if (text === 'Completed' && type === 'datetime') return 'Completion date and time';
  if (text === 'Reported' && type === 'datetime') return 'Reported date and time';
  if (text === 'Due' && type === 'date') return 'Due date';
  if (text === 'Created' && type === 'datetime') return 'Created date and time';
  return text;
};
/** Date input labels use dates, not ambiguous 'from/to/when'. */
UI.formLabel = (field) => {
  const label = String(UI.operationsLabel(field.label || ''));
  if (!['date', 'datetime'].includes(field.type)) return label;
  if (label === 'From') return 'Start date';
  if (label === 'To') return 'End date';
  if (label === 'When') return field.type === 'datetime' ? 'Date and time' : 'Date';
  if (label === 'Schedule from') return 'Scheduled start date';
  if (label === 'Schedule to') return 'Scheduled end date';
  if (label === 'Expected back') return 'Expected availability date';
  return label;
};
UI.drawTable = (id) => {
  const t = UI._tables[id]; const box = document.querySelector(`[data-tbody="${id}"]`); if (!t || !box) return;
  const rows = UI.tableRows(id);
  const count = document.querySelector(`[data-tcount="${id}"]`);
  if (count) count.textContent = rows.length === t.rows.length ? `${rows.length} record${rows.length === 1 ? '' : 's'}` : `${rows.length} of ${t.rows.length}`;
  const pager = box.parentElement.querySelector(`[data-tpager="${id}"]`);
  if (pager) pager.innerHTML = '';
  if (!rows.length) {
    if((!t.opts.plain||(UI.role('operations_head')&&box.closest('.app-drawer')&&!box.closest('form')&&!t.opts.dsServerPaged))&&(UI.role('operations_head')||UI.isDispatcher())){
      const message=t.filter?'No records match the current search or filters.':t.opts.empty||'No records found.';
      box.innerHTML='<table class="data-table"><thead><tr>'+t.columns.map(([label,,format])=>'<th>'+UI.esc(UI.columnLabel(label,format))+'</th>').join('')+(t.hasActions?'<th class="actions-col">Action</th>':'')+'</tr></thead><tbody><tr><td colspan="'+(t.columns.length+(t.hasActions?1:0))+'"><div class="table-empty" role="status">'+UI.esc(message)+'</div></td></tr></tbody></table>';
      if(pager&&!t.opts.dsServerPaged)pager.innerHTML=UI.recordPager({total:0});
    } else { box.innerHTML = `<div class="table-empty"><i class="bi ${t.filter ? 'bi-search' : 'bi-inbox'}"></i><span>${UI.esc(t.filter ? 'No record matches "' + t.filter + '".' : (t.opts.empty || 'No records yet.'))}</span></div>`; }
    return;
  }
  const pages = Math.ceil(rows.length / t.limit);
  t.page = Math.min(Math.max(t.page, 0), pages - 1);
  const first = t.page * t.limit, shown = rows.slice(first, first + t.limit);
  const num = (f) => f === 'money' || f === 'num';
  const cellClass = (f) => f === 'money' ? 'num money-col' : f === 'num' ? 'count-col' : (f === 'badge' || f === 'yes') ? 'status-col' : '';
  // COMPACT_TABLES_V1: on reference pages, columns hug their content; only long-text columns (cell-clip / cell-clamp) take the spare width.
  const grow = UI.refPage() ? t.columns.map(([, k, f]) => typeof f === 'function' && shown.some(r => /cell-cl(?:ip|amp)/.test(String(f(r[k], r) || '')))) : [];
  box.innerHTML = `<table class="data-table${t.opts.rowClick ? ' clickable' : ''}"><thead><tr>${t.columns.map(([l, , f], i) => `<th class="${cellClass(f)}${grow[i] ? ' col-grow' : ''}">${UI.esc(UI.columnLabel(l, f))}</th>`).join('')}${t.hasActions ? '<th class="actions-col"><span class="visually-hidden">Actions</span></th>' : ''}</tr></thead>
    <tbody>${shown.map((r) => `<tr data-ri="${t.rows.indexOf(r)}">${t.columns.map(([, k, f], i) => `<td class="${cellClass(f)}${grow[i] ? ' col-grow' : ''}">${UI.fmt(f, r[k], r)}</td>`).join('')}${t.hasActions ? `<td class="actions-col"><div class="row-actions" data-row="${id}:${t.rows.indexOf(r)}">${UI.overviewActions(id,r)}</div></td>` : ''}</tr>`).join('')}</tbody></table>`;
  if(pager&&t.opts.dsServerPaged){pager.innerHTML='';}
  else if(pager&&(!t.opts.plain||(UI.role('operations_head')&&box.closest('.app-drawer')&&!box.closest('form')&&!t.opts.dsServerPaged))&&(UI.role('operations_head')||UI.isDispatcher())){
    pager.innerHTML=UI.recordPager({total:rows.length,page:t.page+1,pages:Math.max(1,pages),pageSize:t.limit,previous:'data-tpg="'+(t.page-1)+'"',next:'data-tpg="'+(t.page+1)+'"'});
    pager.querySelectorAll('[data-tpg]').forEach(button=>button.onclick=()=>{t.page=Number(button.dataset.tpg);UI.drawTable(id);});
  } else if (pager && pages > 1) {
    const nums = [];
    for (let i = 0; i < pages; i++) if (i === 0 || i === pages - 1 || Math.abs(i - t.page) <= 1) nums.push(i); else if (nums[nums.length - 1] !== '…') nums.push('…');
    pager.innerHTML = `<span class="pager-info">${first + 1}–${first + shown.length} of ${rows.length}</span><nav class="pager-pages" aria-label="Pages">
      <button type="button" class="pg" data-tpg="${t.page - 1}" ${t.page === 0 ? 'disabled' : ''} aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>
      ${nums.map(n => n === '…' ? '<span class="pg-gap">…</span>' : `<button type="button" class="pg ${n === t.page ? 'active' : ''}" data-tpg="${n}">${n + 1}</button>`).join('')}
      <button type="button" class="pg" data-tpg="${t.page + 1}" ${t.page >= pages - 1 ? 'disabled' : ''} aria-label="Next page"><i class="bi bi-chevron-right"></i></button></nav>`;
    pager.querySelectorAll('[data-tpg]').forEach(b => b.onclick = () => { t.page = +b.dataset.tpg; UI.drawTable(id); });
  }

  // FLEETOPS_SYSTEMWIDE_OVERVIEW_V3: one compact Manage control for multi-action rows.
  // Existing handlers are invoked only after the chooser closes, to avoid stacked modals.
  box.querySelectorAll('[data-fleet-manage]').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    const rowElement = button.closest('tr[data-ri]');
    const row = t.rows[Number(rowElement?.dataset.ri)];
    if (!row) return;
    const raw = (t.opts.actions ? t.opts.actions(row) : '') || '';
    const hasView = /data-act=["'](?:view|trip|details|open)["']/i.test(raw);
    const more = t.columns.length < t.allColumns.length && !hasView
      ? '<button type="button" class="btn btn-sm btn-outline-primary" data-fleet-details>View details</button>' : '';
    const dialog = UI.modal('Manage record', `<div class="fleet-action-chooser">${more}${raw}</div>`, null, {size:''});
    dialog.querySelectorAll('[data-act], [data-fleet-details]').forEach(action => action.addEventListener('click', e => {
      e.stopPropagation();
      const act = action.dataset.act;
      dialog.addEventListener('hidden.bs.modal', () => {
        if (action.hasAttribute('data-fleet-details')) UI.overviewDetails(id,row);
        else t.opts.onAction?.(act,row,action);
      }, {once:true});
      bootstrap.Modal.getInstance(dialog)?.hide();
    }));
  }));
  box.querySelectorAll('[data-fleet-details]').forEach(button => button.addEventListener('click', event => {
    event.stopPropagation();
    const row = t.rows[Number(button.closest('tr[data-ri]')?.dataset.ri)];
    if (row) UI.overviewDetails(id,row);
  }));

  if (t.opts.onAction) box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', (e) => {
    e.stopPropagation();
    const idx = +b.closest('[data-row]').dataset.row.split(':')[1]; t.opts.onAction(b.dataset.act, t.rows[idx], b);
  }));
  if (t.opts.rowClick) box.querySelectorAll('tbody tr').forEach(tr => tr.addEventListener('click', (e) => {
    if (e.target.closest('a,button,input,select,label')) return;
    t.opts.rowClick(t.rows[+tr.dataset.ri]);
  }));
};

/** Move existing masterlist quick tabs and Filter next to Search in the table toolbar.
 *  DOM movement preserves their original click handlers and modal filter logic.
 *  Order: Search | Filter | Quick buttons | active filter chips | row count.
 */
UI.mountMasterlistQuickToolbar = (root) => {
  const quickButtons = [...root.querySelectorAll(
    '[data-masterlist-tab], [data-client-quick], [data-mechanic-quick]'
  )];
  if (!quickButtons.length) return;
  const toolbar = root.querySelector('.table-tools');
  const filterBar = root.querySelector('.filter-summary');
  if (!toolbar || !filterBar) return;
  if (toolbar.querySelector('.masterlist-toolbar-controls')) return;

  const controls = document.createElement('div');
  controls.className = 'masterlist-toolbar-controls';
  controls.setAttribute('role', 'group');
  controls.setAttribute('aria-label', 'Record filters');

  // Move entire filter bar, not just the Filter button. data-fid is needed
  // by the shared delegated click listener in UI.filterModal.
  const filterParent = filterBar.parentElement;
  controls.append(filterBar);
  filterBar.classList.add('masterlist-toolbar-filter');
  // The permanent "Showing all ..." hint consumes space next to Search.
  // Actual filter chips and Reset remain visible.
  [...filterBar.children].forEach(node => {
    if (node.tagName === 'SPAN' && !node.classList.contains('filter-chip')) {
      node.classList.add('masterlist-toolbar-hint');
    }
  });

  const oldGroups = new Set();
  for (const button of quickButtons) {
    const oldGroup = button.parentElement;
    oldGroups.add(oldGroup);
    button.classList.remove('btn-primary', 'btn-group', 'active');
    button.classList.add('btn', 'btn-sm', 'btn-outline-secondary', 'masterlist-quick-btn');
    // Respect existing aria-pressed; communicate selection without a solid block.
    if (button.getAttribute('aria-pressed') === 'true') {
      button.classList.add('masterlist-quick-selected');
    }
    controls.append(button);
  }

  const search = toolbar.querySelector('.table-search');
  if (search) search.insertAdjacentElement('afterend', controls);
  else toolbar.prepend(controls);

  // Remove now-empty decorative wrappers, not the shared Filter root.
  for (const oldGroup of oldGroups) {
    if (oldGroup && oldGroup !== root && oldGroup !== toolbar &&
        !oldGroup.children.length && !oldGroup.textContent.trim()) {
      oldGroup.remove();
    }
  }
  if (filterParent && filterParent !== root && filterParent !== toolbar &&
      !filterParent.children.length && !filterParent.textContent.trim()) {
    filterParent.remove();
  }
};

/** Call after inserting table HTML into the page (the router calls it for the page body). */
UI.activate = (root) => {
  root.querySelectorAll('[data-tbody]').forEach(b => { if (!b.dataset.drawn) { b.dataset.drawn = '1'; UI.drawTable(b.dataset.tbody); } });
  root.querySelectorAll('[data-tsearch]').forEach(inp => { if (inp.dataset.bound) return; inp.dataset.bound = '1';
    inp.addEventListener('input', () => { const t = UI._tables[inp.dataset.tsearch]; t.filter = inp.value.trim(); t.page = 0; UI.drawTable(inp.dataset.tsearch); }); });
  UI.bindGo(root);
  root.querySelectorAll('[data-chart]').forEach(c => { if (!c.dataset.drawn) { c.dataset.drawn = '1'; UI.drawChart(c); } });
  root.querySelectorAll('[data-tpicker]').forEach(box => { if (!box.dataset.drawn) { box.dataset.drawn = '1'; UI.drawPicker(box); } });
  root.querySelectorAll('[data-chartmore]').forEach(m => m.querySelector('button').onclick = () => {
    const holder = document.createElement('div'); holder.className = 'chart-grid';
    holder.innerHTML = (UI._more[m.dataset.chartmore] || []).map(UI.chartHtml).join('');
    m.replaceWith(holder); holder.querySelectorAll('[data-chart]').forEach(c => UI.drawChart(c));
  });
  UI.mountMasterlistQuickToolbar(root);
};
/** STAGE_B_WORKSPACE_UI — compact icon action for table rows: tooltip + accessible label, same data-act contract as UI.btn. */
UI.ibtn = (act, label, icon, cls = 'outline-secondary') =>
  `<button type="button" class="btn btn-sm btn-${cls} icon-act" data-act="${UI.esc(act)}" title="${UI.esc(label)}" aria-label="${UI.esc(label)}"><i class="bi ${UI.esc(icon)}" aria-hidden="true"></i></button>`;
UI.btn = (act, label, cls = 'outline-secondary', icon = '') => {
  const text = UI.operationsLabel(label === 'Open' ? 'View' : label === 'Open trip' ? 'View trip' : label);
  const isView = act === 'view' || (act === 'trip' && text === 'View trip');
  const variant = isView && cls === 'outline-secondary' ? 'outline-primary' : cls;
  const viewIcon = isView && !icon ? 'bi-eye' : icon;
  return `<button type="button" class="btn btn-sm btn-${variant}${isView ? ' table-view-btn' : ''}" data-act="${act}">${viewIcon ? `<i class="bi ${viewIcon}" aria-hidden="true"></i> ` : ''}${UI.esc(text)}</button>`;
};

// ------------------------------------------------------------------ forms
/**
 * fields: [{name, label, type, options:[[value,label]], required, value, help, col, min, step, placeholder, attrs}]
 * types: text, number, money, date, datetime, select, textarea, checkbox, password, file, hidden, html
 */
UI.fields = (fields) => `<div class="row g-2">${fields.map(UI.field).join('')}</div>`;
UI.field = (f) => {
  if (f.type === 'hidden') return `<input type="hidden" name="${f.name}" value="${UI.esc(f.value ?? '')}">`;
  if (f.type === 'html') return `<div class="col-${f.col || 12}">${f.html}</div>`;
  const id = 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 6);
  const req = f.required ? 'required' : '';
  const val = f.value ?? '';
  let input;
  if (f.type === 'select') {
    input = `<select class="form-select form-select-sm" id="${id}" name="${f.name}" ${req} ${f.attrs || ''}>${f.placeholder === false ? '' : `<option value="">${UI.esc(f.placeholder || 'Choose…')}</option>`}
      ${(f.options || []).map(o => { const [v, l] = Array.isArray(o) ? o : [o, o]; return `<option value="${UI.esc(v)}" ${String(v) === String(val) ? 'selected' : ''}>${UI.esc(l)}</option>`; }).join('')}</select>`;
  } else if (f.type === 'textarea') {
    input = `<textarea class="form-control form-control-sm" id="${id}" name="${f.name}" rows="${f.rows || 3}" ${req} placeholder="${UI.esc(f.placeholder || '')}">${UI.esc(val)}</textarea>`;
  } else if (f.type === 'checkbox') {
    return `<div class="col-${f.col || 12}"><div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="${id}" name="${f.name}" value="1" ${+val ? 'checked' : ''}>
      <label class="form-check-label small" for="${id}">${UI.esc(UI.formLabel(f))}</label>${f.help ? `<div class="form-help">${UI.esc(f.help)}</div>` : ''}</div></div>`;
  } else {
    const type = { money: 'number', datetime: 'datetime-local' }[f.type] || f.type || 'text';
    const step = f.type === 'money' ? 'step="0.01" min="0"' : (f.type === 'number' ? `step="${f.step || 1}" min="${f.min ?? 0}"` : '');
    input = `<input class="form-control form-control-sm" type="${type}" id="${id}" name="${f.name}" value="${UI.esc(val)}" ${step} ${req} placeholder="${UI.esc(f.placeholder || '')}" ${f.type === 'file' && f.multiple ? 'multiple' : ''} ${f.attrs || ''}>`;
  }
  return `<div class="col-md-${f.col || 6}"><label class="form-label ${f.required ? 'req' : ''}" for="${id}">${UI.esc(UI.formLabel(f))}</label>${input}${f.help ? `<div class="form-help">${UI.esc(f.help)}</div>` : ''}</div>`;
};
/** Reads a form into an object (checkboxes -> 1/0). */
UI.formData = (form) => {
  const d = {};
  new FormData(form).forEach((v, k) => { if (!(v instanceof File)) d[k] = v; });
  form.querySelectorAll('input[type=checkbox][name]').forEach(c => { d[c.name] = c.checked ? 1 : 0; });
  return d;
};

/**
 * Application modal with a form. onSubmit(data, form, wrap) may throw an Error; the message
 * shows inside the modal. Returns the modal element.
 */
/* CHECKLIST_18_MODAL_UNSAVED_GUARD_V1 */
/** Capture user-editable modal values, including selected files (not file bytes). */
UI.modalEditSnapshot = (form) => JSON.stringify([...form.querySelectorAll('input, select, textarea')]
  .filter(el => !el.disabled && el.name && el.type !== 'hidden' &&
    !['submit', 'button', 'reset'].includes(el.type))
  .map(el => [el.name, el.type === 'file' ? [...el.files].map(f => [f.name, f.size, f.lastModified])
    : (el.type === 'checkbox' || el.type === 'radio') ? el.checked : el.value]));

/* CHECKLIST_18_CONFIRM_BEFORE_SAVE_V2 */
/** Custom confirmation layer ABOVE the current app modal (never browser confirm). */
UI.askWithinModal = (wrap, title, message, confirmLabel = 'Confirm changes') => new Promise(resolve => {
  if (wrap.querySelector('[data-app-confirm-layer]')) { resolve(false); return; }
  const layer = document.createElement('div');
  layer.dataset.appConfirmLayer = '1';
  layer.style.cssText = 'position:fixed;inset:0;z-index:1100;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(0,0,0,.42)';
  layer.innerHTML = `<div role="alertdialog" aria-modal="true" aria-labelledby="appConfirmTitle" aria-describedby="appConfirmText"
    class="bg-white rounded-3 shadow-lg border p-4" style="width:min(100%,430px);max-height:90vh;overflow-y:auto">
    <h5 id="appConfirmTitle" class="mb-2"></h5>
    <p id="appConfirmText" class="mb-3 text-muted"></p>
    <div class="d-flex flex-wrap justify-content-end gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-app-confirm-no>Go back</button>
      <button type="button" class="btn btn-sm btn-primary" data-app-confirm-yes></button>
    </div></div>`;
  layer.querySelector('#appConfirmTitle').textContent = title;
  layer.querySelector('#appConfirmText').textContent = message;
  layer.querySelector('[data-app-confirm-yes]').textContent = confirmLabel;
  const previousFocus = document.activeElement;
  let completed = false;
  const finish = (result) => {
    if (completed) return;
    completed = true;
    layer.remove();
    if (previousFocus && previousFocus.isConnected) previousFocus.focus();
    resolve(result);
  };
  layer.querySelector('[data-app-confirm-no]').onclick = () => finish(false);
  layer.querySelector('[data-app-confirm-yes]').onclick = () => finish(true);
  layer.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); }
    if (e.key === 'Tab') {
      const no = layer.querySelector('[data-app-confirm-no]');
      const yes = layer.querySelector('[data-app-confirm-yes]');
      if (e.shiftKey && document.activeElement === no) { e.preventDefault(); yes.focus(); }
      else if (!e.shiftKey && document.activeElement === yes) { e.preventDefault(); no.focus(); }
    }
  }, true);
  wrap.append(layer);
  layer.querySelector('[data-app-confirm-no]').focus();
});


/* FLEETOPS_ACTION_CONFIRMATIONS_V3 */
/** Ask before any deliberate data-changing operation, using the app's own UI. */
UI.confirmProceed = (title, message, label = 'Yes, proceed') => {
  const activeModal = [...document.querySelectorAll('.modal.show')].at(-1);
  if (activeModal) return UI.askWithinModal(activeModal, title, message, label);
  return new Promise(resolve => {
    let accepted = false;
    let settled = false;
    const done = value => { if (!settled) { settled = true; resolve(value); } };
    const wrap = UI.modal(UI.esc(title), `<p class="mb-0">${UI.esc(message)}</p>`,
      async () => { accepted = true; }, {
        size: '', submit: label, confirmBeforeSubmit: false, guardUnsaved: false
      });
    wrap.addEventListener('hidden.bs.modal', () => done(accepted), { once: true });
  });
};

UI.modal = (title, bodyHtml, onSubmit, opts = {}) => {
  title=UI.operationsLabel(title);if(opts.submit)opts={...opts,submit:UI.operationsLabel(opts.submit)};
  const wrap = document.createElement('div');
  wrap.className = 'modal fade'; wrap.tabIndex = -1; wrap.setAttribute('aria-modal', 'true'); wrap.setAttribute('role', 'dialog');
  wrap.innerHTML = `<div class="modal-dialog ${opts.size ?? 'modal-lg'} modal-dialog-scrollable modal-dialog-centered"><form class="modal-content" novalidate>
    <div class="modal-header"><h5 class="modal-title">${title}</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">${bodyHtml}<div class="alert alert-danger mt-3 mb-0 form-error" role="alert"></div></div>
    <div class="modal-footer">${opts.extraFooter || ''}<button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">${onSubmit ? 'Cancel' : 'Close'}</button>
    ${onSubmit ? `<button type="submit" class="btn btn-${opts.danger ? 'danger' : 'primary'} btn-sm">${UI.esc(opts.submit || 'Save')}</button>` : ''}</div></form></div>`;
  document.body.append(wrap);
  const m = new bootstrap.Modal(wrap);
  const form = wrap.querySelector('form');
  let confirmPending = false;
  form.onsubmit = async (e) => {
    e.preventDefault();
    const err = wrap.querySelector('.form-error'); err.style.display = 'none';
    form.querySelectorAll('.is-invalid').forEach(x => x.classList.remove('is-invalid'));
    const visibleControls = [...form.querySelectorAll('input, select, textarea')].filter(x => !x.disabled && (x.offsetParent !== null || x.type === 'hidden'));
    const invalid = visibleControls.find(x => {
      if (x.required && x.type === 'checkbox' && !x.checked) return true;
      if (x.required && x.type === 'file' && !x.files.length) return true;
      return !x.checkValidity();
    });
    if (invalid) {
      invalid.classList.add('is-invalid');
      const fieldLabel = (form.querySelector(`label[for="${invalid.id}"]`)?.textContent || invalid.name || 'field').replace(/\s*\*$/, '');
      err.textContent = invalid.validity.valueMissing || (invalid.type === 'checkbox' && !invalid.checked)
        ? 'Complete the required field: ' + fieldLabel
        : 'Please enter a valid value for: ' + fieldLabel;
      err.style.display = 'block'; invalid.focus(); return;
    }
    // Validate first, ask user inside the app, then execute the save/API request.
    // Existing explicit confirm-action modals opt out to avoid double prompts.
    if (onSubmit && opts.confirmBeforeSubmit !== false) {
      confirmPending = true;
      let accepted = false;
      try {
        accepted = await UI.askWithinModal(wrap, 'Confirm changes',
          'Are you sure you want to proceed? Your changes will be saved only after confirmation.',
          'Confirm & save');
      } finally { confirmPending = false; }
      if (!accepted) return;
    }
    const btn = form.querySelector('button[type=submit]'); const label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing…';
    try { await onSubmit(UI.formData(form), form, wrap); saveCompleted = true; m.hide(); }
    catch (x) { err.textContent = x.message; err.style.display = 'block'; }
    finally { btn.disabled = false; btn.innerHTML = label; }
  };
   // Checklist #18: in-app discard dialog; never call window.confirm.
   // Render within the existing Bootstrap modal to avoid competing backdrops.
   let initialValues = null;
   let saveCompleted = false;
   let allowDiscard = false;
   const showDiscardDialog = () => {
     if (wrap.querySelector('[data-unsaved-dialog]')) return;
     const dialog = document.createElement('div');
     dialog.className = 'unsaved-dialog-layer';
     dialog.dataset.unsavedDialog = '1';
     dialog.style.cssText = 'position:absolute;inset:0;z-index:10;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.42);';
     dialog.innerHTML = `<section role="alertdialog" aria-modal="true" aria-labelledby="unsavedTitle"
       aria-describedby="unsavedMessage" class="bg-white shadow-lg rounded-3 border p-4"
       style="width:min(100%,430px);max-height:90%;overflow:auto">
       <h5 id="unsavedTitle" class="mb-2"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Unsaved changes</h5>
       <p id="unsavedMessage" class="text-muted mb-3">You have unsaved changes. Discard them and close this form?</p>
       <div class="d-flex justify-content-end gap-2 flex-wrap">
         <button type="button" class="btn btn-sm btn-outline-secondary" data-unsaved-keep>Keep editing</button>
         <button type="button" class="btn btn-sm btn-danger" data-unsaved-discard>Discard changes</button>
       </div>
     </section>`;
     // Keep Escape and backdrop clicks from closing the parent while asking.
     const escGuard = (e) => {
       if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); dialog.querySelector('[data-unsaved-keep]').click(); }
     };
     dialog.addEventListener('keydown', escGuard, true);
     const closeDialog = () => { dialog.remove(); };
     dialog.querySelector('[data-unsaved-keep]').onclick = () => {
       closeDialog();
       form.querySelector('input:not([type=hidden]),select,textarea')?.focus();
     };
     dialog.querySelector('[data-unsaved-discard]').onclick = () => {
       allowDiscard = true;
       closeDialog();
       m.hide();
     };
     wrap.append(dialog);
     dialog.querySelector('[data-unsaved-keep]').focus();
   };
   wrap.addEventListener('hide.bs.modal', (event) => {
     if (confirmPending) { event.preventDefault(); return; }
     if (allowDiscard || !onSubmit || opts.guardUnsaved === false || saveCompleted || initialValues === null) return;
     if (UI.modalEditSnapshot(form) === initialValues) return;
     event.preventDefault();
     showDiscardDialog();
   });
   wrap.addEventListener('hidden.bs.modal', () => wrap.remove());
   m.show();
   if (opts.onShow) opts.onShow(wrap, form);
   // Wait until initial conditional fields/onShow handlers have populated the form.
   queueMicrotask(() => { initialValues = UI.modalEditSnapshot(form); });
   return wrap;
};
/** Standard "form -> action -> reload page" modal. */
UI.formModal = (title, fields, action, opts = {}) => UI.modal(title, (opts.intro ? `<p class="modal-intro">${opts.intro}</p>` : '') + UI.fields(fields), async (d, form, wrap) => {
  const payload = opts.transform ? opts.transform(d, form, wrap) : d;
  const r = await App.api(action, { ...(opts.extra || {}), ...payload });
  UI.result(r);
  if (opts.after) opts.after(r); else App.route();
}, opts);
UI.confirmAction = (title, text, action, payload, opts = {}) => UI.modal(title, `<p class="mb-${opts.fields ? 3 : 0}">${text}</p>${opts.fields ? UI.fields(opts.fields) : ''}`, async (d) => {
  const r = await App.api(action, { ...payload, ...d }); UI.result(r);
  if (opts.after) opts.after(r); else { UI.closeDrawer(); App.route(); }
}, { size: '', submit: opts.submit || 'Confirm', danger: opts.danger, confirmBeforeSubmit: false });
/** Simple in-app confirmation (replaces the browser's confirm()). */
UI.confirm = (title, text, onYes, opts = {}) => UI.modal(UI.esc(title), `<p class="mb-0">${UI.esc(text)}</p>`, async () => { await onYes(); }, { size: '', submit: opts.submit || 'Confirm', danger: opts.danger, confirmBeforeSubmit: false });

/**
 * Tabs: tabs = [[key, label(html), htmlFn]]. Remembers the last tab of each page.
 * opts.key = separate memory when a page has more than one tab group; opts.pills = small pills.
 */
/** Hide display-only record counts in navigation labels; preserve counts in KPIs and tables. */
UI.tabLabel = label => String(label ?? '').replace(/\s*\(\d[\d,]*\)\s*$/, '').trim();
UI.tabs = (root, tabs, active, opts = {}) => {
  if (!tabs.length) return;
  const id = 'tabs' + Math.random().toString(36).slice(2, 6);
  const mem = 'tab:' + (App.current?.page || 'record') + (opts.key ? ':' + opts.key : '');
  active = active || tabs[0][0];
  root.insertAdjacentHTML('beforeend', `<div class="${opts.pills ? 'pill-tabs' : 'line-tabs'} no-print" id="${id}" role="tablist">${tabs.map(([k, l]) => `<button type="button" role="tab" class="tab ${k === active ? 'active' : ''}" data-tab="${k}">${UI.tabLabel(l)}</button>`).join('')}</div><div id="${id}body" class="tab-body"></div>`);
  const body = document.getElementById(id + 'body');
  const retained = new Map();
  const buttons = [...document.querySelectorAll(`#${id} > [data-tab]`)];
  buttons.forEach((button, index) => { button.id = id + '-tab-' + index; button.setAttribute('aria-controls', opts.retainPanels ? id + '-panel-' + index : body.id); });
  if (!opts.retainPanels) body.setAttribute('role', 'tabpanel');
  const show = (k) => {
    buttons.forEach(b => { const on = b.dataset.tab === k; b.classList.toggle('active', on); b.setAttribute('aria-selected', on); b.tabIndex = on ? 0 : -1; if(on) body.setAttribute('aria-labelledby', b.id); });
    const t = tabs.find(x => x[0] === k);
    try {
      if (opts.retainPanels) {
        // Move existing nodes once. Keep hidden panels mounted so handlers, inputs and audit text survive.
        tabs.forEach(([key, , render], index) => {
          if (!retained.has(key)) { const panel = document.createElement('section'); panel.id = id + '-panel-' + index; panel.setAttribute('role','tabpanel'); panel.setAttribute('aria-labelledby',buttons[index].id); panel.tabIndex=0; body.append(panel); const out=render(panel); if(typeof out==='string')panel.innerHTML=out; retained.set(key,panel); UI.activate(panel); }
          retained.get(key).hidden=key!==k;
        });
      } else { body.innerHTML = ''; const out = t[2](body); if (typeof out === 'string') body.innerHTML = out; UI.activate(body); }
    }
    catch (e) { console.error(e); body.innerHTML = `<div class="module-down"><i class="bi bi-exclamation-triangle"></i><div>${UI.esc(e.message)}</div></div>`; }
    sessionStorage.setItem(mem, k);
  };
  buttons.forEach((b,index) => { b.onclick = () => show(b.dataset.tab); b.onkeydown = event => { let next; if(event.key==='ArrowRight')next=(index+1)%buttons.length;else if(event.key==='ArrowLeft')next=(index+buttons.length-1)%buttons.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=buttons.length-1;else return;event.preventDefault();buttons[next].click();buttons[next].focus(); }; });
  const remembered = sessionStorage.getItem(mem);
  show(opts.forceActive && tabs.some(t => t[0] === active) ? active : (tabs.some(t => t[0] === remembered) ? remembered : active));
};

// ------------------------------------------------------------------ charts (Chart.js, repo colors)
UI.palette = ['#1f2328', '#d99a1e', '#0b5cad', '#1a7f37', '#b5121b', '#6e7781', '#8250df', '#0891b2', '#9a6700', '#57606a'];
/** One color per meaning in every chart (status, money, aging, truck type). */
UI.meaningColors = {
  'Available': '#1a7f37', 'On Trip': '#0b5cad', 'Under Maintenance': '#b5121b', 'Unavailable': '#8c959f',
  'Billed': '#2563eb', 'Collected': '#15803d', 'Expenses': '#c62828',
  'Cash in (collections)': '#15803d', 'Cash out (disbursements)': '#c62828',
  'All trips': '#1f2328', 'Trips': '#1f2328', 'Completed': '#1a7f37', 'Completed trips': '#1a7f37',
  'Parts': '#0b5cad', 'Labor': '#d99a1e', 'Other': '#6e7781',
  'Not yet due': '#15803d', '1-30 days': '#f59e0b', '31-60 days': '#ea580c', '61-90 days': '#c62828', 'Over 90 days': '#7f1d1d',
  'Assigned': '#0b5cad',
  'Car Carrier': '#1f2328', 'Container': '#d99a1e', 'Wing Van': '#0b5cad',
  'Client': '#d99a1e',
};
UI.colorOf = (label, i) => UI.meaningColors[label] || UI.palette[i % UI.palette.length];
UI._charts = [];
UI.chartEmpty = (c) => !c.labels || !c.labels.length || c.datasets.every(d => !d.data.some(x => +x));
UI.chartHtml = (c) => {
  const id = 'ch' + Math.random().toString(36).slice(2, 8);
  UI._charts[id] = c;
  // Horizontal bars: height follows the number of names so the bars are not crowded.
  const h = c.horizontal ? Math.max(220, Math.min(640, 70 + c.labels.length * 30)) : 0;
  return `<section class="panel chart-panel"><div class="panel-head"><h2 class="panel-title">${UI.esc(c.title)}</h2></div><div class="panel-body">
    ${UI.chartEmpty(c) ? '<div class="table-empty"><i class="bi bi-bar-chart"></i><span>No data for this period.</span></div>' : `<div class="chart-box${c.horizontal ? ' chart-box-h' : ''}" ${h ? `style="height:${h}px"` : ''}><canvas data-chart="${id}"></canvas></div>`}
    ${c.how ? `<div class="chart-how"><i class="bi bi-info-circle"></i> ${UI.esc(c.how)}</div>` : ''}</div></section>`;
};
/** Chart.js settings from a chart spec: peso format, %, colors by meaning, horizontal, stacked. */
UI.chartConfig = (c, forPrint = false) => {
  const round = c.type === 'doughnut' || c.type === 'pie';
  const money = c.format === 'money';
  const fmt = (v) => money ? UI.money(v) : UI.num(v, 2);
  const axisFmt = (v) => money ? '₱' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }) : UI.num(v, 2);
  const valueAxis = c.horizontal ? 'x' : 'y', nameAxis = c.horizontal ? 'y' : 'x';
  // Counts (trips, units): whole numbers only on the axis.
  const whole = !money && c.datasets.every(d => d.data.every(x => Number.isInteger(+x)));
  return {
    type: c.type,
    data: { labels: c.labels, datasets: c.datasets.map((d, i) => ({ ...d,
      backgroundColor: round ? c.labels.map((l, j) => UI.colorOf(l, j)) : UI.colorOf(d.label, i),
      borderColor: round ? '#fff' : UI.colorOf(d.label, i), tension: .3, fill: false, maxBarThickness: 48 })) },
    options: {
      responsive: !forPrint, maintainAspectRatio: false, animation: forPrint ? false : undefined,
      indexAxis: c.horizontal ? 'y' : 'x',
      plugins: {
        legend: { display: round || c.datasets.length > 1, position: 'bottom' },
        tooltip: { callbacks: { label: (ctx) => {
          const v = ctx.raw;
          if (round) { const total = ctx.dataset.data.reduce((a, b) => a + (+b || 0), 0); return `${ctx.label}: ${fmt(v)} (${total ? (v / total * 100).toFixed(1) : 0}%)`; }
          return `${ctx.dataset.label}: ${fmt(v)}`;
        } } },
      },
      scales: round ? {} : {
        [valueAxis]: { beginAtZero: true, stacked: !!c.stacked, ticks: { callback: axisFmt, ...(whole ? { precision: 0 } : {}) } },
        [nameAxis]: { stacked: !!c.stacked, ticks: { autoSkip: !c.horizontal } },
      },
    },
  };
};
UI.drawChart = (canvas) => {
  const c = UI._charts[canvas.dataset.chart]; if (!c || typeof Chart === 'undefined') return;
  new Chart(canvas, UI.chartConfig(c));
};

/** PNG image of a chart, for printing. */
UI.chartImage = (c, w = 900, h = 340) => {
  if (typeof Chart === 'undefined' || UI.chartEmpty(c)) return '';
  const cv = document.createElement('canvas'); cv.width = w; cv.height = c.horizontal ? Math.max(h, 70 + c.labels.length * 26) : h;
  cv.style.position = 'fixed'; cv.style.left = '-10000px'; document.body.append(cv);
  const ch = new Chart(cv, UI.chartConfig(c, true));
  const url = cv.toDataURL('image/png'); ch.destroy(); cv.remove();
  return url;
};

/**
 * Renders the common {alerts, cards, charts, tables} block used by dashboards and reports.
 * Order: alerts (to do) -> cards -> charts -> tables.
 * opts.maxCharts = charts visible at once; opts.stack = show every table (dashboard) instead of a picker.
 */
UI.blocks = (spec, opts = {}) => {
  let h = '';
  if (spec.alerts && spec.alerts.length) h += `<section class="panel attention"><div class="panel-head"><h2 class="panel-title"><i class="bi bi-flag"></i> Needs attention</h2></div>
    <ul class="attention-list">${spec.alerts.map(a => `<li class="lvl-${a.level || 'info'}"><span class="dot"></span><span class="txt">${UI.esc(a.text)}</span>${a.page && UI.canGo(a.page) ? `<button type="button" class="btn btn-sm btn-link" data-go="${UI.esc(a.page)}">Open <i class="bi bi-arrow-right"></i></button>` : ''}</li>`).join('')}</ul></section>`;
  if (spec.cards && spec.cards.length) h += UI.cardGroups(spec.cards);
  const charts = spec.charts || [], max = opts.maxCharts || charts.length;
  if (charts.length) {
    h += `<div class="chart-grid">${charts.slice(0, max).map(UI.chartHtml).join('')}</div>`;
    if (charts.length > max) {
      const id = 'cm' + Math.random().toString(36).slice(2, 8);
      UI._more[id] = charts.slice(max);
      h += `<div class="chart-more no-print" data-chartmore="${id}"><button type="button" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart"></i> Show ${charts.length - max} more chart(s)</button></div>`;
    }
  }
  const tables = spec.tables || [];
  if (opts.stack || tables.length === 1) h += tables.map(t => UI.blockTable(t)).join('');
  else if (tables.length > 1) {
    const id = 'tp' + Math.random().toString(36).slice(2, 8);
    UI._pickers[id] = { tables, key: opts.key || '' };
    h += `<section class="panel" data-tpicker="${id}"></section>`;
  }
  return h;
};
UI._more = {}; UI._pickers = {};
UI.blockTable = (t, title = true) => UI.table(t.columns.map(([l, k, f]) => [l, k, f]), t.rows, { title: title ? UI.esc(t.title) + (t.page && UI.canGo(t.page) ? ` <a href="#${t.page}" class="panel-link">Open page <i class="bi bi-arrow-right"></i></a>` : '') : '', limit: t.limit || 10, empty: t.empty });
/** Metric strips; cards with a "group" get a small heading per group. */
UI.cardGroups = (cards) => {
  if (!cards.some(c => c.group)) return UI.cards(cards);
  const groups = [];
  cards.forEach(c => { const g = groups.find(x => x[0] === (c.group || '')); g ? g[1].push(c) : groups.push([c.group || '', [c]]); });
  return groups.map(([g, list]) => `<div class="metric-group">${g ? `<div class="metric-group-title">${UI.esc(g)}</div>` : ''}${UI.cards(list)}</div>`).join('');
};
/** Table picker (reports): pills of the tables that have rows, one line for the empty ones. */
UI.drawPicker = (box) => {
  const p = UI._pickers[box.dataset.tpicker]; if (!p) return;
  const full = p.tables.filter(t => (t.rows || []).length), empty = p.tables.filter(t => !(t.rows || []).length);
  const mem = 'tpick:' + App.current.page + ':' + p.key;
  let cur = sessionStorage.getItem(mem);
  if (!full.some(t => t.title === cur)) cur = full.length ? full[0].title : null;
  const t = full.find(x => x.title === cur);
  box.innerHTML = `${full.length ? `<div class="panel-head"><div class="pill-tabs mb-0">${full.map(x => `<button type="button" class="tab ${x === t ? 'active' : ''}" data-pick="${UI.esc(x.title)}">${UI.esc(x.title)} <span class="tab-count">${x.rows.length}</span></button>`).join('')}</div>
      ${t && t.page && UI.canGo(t.page) ? `<a href="#${t.page}" class="panel-link">Open page <i class="bi bi-arrow-right"></i></a>` : ''}</div>` : ''}
    <div class="panel-body flush">${t ? UI.blockTable(t, false).replace(/^<section class="panel"><div class="panel-body flush">/, '').replace(/<\/div><\/section>$/, '') : ''}</div>
    ${empty.length ? `<div class="picker-empty"><i class="bi bi-info-circle"></i> No records: ${empty.map(x => UI.esc(x.title)).join(', ')}.</div>` : ''}`;
  box.querySelectorAll('[data-pick]').forEach(b => b.onclick = () => { sessionStorage.setItem(mem, b.dataset.pick); box.dataset.drawn = ''; UI.drawPicker(box); UI.activate(box); });
  UI.activate(box);
};

/** Small list showing a computation (name, how, amount). */
UI.breakdown = (lines, total, totalLabel = 'Total') => `<table class="breakdown w-100">${(lines || []).map(l => `<tr><td>${UI.esc(l.name)}</td><td class="text-muted">${UI.esc(l.explain || '')}</td><td class="num">${UI.money(l.amount)}</td></tr>`).join('')}
  <tr class="total"><td colspan="2">${UI.esc(totalLabel)}</td><td class="num">${UI.money(total)}</td></tr></table>`;

/**
 * Fund Request form (PDF #12 Scenario A step 4 / B step 4).
 * The dispatcher picks the preset categories and may add other categories.
 * The total is shown while choosing; the server still takes the real preset amounts.
 * opts: { title, categories:[{name, amount}], selected:[names], extra:[{name, amount}], notes, action, payload, intro }
 */
UI.fundForm = (opts) => {
  const sel = new Set(opts.selected || []);
  const extra = (opts.extra || []).concat(Array(Math.max(0, 3 - (opts.extra || []).length)).fill({ name: '', amount: '' }));
  const cats = (opts.categories || []).map((c, i) => `<label class="d-flex justify-content-between border rounded px-2 py-1 mb-1">
      <span><input type="checkbox" class="form-check-input me-2 fund-cat" data-name="${UI.esc(c.name)}" data-amount="${+c.amount}" ${sel.has(c.name) ? 'checked' : ''}>${UI.esc(c.name)}</span>
      <span class="num">${UI.money(c.amount)}</span></label>`).join('') || '<div class="text-muted small">No preset categories yet. Ask the Admin to add them in Administration > Settings.</div>';
  const rows = extra.map((l, i) => `<div class="row g-2 mb-1 fund-extra">
      <div class="col-8"><input class="form-control form-control-sm" maxlength="80" placeholder="Other expense category" value="${UI.esc(l.name || '')}"></div>
      <div class="col-4"><input class="form-control form-control-sm" type="number" min="0" step="0.01" placeholder="Amount" value="${l.amount === '' ? '' : +l.amount}"></div></div>`).join('');
  const body = `${opts.intro ? `<p class="small text-muted">${opts.intro}</p>` : ''}
    <h6>Fund categories (preset amounts)</h6>${cats}
    <h6 class="mt-3">Other expense categories (optional)</h6>${rows}
    <div class="mt-2"><label class="form-label small">Notes</label><input class="form-control form-control-sm" name="notes" maxlength="255" value="${UI.esc(opts.notes || '')}"></div>
    <div class="fs-6 mt-3 text-end">Total requested fund: <b id="fundTotal">₱0.00</b></div>`;
  UI.modal(opts.title, body, async (d, form) => {
    const categories = [...form.querySelectorAll('.fund-cat:checked')].map(c => c.dataset.name);
    const extraLines = [...form.querySelectorAll('.fund-extra')].map(r => { const [n, a] = r.querySelectorAll('input'); return { name: n.value.trim(), amount: a.value }; }).filter(x => x.name || x.amount);
    if (!categories.length && !extraLines.length) throw new Error('Choose at least one fund category.');
    const r = await App.api(opts.action, { ...opts.payload, categories, extra: extraLines, notes: d.notes || '' });
    UI.result(r); if (opts.after) opts.after(r); else App.route();
  }, { submit: 'Submit Fund Request', onShow(w) {
    const total = () => {
      let t = 0;
      w.querySelectorAll('.fund-cat:checked').forEach(c => t += +c.dataset.amount);
      w.querySelectorAll('.fund-extra').forEach(r => { const a = +r.querySelectorAll('input')[1].value; if (a > 0) t += a; });
      w.querySelector('#fundTotal').textContent = UI.money(t);
    };
    w.addEventListener('input', total); w.addEventListener('change', total); total();
  } });
};

/** Print only the given HTML (forms, invoices). */
/** Puts the form in the print area and opens the print dialog once its images (logo, e-signatures) have loaded (max 3 s). */
UI.print = (html) => {
  const a = document.getElementById('printArea'); a.innerHTML = html;
  const imgs = [...a.querySelectorAll('img')].filter(i => !i.complete);
  const wait = imgs.map(i => new Promise(r => { i.addEventListener('load', r, { once: true }); i.addEventListener('error', r, { once: true }); }));
  Promise.race([Promise.all(wait), new Promise(r => setTimeout(r, 3000))]).then(() => setTimeout(() => window.print(), 60));
};
/**
 * Prints report sections (Reports page). meta = {title, period, filters}.
 * Includes the header (company, report, period, filters, who printed and when), cards, charts (as images) and full tables.
 */
UI.printReport = (sections, meta = {}) => {
  const e = UI.esc, u = App.me.user, co = (App.me.company || {}).name || '';
  const cards = (list) => (list || []).length ? `<div class="pr-cards">${list.map(c => `<div class="pr-card"><div class="pr-val">${c.format === 'money' ? UI.money(c.value) : e(typeof c.value === 'number' ? UI.num(c.value, 2) : c.value)}</div><div class="pr-lbl">${e(c.label)}</div>${c.hint ? `<div class="pr-hint">${e(c.hint)}</div>` : ''}</div>`).join('')}</div>` : '';
  const charts = (list) => (list || []).map(c => { const round = c.type === 'doughnut' || c.type === 'pie';
    const img = round ? UI.chartImage(c, 520, 300) : UI.chartImage(c); return img ? `<div class="pr-chart${round ? ' pr-half' : ''}"><div class="pr-h3">${e(c.title)}</div><img src="${img}" alt="${e(c.title)}">${c.how ? `<div class="pr-note">${e(c.how)}</div>` : ''}</div>` : ''; }).join('');
  const tables = (list) => {
    const full = (list || []).filter(t => (t.rows || []).length), empty = (list || []).filter(t => !(t.rows || []).length);
    return full.map(t => `<div class="pr-table"><div class="pr-h3">${e(t.title)} <span class="pr-count">(${t.rows.length})</span></div><table><thead><tr>${t.columns.map(([l, , f]) => `<th class="${f === 'money' ? 'num' : ''}">${e(l)}</th>`).join('')}</tr></thead>
      <tbody>${t.rows.map(r => `<tr>${t.columns.map(([, k, f]) => `<td class="${f === 'money' ? 'num' : ''}">${String(UI.fmt(f, r[k], r)).replace(/<(?!\/?(b|i|span)\b)[^>]+>/g, '')}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`).join('')
      + (empty.length ? `<div class="pr-note">No data: ${empty.map(t => e(t.title)).join(', ')}.</div>` : '');
  };
  const html = `<div class="print-report"><div class="pr-head"><div><div class="pr-co">${e(co)}</div><div class="pr-title">${e(meta.title || 'Report')}</div>
      <div class="pr-meta">Period: <b>${e(meta.period || '')}</b>${meta.filters ? ` · ${e(meta.filters)}` : ''}</div></div>
      <div class="pr-by">Printed by ${e(u.full_name)} (${e(u.role_label || u.role)})<br>${e(new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' }))}</div></div>
    ${sections.map((s, i) => `<section class="pr-section${i ? ' pr-break' : ''}"><h2>${e(s.title)}</h2>${s.note ? `<div class="pr-note">${e(s.note)}</div>` : ''}${cards(s.cards)}${charts(s.charts)}${tables(s.tables)}</section>`).join('')}</div>`;
  document.getElementById('printArea').innerHTML = html;
  setTimeout(() => window.print(), 50);
};

/** Options helpers for selects. */
UI.opts = (rows, labelFn, valueKey = 'id') => (rows || []).map(r => [r[valueKey], labelFn(r)]);
UI.list = (arr) => (arr || []).map(x => [x, x]);

/**
 * One-shot deep links (?open=, ?report=, ?request_for=): returns the values and removes them from the
 * address without reloading, so a later page refresh after saving does not open the same form again.
 */
UI.takeQuery = (ctx, ...keys) => {
  const out = {}; let changed = false;
  // WS_NAV_STATE_V1: one-shot keys already taken out by route() on workspace pages.
  keys.forEach(k => { if (ctx.once && ctx.once[k] !== undefined) { out[k] = ctx.once[k]; delete ctx.once[k]; } });
  keys.forEach(k => { if (ctx.query[k] !== undefined) { out[k] = ctx.query[k]; delete ctx.query[k]; changed = true; } });
  if (changed) {
    const qs = new URLSearchParams(ctx.query).toString();
    history.replaceState(null, '', '#' + ctx.page + (qs ? '?' + qs : ''));
    if (App.current) App.current.query = { ...ctx.query };
  }
  return out;
};
// ------------------------------------------------------------------ helpers added in the revision
/** Form modal that also sends the files of the form (uses App.apiForm). */
UI.formModalFiles = (title, fields, action, opts = {}) => UI.modal(title, (opts.intro ? `<div class="small text-muted mb-2">${opts.intro}</div>` : '') + UI.fields(fields), async (d, form, wrap) => {
  const payload = opts.transform ? opts.transform(d, form, wrap) : d;
  const r = await App.apiForm(action, { ...(opts.extra || {}), ...payload }, form);
  UI.result(r);
  if (opts.after) opts.after(r); else App.route();
}, opts);

/**
 * ONE simple filter modal per list (no permanent filter bar).
 * fields: same format as UI.fields; values come from the page query.
 * Returns a small bar: [Filter] button, the active filters as chips, and Reset.
 */
UI.filterModal = (ctx, fields, opts = {}) => {
  const id = 'flt' + Math.random().toString(36).slice(2, 8);
  const keys = fields.map(f => f.name);
  const active = keys.filter(k => ctx.query[k]);
  const reset = () => { const q = { ...ctx.query }; keys.forEach(k => delete q[k]); App.go(ctx.page, q); };
  const open = () => {
    const wrap = UI.modal('Filter records', UI.fields(fields.map(f => ({ ...f, value: ctx.query[f.name] ?? '' }))) + (opts.note ? `<div class="form-help mt-2">${UI.esc(opts.note)}</div>` : ''), async (d) => {
      if (d.from && d.to && d.from > d.to) throw new Error('End date must be on or after the start date.');
      const q = { ...ctx.query }; keys.forEach(k => { if (d[k]) q[k] = d[k]; else delete q[k]; });
      App.go(ctx.page, q);
    }, { size: '', submit: 'Apply filters', confirmBeforeSubmit: false, extraFooter: '<button type="button" class="btn btn-outline-danger btn-sm me-auto" data-freset>Clear filters</button>' });
    wrap.querySelector('[data-freset]').onclick = () => { bootstrap.Modal.getInstance(wrap).hide(); reset(); };
  };
  const label = (k) => { const f = fields.find(x => x.name === k); const o = (f.options || []).find(o => String(Array.isArray(o) ? o[0] : o) === String(ctx.query[k])); return `${f.label}: ${o ? (Array.isArray(o) ? o[1] : o) : ctx.query[k]}`; };
  UI._filters[id] = { open, reset };
  return `<div class="filter-summary ui-filter-toolbar" data-fid="${id}"><button type="button" class="btn btn-sm btn-outline-secondary" data-fopen><i class="bi bi-funnel"></i> Filter${active.length ? ` <span class="badge bg-warning text-dark">${active.length}</span>` : ''}</button>
    ${active.length ? active.map(k => `<span class="filter-chip">${UI.esc(label(k))}</span>`).join('') + '<button type="button" class="btn btn-link btn-sm p-0 ms-1" data-fclear>Reset</button>' : `<span>${UI.esc(opts.hint || 'Showing all records.')}</span>`}</div>`;
};

/**
 * STAGE_B_WORKSPACE_UI — general filters shown directly on the page (Truck Masterlist style): selects and dates that
 * change the page URL query at once, plus Reset. fields: [{name, label, options: [[value, label]] | [value], type: 'select'|'date'}].
 * Options must come from real loaded data. Works together with status tabs and the table search.
 */
UI.generalFilters = (ctx, fields) => {
  const keys = fields.map(f => f.name), active = keys.filter(k => ctx.query[k]);
  const ctl = (f) => {
    const v = ctx.query[f.name] ?? '';
    if (f.type === 'date') return `<input type="date" class="form-control form-control-sm" data-gf="${UI.esc(f.name)}" value="${UI.esc(v)}" aria-label="${UI.esc(f.label)}" title="${UI.esc(f.label)}">`;
    const opts = (f.options || []).map(o => Array.isArray(o) ? o : [o, o]);
    return `<select class="form-select form-select-sm" data-gf="${UI.esc(f.name)}" aria-label="${UI.esc(f.label)}"><option value="">${UI.esc(f.all || 'All ' + f.label.toLowerCase())}</option>
      ${opts.map(([val, lab]) => `<option value="${UI.esc(val)}" ${String(val) === String(v) ? 'selected' : ''}>${UI.esc(lab)}</option>`).join('')}</select>`;
  };
  return `<div class="ws-filters no-print" role="group" aria-label="Filters">${fields.map(ctl).join('')}${active.length ? `<button type="button" class="btn btn-link btn-sm ws-filter-reset" data-gf-reset="${UI.esc(keys.join(','))}"><i class="bi bi-x-circle"></i> Reset filters</button>` : ''}</div>`;
};
document.addEventListener('change', (e) => {
  const c = e.target.closest('[data-gf]'); if (!c || !App.current) return;
  const q = { ...App.current.query }; if (c.value) q[c.dataset.gf] = c.value; else delete q[c.dataset.gf];
  App.go(App.current.page, q);
});
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-gf-reset]'); if (!b || !App.current) return;
  const q = { ...App.current.query }; b.dataset.gfReset.split(',').forEach(k => delete q[k]); App.go(App.current.page, q);
});

UI._filters = {};
// One listener for every filter bar (works even when the bar is drawn later inside a tab).
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-fopen],[data-fclear]'); if (!b) return;
  const f = UI._filters[b.closest('[data-fid]')?.dataset.fid]; if (!f) return;
  if (b.hasAttribute('data-fopen')) f.open(); else f.reset();
});

/** Side drawer for record details (Bootstrap offcanvas). */
UI.drawer = (title, html, opts = {}) => {
  // Re-use the open drawer (refresh its content) so backdrops never stack up.
  let el = document.querySelector('.offcanvas.app-drawer');
  const head = `<div><h5 class="offcanvas-title mb-0">${title}</h5>${opts.subtitle ? `<div class="small text-muted">${opts.subtitle}</div>` : ''}</div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close details"></button>`;
  if (el) {
    el.querySelector('.offcanvas-header').innerHTML = head;
    el.querySelector('.offcanvas-body').innerHTML = html;
    el.querySelector('.offcanvas-body').scrollTop = 0;
    if (!el.classList.contains('show')) bootstrap.Offcanvas.getOrCreateInstance(el).show();
  } else {
    el = document.createElement('div');
    el.className = 'offcanvas offcanvas-end app-drawer'; el.tabIndex = -1;
    el.innerHTML = `<div class="offcanvas-header">${head}</div><div class="offcanvas-body">${html}</div>`;
    document.body.append(el);
    bootstrap.Offcanvas.getOrCreateInstance(el).show();
    el.addEventListener('hidden.bs.offcanvas', () => el.remove());
  }
  UI.activate(el);
  if (opts.onShow) opts.onShow(el);
  return el;
};
UI.closeDrawer = () => document.querySelectorAll('.offcanvas.app-drawer').forEach(o => bootstrap.Offcanvas.getInstance(o)?.hide());

/** Progress stepper: stages = [labels], current = index, opts.warn = text shown in red. */
UI.steps = (stages, current, opts = {}) => `<div class="wf-steps ${opts.compact ? 'wf-steps-compact' : ''}">${stages.map((s, i) => `<div class="wf-step ${i < current ? 'done' : ''} ${i === current ? 'now' : ''} ${i === current && opts.warn ? 'warn' : ''}">
    <span class="dot">${i < current ? '<i class="bi bi-check"></i>' : i + 1}</span><span class="lbl">${UI.esc(s)}</span></div>`).join('')}</div>`;

/** Label / value grid. pairs = [[label, html], ...] (values are already escaped / formatted). */
UI.kv = (pairs) => `<dl class="kv">${pairs.filter(Boolean).map(([k, v]) => `<dt>${UI.esc(UI.operationsLabel(k))}</dt><dd>${v === null || v === undefined || v === '' ? '—' : v}</dd>`).join('')}</dl>`;
UI.e = (v) => (v === null || v === undefined || v === '') ? '—' : UI.esc(v);

/* CHECKLIST_25_INLINE_DOCUMENT_PREVIEW_V1 */
/** Existing authenticated endpoint checks role/record permissions for every image and PDF. */
/* CHECKLIST_25_INLINE_DOCUMENT_PREVIEW_ALL_RECORDS_V3 */
/** Existing authenticated endpoint checks role/record permissions for every image and PDF. */
UI.docUrl = (id, inline = true) => 'index.php?api=document_download&id=' + encodeURIComponent(id) + (inline ? '&inline=1' : '');
/** In-list thumbnails: maintenance sees incident evidence immediately, without opening a file. */
UI.docs = (list, empty = 'No documents attached.') => (list || []).length ? `<ul class="doc-list doc-preview-list">${list.map(d => {
    const mime = String(d.mime_type || '');
    const image = mime.startsWith('image/');
    const pdf = mime === 'application/pdf';
    const id = Number(d.id);
    const fileName = UI.esc(d.original_filename);
    const preview = image
      ? `<button type="button" class="doc-thumb-button" data-doc-preview="${id}" data-doc-mime="${UI.esc(mime)}" data-doc-name="${fileName}" aria-label="Preview ${fileName}"><img class="doc-thumb" src="${UI.docUrl(id)}" alt="${fileName}" loading="lazy" decoding="async"></button>`
      : `<button type="button" class="doc-thumb-button doc-thumb-pdf" data-doc-preview="${id}" data-doc-mime="${UI.esc(mime)}" data-doc-name="${fileName}" aria-label="Preview ${fileName}"><i class="bi ${pdf ? 'bi-file-earmark-pdf' : 'bi-file-earmark'}" aria-hidden="true"></i><span>${pdf ? 'PDF' : 'FILE'}</span></button>`;
    return `<li class="doc-preview-item">${preview}<div class="doc-preview-info"><button type="button" class="doc-preview-name" data-doc-preview="${id}" data-doc-mime="${UI.esc(mime)}" data-doc-name="${fileName}">${fileName}</button><div class="small text-muted">${UI.esc(d.category)}${d.for ? ' · ' + UI.esc(d.for) : ''} · ${UI.datetime(d.created_at)}${d.uploader ? ' · ' + UI.esc(d.uploader) : ''}</div><a class="small" href="${UI.docUrl(id, false)}" download>Download</a></div></li>`;
  }).join('')}</ul>` : `<div class="text-muted small">${UI.esc(empty)}</div>`;
/** A single delegated handler also works for document lists created later in drawers. */
if (!UI._documentPreviewBound) {
  UI._documentPreviewBound = true;
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-doc-preview]');
    if (!button) return;
    event.preventDefault();
    const id = Number(button.dataset.docPreview);
    if (!Number.isSafeInteger(id) || id <= 0) return;
    const mime = button.dataset.docMime || '';
    const name = button.dataset.docName || 'Document';
    const url = UI.docUrl(id);
    const content = mime.startsWith('image/')
      ? `<img src="${url}" alt="${UI.esc(name)}" class="doc-full-image">`
      : mime === 'application/pdf'
        ? `<iframe src="${url}" title="${UI.esc(name)}" class="doc-pdf-frame"></iframe>`
        : `<div class="p-4 text-muted">Preview is not available for this file type. Use Download.</div>`;
    const wrap = UI.modal('Document preview', `<div class="doc-full-frame">${content}</div>`, null, {
      size: 'modal-xl',
      extraFooter: `<a class="btn btn-outline-secondary btn-sm" href="${url}" target="_blank" rel="noopener">Open in new tab</a> <a class="btn btn-primary btn-sm" href="${UI.docUrl(id, false)}" download>Download</a>`
    });
    // Browser's Bootstrap modal handles Escape and close button.
    wrap.querySelector('.modal-title').textContent = name;
  });
}

/** Section heading inside drawers. */
UI.h = (text, extra = '') => `<div class="drawer-h"><span>${UI.esc(UI.operationsLabel(text))}</span>${extra}</div>`;

// Shared presentation only: callers supply already-authorized records and server decisions.
UI.state = (kind, opts = {}) => {
  const states = { empty:['bi-inbox','No records found.'], loading:['bi-hourglass-split','Loading records…'], error:['bi-exclamation-triangle','Unable to load records.'], denied:['bi-shield-lock','Access denied.'], success:['bi-check-circle','Saved successfully.'] };
  const [icon, title] = states[kind] || states.error;
  return `<div class="ui-state ui-state-${UI.esc(kind)}" role="${['error','denied'].includes(kind)?'alert':'status'}"${kind==='loading'?' aria-busy="true"':''}><i class="bi ${icon}" aria-hidden="true"></i><div><strong>${UI.esc(opts.title||title)}</strong>${opts.message?`<p>${UI.esc(opts.message)}</p>`:''}${opts.actionHtml||''}</div></div>`;
};
UI.actionSummary = (items, opts = {}) => {
  const visible = (items||[]).filter(item => item && (item.count == null || Number(item.count)>0));
  return `<section class="ui-action-summary" aria-label="${UI.esc(opts.title||'Action required')}"><h2>${UI.esc(opts.title||'Action required')}</h2>${visible.length?'<div class="ui-action-list">'+visible.map(item=>{
    const go=item.go&&UI.canGo(item.go),tag=go?'a':'div';
    const level=['danger','warning','success'].includes(item.level)?item.level:'info';
    return `<${tag} class="ui-action-item ui-action-${level}"${go?` href="#${UI.esc(item.go.replace(/^#/,''))}"`:''}><span>${UI.esc(item.label||item.text)}</span>${item.count!=null?`<strong>${UI.num(item.count)}</strong>`:''}${go?'<i class="bi bi-chevron-right" aria-hidden="true"></i>':''}</${tag}>`;
  }).join('')+'</div>':UI.state('empty',{title:opts.empty||'No action items in the available records.'})}</section>`;
};
UI.workflow = (milestones, opts = {}) => `<section class="ui-workflow" aria-label="${UI.esc(opts.label||'Recorded workflow progress')}">${opts.status?`<div class="ui-workflow-status">${UI.badge(opts.status)}</div>`:''}<ol>${(milestones||[]).map(m=>{
  const state=['complete','current','pending','exception'].includes(m.state)?m.state:'pending';
  return `<li class="ui-workflow-${state}"><span class="ui-workflow-dot" aria-hidden="true">${state==='complete'?'✓':state==='exception'?'!':'•'}</span><div><b>${UI.esc(m.label)}</b><small>${UI.esc({complete:'Recorded',current:'Current',pending:'Not recorded',exception:'Needs attention'}[state])}${m.at?' · '+UI.datetime(m.at):''}</small>${m.note?`<span>${UI.esc(m.note)}</span>`:''}</div></li>`;
}).join('')}</ol></section>`;
UI.activity = (records, opts = {}) => !(records||[]).length?UI.state('empty',{title:opts.empty||'No recorded activity.'}):`<ol class="timeline ui-activity">${records.map(e=>`<li><div class="when">${e.created_at||e.completed_at?UI.datetime(e.created_at||e.completed_at):'Time not recorded'}${e.actor?' · '+UI.esc(e.actor):''}</div><b>${UI.esc(e.event_type||e.update_type||e.action||e.to_status||e.kind||'Recorded activity')}</b>${e.from_status!==e.to_status&&e.to_status?`<div>${UI.esc(e.from_status||'Not recorded')} → ${UI.esc(e.to_status)}</div>`:''}${e.notes||e.note||e.details?`<p>${UI.esc(e.notes||e.note||e.details)}</p>`:''}${e.mechanic?`<small>Mechanic: ${UI.esc(e.mechanic)}</small>`:''}${e.reference?`<small>${UI.esc(e.reference)}</small>`:''}</li>`).join('')}</ol>`;
UI.readiness = (checks, opts = {}) => `<section class="ui-readiness" aria-label="${UI.esc(opts.title||'Recorded readiness')}"><h3>${UI.esc(opts.title||'Recorded readiness')}</h3>${(checks||[]).length?'<ul>'+checks.map(c=>`<li class="ui-check-${c.state==='complete'?'complete':c.state==='blocked'?'blocked':'unknown'}"><i class="bi ${c.state==='complete'?'bi-check-circle':c.state==='blocked'?'bi-exclamation-circle':'bi-info-circle'}" aria-hidden="true"></i><span><b>${UI.esc(c.label)}</b>${c.detail?`<small>${UI.esc(c.detail)}</small>`:''}</span></li>`).join('')+'</ul>':'<p>No validation details are available.</p>'}<p class="ui-readiness-note">${UI.esc(opts.note||'Informational only. The server checks eligibility and permissions again when an action is submitted.')}</p></section>`;
UI.documentChecklist = (documents, requirements, opts = {}) => `<section class="ui-document-checklist" aria-label="Document readiness"><h3>${UI.esc(opts.title||'Document readiness')}</h3><ul>${(requirements||[]).map(rule=>{
  const files=(documents||[]).filter(d=>d.category===rule.category);
  const expired=files.length&&rule.expiryField&&opts.asOf&&files.every(d=>d[rule.expiryField]&&String(d[rule.expiryField]).slice(0,10)<opts.asOf);
  const state=rule.applicable===false?'Not Applicable':expired?'Expired':files.length?'Attached':'Missing';
  return `<li><span>${UI.esc(rule.label||rule.category)} <small>${rule.required?'Required': 'Optional'}</small></span><b class="${state==='Attached'?'text-success':rule.required&&state!=='Not Applicable'?'text-danger':'text-muted'}">${state}</b>${state==='Missing'?`<small>${UI.esc(rule.required?'Required for '+(rule.for||'the existing workflow')+'.':'Optional attachment; does not block completion.')}</small>`:''}${rule.applicable===false&&rule.reason?`<small>${UI.esc(rule.reason)}</small>`:''}</li>`;
}).join('')}</ul></section>`;
UI.tripDocuments = documents => UI.documentChecklist(documents,[
  ...['Waybill','Acknowledgement Receipt','Delivery Receipt'].map(category=>({category,required:true,for:'trip completion'})),
  {category:'Diesel PO',required:false},{category:'Supplier SOA',required:false}
]);
UI.fundProgress = fund => UI.workflow([
  {label:'Submitted',at:fund.created_at,state:fund.created_at?'complete':'pending'},
  {label:fund.status==='Rejected'?'Rejected':'Approved',at:fund.approved_at,state:fund.status==='Rejected'?'exception':fund.approved_at?'complete':'pending',note:fund.rejected_reason},
  {label:'Released',at:fund.released_at,state:fund.released_at?'complete':'pending'},
  {label:'Liquidated',at:fund.liquidated_at,state:fund.liquidated_at?'complete':'pending'}
],{status:fund.status,label:'Recorded fund progress'});
UI.tripProgress = (trip, data = {}) => {
  const attempt=Number(trip.attempt_number||1);
  const inspections=(data.inspections||[]).filter(i=>Number(i.attempt_number)===attempt&&Number(i.vehicle_id)===Number(trip.vehicle_id));
  const pre=inspections.find(i=>i.kind==='Pre-Dispatch'&&i.completed_at),arrival=inspections.find(i=>i.kind==='Arrival'&&i.completed_at);
  const exceptional=['Cancelled','Rejected','On Hold - Incident Reported','For Reschedule'].includes(trip.status);
  const milestones=[
    {label:'Created',at:trip.created_at,state:trip.created_at?'complete':'pending'},
    {label:'Resources assigned',state:trip.vehicle_id&&trip.driver_id?'complete':'pending',note:'Current recorded assignment; see history for previous attempts.'},
    {label:'Pre-dispatch · attempt '+attempt,at:pre?.completed_at,state:pre?(Number(pre.has_not_ok)?'exception':'complete'):'pending'},
    {label:'Dispatched',at:trip.dispatched_at,state:trip.dispatched_at?'complete':'pending'},
    {label:'Completed',at:trip.completed_at,state:trip.completed_at?'complete':'pending'},
    {label:'Arrival inspection / Truck release',at:arrival?.completed_at,state:arrival?(Number(arrival.has_not_ok)||Number(trip.awaiting_arrival)?'exception':'complete'):'pending',note:'Applies to the recorded Truck and attempt.'}
  ];
  // Never infer past completion merely from the current status. Preserve full multi-attempt history separately.
  if(exceptional){const first=milestones.find(m=>m.state==='pending');if(first)first.state='exception';}
  else { const current = {'Awaiting Resources':1,'Pre-Assigned':2,'Ready for Dispatch':3,'Dispatched':4,'In Transit':4,'Completed':5}[trip.status]; if(current!=null&&milestones[current].state==='pending')milestones[current].state='current'; }
  return UI.workflow(milestones,{status:trip.status,label:'Recorded trip milestones'});
};
/** Organize existing drawer nodes with UI.tabs; never clone actions, forms or document handlers. */
UI.drawerSections = (drawer, definitions, opts = {}) => {
  const body=drawer.querySelector('.offcanvas-body');if(!body||body.querySelector('[data-record-sections]'))return;
  const groups=definitions.map(([key,label,titles])=>({key,label,titles,nodes:[]}));
  const selector=opts.selector||'.drawer-h';let current=groups[0];
  [...body.childNodes].forEach(node=>{
    if(node.nodeType===1&&node.matches(selector)){const title=(node.querySelector(opts.titleSelector||'span')?.textContent||'').trim();current=groups.find(g=>g.titles.some(t=>title===UI.operationsLabel(t)||title.startsWith(UI.operationsLabel(t)+' (')))||groups[0];}
    current.nodes.push(node);
  });
  const host=document.createElement('div');host.dataset.recordSections='';host.className='ui-record-sections';body.replaceChildren(host);
  UI.tabs(host,groups.filter(g=>g.nodes.some(n=>n.nodeType===1||n.textContent.trim())).map(g=>[g.key,UI.esc(UI.operationsLabel(g.label)),panel=>panel.append(...g.nodes)]),groups[0].key,{key:opts.key||'record-details',forceActive:true,retainPanels:true});
};
/** Standardize an existing search/filter form while preserving its submit/change contract. */
UI.searchToolbar = (form) => {
  const search=form.querySelector('input[type=search]');if(!search||form.dataset.uiToolbar)return;form.dataset.uiToolbar='';
  form.classList.add('ui-search-form','oh-compact-filter');const label=search.closest('label');if(!label)return;
  const others=[...form.children].filter(node=>node!==label);
  [...label.childNodes].filter(n=>n.nodeType===Node.TEXT_NODE&&n.textContent.trim()).forEach(node=>{const span=document.createElement('span');span.className='visually-hidden';span.textContent=node.textContent;node.replaceWith(span);});
  const toolbar=document.createElement('div');toolbar.className='ui-search-toolbar oh-record-toolbar';label.classList.add('oh-search');
  if(!label.querySelector('.bi-search'))label.insertAdjacentHTML('afterbegin','<i class="bi bi-search" aria-hidden="true"></i>');
  toolbar.append(label);toolbar.insertAdjacentHTML('beforeend','<button type="submit" class="btn btn-primary">Search</button><button type="button" class="btn btn-outline-success" data-toggle-record-filters aria-expanded="false">Filters</button>');
  const panel=document.createElement('div');panel.className='ui-search-filters oh-record-filter-panel';panel.id='filters-'+UI.uuid();panel.hidden=true;panel.append(...others);panel.insertAdjacentHTML('beforeend','<button type="button" class="btn btn-primary" data-apply-record-filters>Apply Filters</button>');
  const toggle=toolbar.querySelector('[data-toggle-record-filters]');toggle.setAttribute('aria-controls',panel.id);toggle.onclick=()=>{panel.hidden=!panel.hidden;toggle.setAttribute('aria-expanded',String(!panel.hidden));};
  const chips=document.createElement('div');chips.className='ui-filter-indicators';chips.setAttribute('aria-label','Active filters');
  const refresh=()=>{chips.replaceChildren();[...form.elements].filter(e=>e.name&&e!==search&&e.value&&e.type!=='hidden').forEach(e=>{const chip=document.createElement('span');chip.className='filter-chip';const caption=e.closest('label')?.firstChild?.textContent?.trim()||e.name;chip.textContent=caption+': '+(e.tagName==='SELECT'?e.selectedOptions[0]?.textContent:e.value);chips.append(chip);});};
  const apply=()=>{refresh();form.dispatchEvent(new Event('change',{bubbles:true}));};
  panel.querySelector('[data-apply-record-filters]').onclick=apply;
  form.onsubmit=event=>{event.preventDefault();apply();};form.append(toolbar,panel,chips);form.addEventListener('change',refresh);refresh();
};

/**
 * Printable form with the company header used on every printout (PDF #13 formats).
 * opts.number = form number shown at the top right.
 */
UI.printForm = (title, body, opts = {}) => {
  const co = App.me.company || {};
  UI.print(`<div class="pf"><div class="pf-head">${UI.logoTag('pf-logo-img')}<div class="pf-head-text"><div class="pf-co">${UI.esc(co.name || 'R P MULAWIN TRUCKING SERVICE')}</div>
    <div class="pf-addr">${UI.esc(co.address || '')}</div></div>${opts.number ? `<div class="pf-no">No. <b>${UI.esc(opts.number)}</b></div>` : ''}</div>
    <div class="pf-title">${UI.esc(title)}</div>${body}
    <div class="pf-foot">Printed from Mulawin FleetOps by ${UI.esc(App.me.user.full_name)} on ${UI.esc(new Date().toLocaleString('en-PH'))}</div></div>`);
};
/**
 * Signature line. name = printed name; sig = user id whose saved e-signature should appear
 * above the line (the image loads only for users allowed to see it).
 */
/** Empty table rows so a printed list looks like the paper form (rows = columns count, upTo = total rows wanted). */
UI.pfBlank = (cols, have, upTo = 8) => Array.from({ length: Math.max(0, upTo - have) }, () => `<tr>${'<td>&nbsp;</td>'.repeat(cols)}</tr>`).join('');
UI.sign = (label, name = '', sig = null, recordType = null, recordId = null) => `<div class="pf-sign">${sig ? `<img class="pf-sig-img" src="index.php?api=signature&user=${encodeURIComponent(sig)}&record_type=${encodeURIComponent(recordType || '')}&record_id=${encodeURIComponent(recordId || '')}" alt="" onerror="this.remove()">` : ''}<div class="pf-line">${UI.esc(name)}</div><div class="pf-sl">${UI.esc(label)}</div></div>`;

// ------------------------------------------------------------------ e-signature (upload or draw)
/**
 * "My e-signature": upload a PNG/JPG image, or draw with mouse / finger / stylus.
 * Both save a real image for the signed-in user; empty drawings are refused.
 */
UI.signatureModal = () => {
  const u = App.me.user;
  const body = `<p class="modal-intro">Your signature is printed on the forms you prepare or approve (purchase orders, cash fund requests, incident reports, invoices).</p>
    ${u.has_signature ? `<div class="sig-current"><span class="small text-muted">Current signature</span><img src="index.php?api=signature&user=${u.id}&t=${Date.now()}" alt="Current signature"></div>` : ''}
    <div class="pill-tabs" role="tablist"><button type="button" class="tab active" data-sigtab="draw"><i class="bi bi-pencil"></i> Draw</button><button type="button" class="tab" data-sigtab="upload"><i class="bi bi-upload"></i> Upload image</button></div>
    <div data-sigpane="draw">
      <div class="sig-pad-wrap"><canvas class="sig-pad" width="560" height="180" aria-label="Signature drawing area"></canvas><span class="sig-hint">Sign here with your mouse, finger or stylus</span></div>
      <div class="d-flex justify-content-between align-items-center mt-2"><span class="small text-muted" data-sigstatus>Empty</span><button type="button" class="btn btn-sm btn-outline-secondary" data-sigclear><i class="bi bi-eraser"></i> Clear</button></div>
    </div>
    <div data-sigpane="upload" class="d-none">
      <label class="form-label" for="sigFile">Signature image (PNG or JPG, up to 2 MB)</label>
      <input class="form-control" type="file" id="sigFile" accept="image/png,image/jpeg">
      <div class="form-help">Use a clear photo or scan of your signature on white paper.</div>
    </div>
    <div class="sig-preview d-none"><span class="small text-muted">Preview</span><img alt="Signature preview"></div>`;
  UI.modal('My e-signature', body, async (d, form, wrap) => {
    const mode = wrap.querySelector('[data-sigtab].active').dataset.sigtab;
    let file = null;
    if (mode === 'draw') {
      if (!wrap._sig.inked()) throw new Error('Draw your signature first (the box is empty).');
      file = await wrap._sig.blob();
    } else {
      file = wrap.querySelector('#sigFile').files[0];
      if (!file) throw new Error('Choose an image file.');
      if (!/^image\/(png|jpeg)$/.test(file.type)) throw new Error('Only PNG or JPG images are accepted.');
      if (file.size > 2 * 1024 * 1024) throw new Error('The image is larger than 2 MB.');
    }
    const r = await App.apiForm('Admin.signature_upload', { method: mode }, null, { signature: file });
    UI.result(r); App.me.user.has_signature = true; App.shell(); App.route();
  }, { size: '', submit: 'Save signature', onShow: (wrap) => {
    const cv = wrap.querySelector('.sig-pad'), ctx2 = cv.getContext('2d');
    const status = wrap.querySelector('[data-sigstatus]'), hint = wrap.querySelector('.sig-hint');
    const prev = wrap.querySelector('.sig-preview'), prevImg = prev.querySelector('img');
    let drawing = false, strokes = 0, last = null;
    const pos = (e) => { const r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; };
    const showPreview = (src) => { prevImg.src = src; prev.classList.toggle('d-none', !src); };
    ctx2.lineWidth = 2.4; ctx2.lineCap = 'round'; ctx2.lineJoin = 'round'; ctx2.strokeStyle = '#111';
    cv.addEventListener('pointerdown', (e) => { drawing = true; last = pos(e); cv.setPointerCapture(e.pointerId); hint.style.display = 'none'; const er = wrap.querySelector('.form-error'); if (er) er.style.display = 'none'; e.preventDefault(); });
    wrap.querySelector('#sigFile').addEventListener('change', () => { const er = wrap.querySelector('.form-error'); if (er) er.style.display = 'none'; });
    cv.addEventListener('pointermove', (e) => { if (!drawing) return; const p = pos(e); ctx2.beginPath(); ctx2.moveTo(...last); ctx2.lineTo(...p); ctx2.stroke(); last = p; strokes++; });
    const end = () => { if (!drawing) return; drawing = false; status.textContent = strokes > 8 ? 'Signature drawn' : 'Keep drawing'; if (strokes > 8) showPreview(cv.toDataURL('image/png')); };
    cv.addEventListener('pointerup', end); cv.addEventListener('pointerleave', end);
    wrap.querySelector('[data-sigclear]').onclick = () => { ctx2.clearRect(0, 0, cv.width, cv.height); strokes = 0; status.textContent = 'Empty'; hint.style.display = ''; showPreview(''); };
    wrap._sig = {
      inked: () => { if (strokes <= 8) return false; const px = ctx2.getImageData(0, 0, cv.width, cv.height).data; let n = 0; for (let i = 3; i < px.length; i += 4) if (px[i] > 0) n++; return n > 150; },
      blob: () => new Promise(res => cv.toBlob(b => res(new File([b], 'signature.png', { type: 'image/png' })), 'image/png')),
    };
    wrap.querySelectorAll('[data-sigtab]').forEach(b => b.onclick = () => {
      wrap.querySelectorAll('[data-sigtab]').forEach(x => x.classList.toggle('active', x === b));
      wrap.querySelectorAll('[data-sigpane]').forEach(p => p.classList.toggle('d-none', p.dataset.sigpane !== b.dataset.sigtab));
      showPreview(b.dataset.sigtab === 'draw' && strokes > 8 ? cv.toDataURL('image/png') : '');
    });
    wrap.querySelector('#sigFile').onchange = (e) => { const f = e.target.files[0]; if (f && /^image\//.test(f.type)) { const rd = new FileReader(); rd.onload = () => showPreview(rd.result); rd.readAsDataURL(f); } else showPreview(''); };
  } });
};

/* RPM SHARED RECORD VIEW SWITCH V1 START */
/* Adds Cards/List choice to normal UI.table lists without changing backend queries,
 * row actions, search, or the existing paginator. Plain detail tables excluded.
 */
(() => {
  const oldTable = UI.table;
  const oldDraw = UI.drawTable;
  const oldActivate = UI.activate;
  const userKey = () => String(App.me?.user?.id ?? App.me?.user?.username ?? 'user');
  const prefKey = () => 'rpm:record-view:v1:' + userKey() + ':' + String(App.current?.page || 'records');
  const getPref = () => {
    try { return localStorage.getItem(prefKey()) === 'cards' ? 'cards' : 'list'; }
    catch (_) { return 'list'; }
  };
  const savePref = (value) => {
    try { localStorage.setItem(prefKey(), value); } catch (_) { /* private browsing */ }
  };
  // WS_DESIGN_V1: Maintenance / Accounting workspace pages always show one table (no manual List/Card switch).
  const eligible = (opts) => !opts.plain && opts.viewSwitch !== false && App.current?.page !== 'dashboard' && !UI.WS_PAGES.has(App.current?.page) && !UI.refPage();

  UI.table = (columns, rows, opts = {}) => {
    if (!eligible(opts)) return oldTable(columns, rows, opts);
    const marker = 'rpmview' + Math.random().toString(36).slice(2, 9);
    const actions = `<div class="rpm-record-controls no-print" data-rpm-controls="${marker}" aria-label="Record view and page size">
      <div class="rpm-record-toggle" role="group" aria-label="View records as">
        <button type="button" data-rpm-view="list" aria-pressed="false"><i class="bi bi-list-ul" aria-hidden="true"></i><span class="visually-hidden">List</span></button>
        <button type="button" data-rpm-view="cards" aria-pressed="false"><i class="bi bi-grid-3x2-gap" aria-hidden="true"></i><span class="visually-hidden">${UI.role('operations_head')||UI.isDispatcher()?'Grid':'Cards'}</span></button>
      </div>
      <label class="rpm-record-size-label">Per page <select class="form-select form-select-sm" data-rpm-size aria-label="Records per page"><option value="12">12</option><option value="24">24</option><option value="48">48</option></select></label>
    </div>`;
    const originalTools = opts.tools || '';
    const result = oldTable(columns, rows, { ...opts, tools: originalTools + actions });
    const t = Object.values(UI._tables).find(table => table.opts && table.opts.tools && table.opts.tools.includes(marker));
    if (t) {
      t.rpmView = 'list';
      t.rpmMarker = marker;
      t.limit = 12;
    }
    return result;
  };

  UI.drawTable = (id) => {
    oldDraw(id);
    const t = UI._tables[id];
    if (!t?.rpmMarker) return;
    const container = document.querySelector(`[data-tbody="${id}"]`);
    const control = document.querySelector(`[data-rpm-controls="${t.rpmMarker}"]`);
    if (!container) return;
    if (control) {
      control.querySelectorAll('[data-rpm-view]').forEach(button => {
        const active = button.dataset.rpmView === t.rpmView;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      const size = control.querySelector('[data-rpm-size]');
      if (size) size.value = String(t.limit);
    }
    if (t.rpmView !== 'cards' || !UI.tableRows(id).length) return;
    const table = container.querySelector('table.data-table');
    if (!table) return; // empty list is handled by existing table renderer
    const grid = document.createElement('div');
    grid.className = 'rpm-record-grid';
    const columns = t.columns;
    table.querySelectorAll('tbody tr[data-ri]').forEach(tr => {
      const r = t.rows[Number(tr.dataset.ri)];
      const cells = Array.from(tr.children);
      const card = document.createElement('article');
      card.className = 'rpm-record-card' + (t.opts.rowClick ? ' rpm-record-clickable' : '');
      if (t.opts.rowClick) { card.tabIndex = 0; card.setAttribute('role', 'button'); }
      const fields = document.createElement('div');
      fields.className = 'rpm-record-fields';
      columns.forEach(([label, , format], idx) => {
        if (idx >= 4 && !(UI.role('operations_head')||UI.isDispatcher())) return; // RPM CARD COMPACT V2: hide secondary fields; full details through View action. // Keep cards concise; the View action opens full details.
        const td = cells[idx];
        if (!td) return;
        const field = document.createElement('div');
        field.className = 'rpm-record-field' + (idx === 0 ? ' rpm-record-primary' : '') + (idx >= 4 ? ' rpm-record-extra' : '');
        const name = document.createElement('span');
        name.className = 'rpm-record-name';
        name.textContent = UI.columnLabel(label, format);
        const value = document.createElement('div');
        value.className = 'rpm-record-value';
        while (td.firstChild) value.appendChild(td.firstChild); // move nodes: existing action/listeners preserved
        field.append(name, value);
        fields.appendChild(field);
      });
      card.appendChild(fields);
      if (t.hasActions) {
        const actions = cells[cells.length - 1]?.querySelector('.row-actions');
        if (actions) {
          const area = document.createElement('div');
          area.className = 'rpm-record-actions';
          area.appendChild(actions); // move original bound buttons, not re-create them
          card.appendChild(area);
        }
      }
      if (t.opts.rowClick) {
        const open = () => t.opts.rowClick(r);
        card.addEventListener('click', e => {
          if (e.target.closest('a,button,input,select,textarea,label,summary,details')) return;
          open();
        });
        card.addEventListener('keydown', e => {
          if (e.target !== card) return;
          if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); }
        });
      }
      grid.appendChild(card);
    });
    table.replaceWith(grid);
  };

  UI.activate = (root) => {
    oldActivate(root);
    root.querySelectorAll('[data-rpm-controls]').forEach(ctrl => {
      if (ctrl.dataset.rpmBound) return;
      ctrl.dataset.rpmBound = '1';
      const marker = ctrl.dataset.rpmControls;
      const entry = () => Object.entries(UI._tables).find(([, t]) => t.rpmMarker === marker);
      ctrl.querySelectorAll('[data-rpm-view]').forEach(b => b.addEventListener('click', () => {
        const choice = b.dataset.rpmView;
        // Only this table responds to its own view switch.
        const result = entry();
        if (!result) return;
        const [id, t] = result;
        t.rpmView = choice;
        t.page = 0;
        UI.drawTable(id);
      }));
      ctrl.querySelector('[data-rpm-size]')?.addEventListener('change', e => {
        const result = entry();
        if (!result) return;
        const [id, t] = result;
        const count = Number(e.target.value);
        if (![12, 24, 48].includes(count)) return;
        t.limit = count;
        t.page = 0;
        UI.drawTable(id);
      });
    });
  };
})();
/* RPM SHARED RECORD VIEW SWITCH V1 END */
// Standard record presentation is opt-in by caller role, not an authorization decision.
(() => {
  const draw=UI.drawTable,activate=UI.activate;
  const scoped=()=>UI.role('operations_head')||UI.isDispatcher();
  const decorate=id=>{const box=document.querySelector('[data-tbody="'+id+'"]'),t=UI._tables[id];if(box&&t&&(!t.opts.plain||(UI.role('operations_head')&&box.closest('.app-drawer')&&!box.closest('form')&&!t.opts.dsServerPaged))){UI.standardTable(box.parentElement,{total:UI.tableRows(id).length});box.closest('.panel')?.classList.add('fleet-data-shell');}};
  UI.drawTable=id=>{draw(id);if(scoped())decorate(id);};
  UI.activate=root=>{activate(root);if(scoped())root.querySelectorAll('[data-tbody]').forEach(box=>decorate(box.dataset.tbody));};
})();


// Maps (UI.Map, UI.mapPicker, UI.routeMap) moved to assets/js/map.js (Checklist #5: OpenStreetMap + server-side road distance).

/** Payment fields used by Finance and Billing. */
UI.payFields = (methods, dateName = 'released_on', dateLabel = 'Release date') => [
  { name: 'payment_method', label: 'Payment method', type: 'select', options: UI.list(methods), required: true, col: 6 },
  { name: dateName, label: dateLabel, type: 'date', value: UI.today(), required: true, col: 6 },
  { name: 'reference_number', label: 'Reference no. (bank / e-wallet)', col: 4 },
  { name: 'cheque_number', label: 'Cheque no.', col: 4 }, { name: 'bank_name', label: 'Bank', col: 4 },
];


// Chart.js look that matches the design system.
if (typeof Chart !== 'undefined') {
  Chart.defaults.font.family = getComputedStyle(document.documentElement).getPropertyValue('--font-sans') || 'system-ui, sans-serif';
  Chart.defaults.font.size = 12;
  Chart.defaults.color = '#57606a';
  Chart.defaults.borderColor = '#e6e8eb';
}

/* REF_FIDELITY_V1 — Reset on reference lists clears the search and returns to the page's default tab and filters;
   Escape closes an open record drawer (as in the reference), unless a dialog is open on top of it. */
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-treset]'); if (!b || !App.current) return;
  const input = document.querySelector(`[data-tsearch="${b.dataset.treset}"]`);
  if (input && input.value) { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); }
  if (Object.keys(App.current.query || {}).length) App.go(App.current.page, {});
});
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape' || document.querySelector('.modal.show')) return;
  if (document.querySelector('.offcanvas.app-drawer.show')) UI.closeDrawer();
});
