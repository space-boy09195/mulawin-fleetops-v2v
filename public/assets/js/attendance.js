/* CHECKLIST_42_ATTENDANCE_PHASE1_V1: shared modal, no new navigation page. */
'use strict';
const AttendanceUI = {
  async open(kind) {
    const today = new Date();
    // Browser date in Philippine timezone regardless of device timezone.
    const date = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Manila',year:'numeric',month:'2-digit',day:'2-digit'}).format(today);
    // CHECKLIST_42_ATTENDANCE_PHASE2_V1: the 'Driver' roster now holds drivers AND helpers.
    const title = kind === 'Driver' ? 'Crew Attendance (Drivers and Helpers)' : `${kind} Attendance`;
    const body = `<div class="row g-2 mb-3"><div class="col-sm-6"><label class="form-label">Attendance date</label><input type="date" class="form-control" name="duty_date" value="${date}" max="${date}" required></div></div>
      <div data-att-unassigned></div>
      <div data-att-info class="small text-muted mb-2">Loading attendance...</div>
      <div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><th>Personnel</th><th class="text-center">Present</th><th class="text-center">Absent</th></tr></thead><tbody data-att-body></tbody></table></div>
      <p class="small text-muted">Blank = Not yet marked. Attendance does not automatically override operational availability.</p>`;
    let roster=[];
    UI.modal(title,body,async(d,form)=>{
      const items=roster.map(r=>({id:+r.id,status:form.querySelector(`input[name="att_${r.id}"]:checked`)?.value||null}));
      const result=await App.api('Attendance.save',{kind,duty_date:d.duty_date,items});
      UI.result(result); App.route();
    },{size:'modal-lg',submit:'Save attendance',onShow:(wrap,form)=>{
      const dateField=form.querySelector('[name="duty_date"]');
      const info=wrap.querySelector('[data-att-info]');
      const tbody=wrap.querySelector('[data-att-body]');
      let request=0;
      const reload=async()=>{
        const seq=++request;info.textContent='Loading attendance...';tbody.innerHTML='';roster=[];
        try {
          const result=await App.api('Attendance.list',{kind,duty_date:dateField.value});
          if(seq!==request)return;
          roster=result.rows||[];
          const rd=result.readiness, rt=(rd&&rd.trips)||[];
          const tripsWarn=rt.length?`<div class="alert alert-warning py-2 small mb-2"><b>${rt.length} upcoming trip(s) have crew marked Absent</b> (${UI.esc(rd.mode)} mode):<ul class="mb-0">${rt.slice(0,8).map(x=>`<li>${UI.esc(x.trip_ref)} · ${UI.datetime(x.scheduled_at)}: ${x.issues.map(UI.esc).join('; ')}</li>`).join('')}</ul></div>`:'';
          wrap.querySelector('[data-att-unassigned]').innerHTML=tripsWarn;
          tbody.innerHTML=roster.map(r=>`<tr><td>${UI.esc(r.full_name)}<div class="small text-muted">${r.person_type?UI.esc(r.person_type)+' · ':''}Availability: ${UI.esc(r.availability)}</div></td>
            ${['Present','Absent'].map(v=>`<td class="text-center"><label class="d-inline-flex align-items-center justify-content-center p-2" style="min-width:42px;cursor:pointer"><input type="radio" name="att_${+r.id}" value="${v}" ${r.status===v?'checked':''} aria-label="${UI.esc(r.full_name)} ${v}"></label></td>`).join('')}</tr>`).join('')||'<tr><td colspan="3" class="text-muted">No active staff in the masterlist.</td></tr>';
          info.textContent=`${result.marked} / ${result.total} marked · ${result.date} · Daily attendance. Click the selected option again to clear.`;
          tbody.querySelectorAll('input[type="radio"]').forEach(el=>{el.dataset.wasChecked=el.checked?'1':'0';el.addEventListener('click',()=>{if(el.dataset.wasChecked==='1'){el.checked=false;el.dataset.wasChecked='0';}else{tbody.querySelectorAll(`input[name="${el.name}"]`).forEach(x=>x.dataset.wasChecked='0');el.dataset.wasChecked='1';}});});
        } catch(e) {if(seq===request)info.textContent=e.message;}
      };
      dateField.addEventListener('change',reload);reload();
    }});
  }
};
