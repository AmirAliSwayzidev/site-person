(()=>{
  const msg=$('#authMsg');
  const state={resetEmail:'',resetToken:'',resendUntil:0};
  function show(text,ok=false){if(!msg)return;msg.textContent=text||'';msg.className='auth-msg '+(text?(ok?'ok':'error'):'');}
  function setBusy(btn,busy,label){if(!btn)return;btn.disabled=busy;if(busy){btn.dataset.label=btn.textContent;btn.textContent='در حال پردازش…'}else btn.textContent=label||btn.dataset.label||btn.textContent;}
  function activateTab(tab){$$('.auth-tab').forEach(x=>x.classList.toggle('active',x.dataset.tab===tab));$$('.auth-panel').forEach(x=>x.classList.toggle('active',x.id==='panel-'+tab));show('');}
  function activateForgotStep(id){$$('#panel-forgot .auth-step').forEach(x=>x.classList.toggle('active',x.id===id));}
  $$('.auth-tab').forEach(t=>t.addEventListener('click',()=>activateTab(t.dataset.tab)));
  $$('.password-toggle').forEach(btn=>btn.addEventListener('click',()=>{const input=$('#'+btn.dataset.target);if(!input)return;const visible=input.type==='text';input.type=visible?'password':'text';btn.textContent=visible?'نمایش':'پنهان';}));

  $('#loginBtn')?.addEventListener('click',async()=>{
    const email=$('#loginEmail').value.trim(),password=$('#loginPass').value;
    if(!email||!password)return show('ایمیل و رمز عبور را وارد کنید.');
    const b=$('#loginBtn');setBusy(b,true);
    try{const r=await api('/api/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,password})});goTo(r.user.role==='admin'?'/admin':'/dashboard');}
    catch(e){show(e.message)}finally{setBusy(b,false,'ورود به حساب')}
  });

  async function submitSignup(){
    const name=$('#suName')?.value.trim()||'';
    const phone=$('#suPhone')?.value.trim()||'';
    const email=$('#suEmail')?.value.trim().toLowerCase()||'';
    const password=$('#suPass')?.value||'';
    const confirm=$('#suPassConfirm')?.value||'';
    if(name.length<2) return show('نام و نام‌خانوادگی را وارد کنید.');
    if(!phone) return show('شماره موبایل را وارد کنید.');
    const phoneDigits = phone.replace(/[\s\-\(\)]/g,'').replace(/^(\+98|98|0)/,'');
    if(!/^9\d{9}$/.test(phoneDigits)) return show('شماره موبایل را به شکل 09xxxxxxxxx یا +989xxxxxxxxx وارد کنید.');
    const normalizedPhone = '0' + phoneDigits;
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return show('ایمیل معتبر وارد کنید.');
    if(password.length<8) return show('رمز عبور باید حداقل ۸ کاراکتر باشد.');
    if(password!==confirm) return show('تکرار رمز عبور با رمز اصلی یکسان نیست.');
    const b=$('#completeSignupBtn'); setBusy(b,true);
    try {
      const r=await api('/api/auth/register',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({name,email,phone:normalizedPhone,password})});
      if(!r || !r.user) throw new Error('ثبت‌نام انجام نشد؛ پاسخ نامعتبر از سرور دریافت شد.');
      show('حساب با موفقیت ساخته شد. در حال ورود…',true);
      goTo(r.user.role==='admin'?'/admin':'/dashboard');
    } catch(e) {
      show(e?.message || 'ثبت‌نام انجام نشد. دوباره تلاش کنید.');
    } finally { setBusy(b,false,'ساخت حساب'); }
  }
  $('#completeSignupBtn')?.addEventListener('click',submitSignup);
  $('#signupForm')?.addEventListener('submit',e=>{e.preventDefault();submitSignup();});

  $('#forgotPasswordLink')?.addEventListener('click',e=>{e.preventDefault();activateTab('forgot');activateForgotStep('forgotStepEmail');$('#forgotEmail').value=$('#loginEmail').value.trim();});
  $('#backToLoginBtn')?.addEventListener('click',()=>activateTab('login'));
  $('#sendResetCodeBtn')?.addEventListener('click',async()=>{
    const email=$('#forgotEmail').value.trim();if(!email)return show('ایمیل حساب را وارد کنید.');state.resetEmail=email;const b=$('#sendResetCodeBtn');setBusy(b,true);
    try{await api('/api/auth/send-code',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email,purpose:'reset'})});activateForgotStep('forgotStepCode');show('کد بازیابی به ایمیل شما ارسال شد.',true);$('#resetCode').focus();}
    catch(e){show(e.message)}finally{setBusy(b,false,'ارسال کد بازیابی')}
  });
  $('#backToResetEmailBtn')?.addEventListener('click',()=>activateForgotStep('forgotStepEmail'));
  $('#verifyResetCodeBtn')?.addEventListener('click',async()=>{
    const code=$('#resetCode').value.replace(/\D/g,'');if(code.length!==5)return show('کد ۵ رقمی را کامل وارد کنید.');const b=$('#verifyResetCodeBtn');setBusy(b,true);
    try{const r=await api('/api/auth/verify-code',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:state.resetEmail,code,purpose:'reset'})});state.resetToken=r.token;activateForgotStep('forgotStepPassword');show('کد تأیید شد. رمز جدید را انتخاب کنید.',true);$('#newPass').focus();}
    catch(e){show(e.message)}finally{setBusy(b,false,'تأیید کد')}
  });
  $('#resetPasswordBtn')?.addEventListener('click',async()=>{
    const password=$('#newPass').value,confirm=$('#newPassConfirm').value;if(password.length<8)return show('رمز جدید باید حداقل ۸ کاراکتر باشد.');if(password!==confirm)return show('تکرار رمز جدید یکسان نیست.');if(!state.resetToken)return show('فرایند بازیابی معتبر نیست.');const b=$('#resetPasswordBtn');setBusy(b,true);
    try{const r=await api('/api/auth/reset-password',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({resetToken:state.resetToken,newPassword:password})});show('رمز با موفقیت تغییر کرد.');setTimeout(()=>goTo(r.user.role==='admin'?'/admin':'/dashboard'),500)}catch(e){show(e.message)}finally{setBusy(b,false,'ذخیره رمز جدید')}
  });
})();