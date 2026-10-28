(async()=>{
  const labels={pending:'در حال تأیید',in_progress:'در حال انجام',completed:'تکمیل‌شده',cancelled:'لغوشده'};
  const paymentLabels={unpaid:'پرداخت نشده',pending:'در حال تأیید پرداخت',paid:'پرداخت موفق',failed:'ناموفق',refunded:'مرجوع‌شده'};
  const me=await api('/api/auth/me').catch(()=>null);
  if(!me?.user || me.user.role!=='admin'){goTo('/auth');return;}
  const currentAdminEmail=me.user.email;

  function fmtDate(v){try{return new Date(v).toLocaleString('fa-IR')}catch{return '—'}}
  function fmtMoney(v){return Number(v||0).toLocaleString('fa-IR')+' تومان'}
  function statusClass(s){return s==='completed'||s==='paid'?'done':s==='in_progress'?'progress':s==='pending'?'pending':''}

  async function load(){
    try{
      const [stats,or,ct,us]=await Promise.all([
        api('/api/admin/stats'),api('/api/admin/orders'),api('/api/admin/contacts'),api('/api/admin/users')
      ]);
      const s=stats.stats;
      $('#count').textContent=s.orders;
      $('#pending').textContent=s.pending_orders;
      $('#contacts').textContent=s.contacts;
      $('#usersCount').textContent=s.users;
      $('#completed').textContent=s.completed_orders;
      $('#revenue').textContent=fmtMoney(s.paid_amount);
      renderUsers(us.users);
      renderOrders(or.orders);
      renderMessages(ct.messages);
    }catch(e){toast(e.message||'بارگذاری اطلاعات مدیریت ناموفق بود.')}
  }

  function renderUsers(users){
    $('#users').innerHTML=users.length?users.map(u=>`
      <div class="order-row">
        <div style="min-width:0">
          <b>${escapeHTML(u.name)}</b> · ${escapeHTML(u.email)}
          <div class="hint">${escapeHTML(u.phone||'شماره ثبت نشده')} · ${u.role==='admin'?'مدیر':'کاربر'} · عضویت ${fmtDate(u.created_at)}</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <button class="btn btn-primary btn-sm view-user" data-id="${u.id}">مشخصات کامل</button>
          ${u.email===currentAdminEmail?'':`<button class="btn btn-ghost btn-sm reset-user" data-id="${u.id}">تغییر رمز</button><button class="btn btn-ghost btn-sm role-user" data-id="${u.id}" data-role="${u.role==='admin'?'user':'admin'}">${u.role==='admin'?'حذف دسترسی مدیر':'مدیر کردن'}</button>`}
        </div>
      </div>
      <div id="user-detail-${u.id}" class="detail-panel" hidden></div>
    `).join(''):'<div class="empty-state">هنوز کاربری ثبت نشده است.</div>';

    $$('.view-user').forEach(b=>b.onclick=()=>toggleUserDetail(b.dataset.id));
    $$('.reset-user').forEach(b=>b.onclick=async()=>{
      const p=prompt('رمز جدید را وارد کنید (حداقل ۸ کاراکتر):'); if(!p)return;
      try{await api('/api/admin/users/'+b.dataset.id+'/reset-password',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:p})});toast('رمز عبور کاربر تغییر کرد.')}catch(e){toast(e.message)}
    });
    $$('.role-user').forEach(b=>b.onclick=async()=>{
      if(!confirm('نقش این حساب تغییر کند؟'))return;
      try{await api('/api/admin/users/'+b.dataset.id+'/role',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({role:b.dataset.role})});toast('نقش حساب تغییر کرد.');load()}catch(e){toast(e.message)}
    });
  }

  async function toggleUserDetail(id){
    const box=$('#user-detail-'+id); if(!box)return;
    if(!box.hidden){box.hidden=true;return;}
    box.hidden=false;box.innerHTML='<span class="hint">در حال دریافت اطلاعات…</span>';
    try{
      const r=await api('/api/admin/users/'+encodeURIComponent(id));
      const u=r.user;
      const total=r.orders.reduce((a,o)=>a+Number(o.amount||0),0);
      const paid=r.orders.filter(o=>o.payment_status==='paid').reduce((a,o)=>a+Number(o.amount||0),0);
      box.innerHTML=`
        <h4>جزئیات ${escapeHTML(u.name)}</h4>
        <div class="admin-user-detail">
          <div class="admin-detail-item"><span>نام</span><b>${escapeHTML(u.name)}</b></div>
          <div class="admin-detail-item"><span>ایمیل</span><b dir="ltr">${escapeHTML(u.email)}</b></div>
          <div class="admin-detail-item"><span>موبایل</span><b dir="ltr">${escapeHTML(u.phone||'ثبت نشده')}</b></div>
          <div class="admin-detail-item"><span>نقش</span><b>${u.role==='admin'?'مدیر':'کاربر'}</b></div>
          <div class="admin-detail-item"><span>تاریخ عضویت</span><b>${fmtDate(u.created_at)}</b></div>
          <div class="admin-detail-item"><span>تعداد سفارش</span><b>${r.orders.length.toLocaleString('fa-IR')}</b></div>
          <div class="admin-detail-item"><span>مجموع سفارش‌ها</span><b>${fmtMoney(total)}</b></div>
          <div class="admin-detail-item"><span>پرداخت موفق</span><b>${fmtMoney(paid)}</b></div>
        </div>
        <h4 style="margin-top:22px">سفارش‌ها</h4>
        ${r.orders.length?r.orders.map(o=>`<div class="order-row"><div><b>${escapeHTML(o.package_name||o.project_type||'پروژه')}</b><div class="hint">${escapeHTML(o.description||'بدون توضیح')} · ${fmtDate(o.created_at)}</div><div class="hint">بودجه: ${escapeHTML(o.budget||'—')} · مهلت: ${escapeHTML(o.deadline||'—')} · پرداخت: ${paymentLabels[o.payment_status]||o.payment_status}</div>${o.attachment_original_name?`<div class="hint">فایل: ${escapeHTML(o.attachment_original_name)}</div>`:''}</div><span class="status ${statusClass(o.status)}">${labels[o.status]||o.status}</span></div>`).join(''):'<div class="empty-state">این کاربر هنوز سفارشی ثبت نکرده است.</div>'}
        <h4 style="margin-top:22px">پیام‌های تماس</h4>
        ${r.messages.length?r.messages.map(m=>`<div class="order-row"><div><b>${escapeHTML(m.name)}</b><div class="hint" dir="ltr">${escapeHTML(m.email)}</div><p>${escapeHTML(m.message)}</p></div><span class="hint">${fmtDate(m.created_at)}</span></div>`).join(''):'<div class="empty-state">پیام مستقیمی برای این حساب ثبت نشده است.</div>'}
        <h4 style="margin-top:22px">پرداخت‌ها</h4>
        ${r.payments.length?r.payments.map(p=>`<div class="order-row"><div><b>${escapeHTML(p.provider||'درگاه')}</b><div class="hint">سفارش: ${escapeHTML(p.order_id)} · ${fmtDate(p.created_at)}</div></div><div><span class="status ${statusClass(p.status)}">${escapeHTML(p.status||'—')}</span><div class="hint">${fmtMoney(p.amount)}</div></div></div>`).join(''):'<div class="empty-state">هنوز رکورد پرداختی برای این کاربر ثبت نشده است.</div>'}
      `;
    }catch(e){box.innerHTML='<span class="hint">'+escapeHTML(e.message)+'</span>'}
  }

  function renderOrders(orders){
    $('#orders').innerHTML=orders.length?orders.map(o=>`
      <div class="order-row">
        <div style="min-width:45%">
          <b>${escapeHTML(o.user_name||'کاربر')}</b> · <span class="hint" dir="ltr">${escapeHTML(o.user_email||'')}</span>
          <div class="hint">${escapeHTML(o.package_name||o.project_type||'پروژه')} — ${escapeHTML((o.description||'').slice(0,180))}</div>
          <div class="hint">مبلغ: ${fmtMoney(o.amount)} · پرداخت: ${paymentLabels[o.payment_status]||o.payment_status} · ${fmtDate(o.created_at)}</div>
          ${o.attachment_original_name?`<a href="/api/admin/orders/${encodeURIComponent(o.id)}/attachment" class="btn btn-ghost btn-sm" style="margin-top:8px">دریافت فایل</a>`:''}
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span class="status ${statusClass(o.status)}">${labels[o.status]||o.status}</span>
          <select data-id="${o.id}">
            <option value="pending">در انتظار بررسی</option><option value="in_progress">در حال انجام</option><option value="completed">تکمیل‌شده</option><option value="cancelled">لغوشده</option>
          </select>
        </div>
      </div>`).join(''):'<div class="empty-state">هنوز سفارشی ثبت نشده است.</div>';
    $$('#orders select').forEach(s=>{
      const original=orders.find(o=>o.id===s.dataset.id);s.value=original?.status||'pending';
      s.addEventListener('change',async()=>{try{await api('/api/admin/orders/'+encodeURIComponent(s.dataset.id),{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({status:s.value})});toast('وضعیت سفارش ذخیره شد.');load()}catch(e){toast(e.message)}})
    });
  }

  function renderMessages(messages){
    $('#messages').innerHTML=messages.length?messages.map(m=>`<div class="order-row"><div><b>${escapeHTML(m.name)}</b> · <span dir="ltr">${escapeHTML(m.email)}</span><p>${escapeHTML(m.message)}</p></div><span class="hint">${fmtDate(m.created_at)}</span></div>`).join(''):'<div class="empty-state">پیام جدیدی ثبت نشده است.</div>';
  }

  $('#createUserForm').onsubmit=async e=>{
    e.preventDefault();
    try{const r=await api('/api/admin/users',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name:$('#newName').value.trim(),email:$('#newEmail').value.trim(),phone:$('#newPhone').value.trim(),password:$('#newPassword').value,role:$('#newRole').value})});toast(`حساب ${r.user.email} ساخته شد.`);e.target.reset();load()}catch(e){toast(e.message)}
  };
  $('#logout').onclick=logoutUser;
  $('#runAgent').onclick=async()=>{
    const out=$('#agentOut');out.textContent='در حال تحلیل…';
    try{const r=await api('/api/admin/agent',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({prompt:$('#agentPrompt').value.trim()})});out.textContent=r.response}catch(e){out.textContent=e.message}
  };
  load();
})().catch(e=>{console.error(e);goTo('/auth')});
