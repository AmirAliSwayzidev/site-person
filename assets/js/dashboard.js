(async()=>{
 const user=await requireAuth(); if(!user)return;
 const labels={pending:'در حال تأیید',in_progress:'در حال انجام',completed:'تکمیل‌شده',cancelled:'لغوشده'};
 const pay={unpaid:'پرداخت نشده',pending:'در حال تأیید پرداخت',paid:'پرداخت موفق',failed:'ناموفق',refunded:'مرجوع‌شده'};
 $('#userName').textContent=user.name; $('#userEmail').textContent=user.email; $('#avatarInit').textContent=(user.name.trim()[0]||'؟');
 $('#pfName').value=user.name; $('#pfEmail').value=user.email; $('#pfPhone').value=user.phone||'—'; $('#pfJoined').value=new Date(user.created_at).toLocaleDateString('fa-IR');
 const orders=await myOrders();
 $('#statOrders').textContent=orders.length.toLocaleString('fa-IR');
 $('#statSpent').textContent=orders.filter(o=>o.payment_status==='paid').reduce((s,o)=>s+Number(o.amount||0),0).toLocaleString('fa-IR');
 $('#statRequests').textContent=orders.filter(o=>o.project_type!=='product').length.toLocaleString('fa-IR');
 $('#ordersList').innerHTML=orders.length?orders.map(o=>`<div class="order-row"><div><b>${escapeHTML(o.package_name||o.project_type||'سفارش')}</b><div class="hint">${escapeHTML((o.description||'').slice(0,120))}</div><div class="hint">شماره: ${escapeHTML(o.id)} · ${new Date(o.created_at).toLocaleDateString('fa-IR')}</div></div><div style="text-align:left"><span class="status ${o.status==='completed'?'done':o.status==='in_progress'?'progress':'pending'}">${labels[o.status]||o.status}</span><div class="hint">${pay[o.payment_status]||o.payment_status}</div></div></div>`).join(''):'<div class="card empty-state"><b>هنوز سفارشی ثبت نشده است.</b><br><br><a href="/request" class="btn btn-primary btn-sm">ثبت درخواست</a></div>';
 $('#requestsList').innerHTML=orders.filter(o=>o.project_type!=='product').map(o=>`<div class="order-row"><div><b>${escapeHTML(o.project_type||'پروژه')}</b><div class="hint">${escapeHTML(o.description||'')}</div></div><span class="status ${o.status==='completed'?'done':o.status==='in_progress'?'progress':'pending'}">${labels[o.status]||o.status}</span></div>`).join('')||'<div class="card empty-state">هنوز درخواست پروژه‌ای ثبت نشده است.</div>';
 $$('.dash-nav a[data-panel]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();$$('.dash-nav a[data-panel]').forEach(x=>x.classList.remove('active'));a.classList.add('active');$$('.dash-panel').forEach(p=>p.classList.remove('active'));$('#dp-'+a.dataset.panel).classList.add('active')}));
 $('#logoutLink')?.addEventListener('click',e=>{e.preventDefault();logoutUser()});
})().catch(e=>toast(e.message||'خطایی در بارگذاری حساب رخ داد.'));
const cpf=document.querySelector('#changePasswordForm');
if(cpf)cpf.onsubmit=async e=>{e.preventDefault();try{const currentPassword=$('#currentPassword').value,newPassword=$('#newPassword').value;if(newPassword.length<8)return toast('رمز جدید باید حداقل ۸ کاراکتر باشد.');await api('/api/auth/change-password',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({currentPassword,newPassword})});toast('رمز عبور با موفقیت تغییر کرد.');cpf.reset()}catch(err){toast(err.message)}};
