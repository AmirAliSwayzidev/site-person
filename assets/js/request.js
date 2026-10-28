(async () => {
  let user = null;
  try { user = await getCurrentUser(); } catch (_) {}

  if (user) {
    if ($('#contactName')) $('#contactName').value = user.name || '';
    if ($('#contactPhone')) $('#contactPhone').value = user.phone || '';
    if ($('#contactEmail')) $('#contactEmail').value = user.email || '';
  }

  const params = new URLSearchParams(location.search);
  const packageName = params.get('package');
  if (packageName) toast('بسته «' + packageName + '» برای سفارش انتخاب شد');

  $$('.chip').forEach(chip => {
    const input = chip.querySelector('input');
    if (!input) return;
    chip.addEventListener('click', () => {
      if (input.type === 'radio') {
        $$('#projectTypeGroup .chip').forEach(c => c.classList.remove('checked'));
      }
      setTimeout(() => chip.classList.toggle('checked', input.checked), 0);
    });
  });

  function valid() {
    if (!$('input[name="projectType"]:checked')) {
      toast('لطفاً نوع پروژه را انتخاب کنید');
      return false;
    }
    const desc = ($('#description') && $('#description').value || '').trim();
    if (desc.length < 8) {
      const f = $('#description') && $('#description').closest('.field');
      if (f) f.classList.add('invalid');
      toast('لطفاً توضیح کوتاهی درباره پروژه بنویسید');
      return false;
    }
    const email = ($('#contactEmail') && $('#contactEmail').value || '').trim();
    if (!/\S+@\S+\.\S+/.test(email)) {
      const f = $('#contactEmail') && $('#contactEmail').closest('.field');
      if (f) f.classList.add('invalid');
      toast('لطفاً یک ایمیل معتبر وارد کنید');
      return false;
    }
    if (!(($('#contactName') && $('#contactName').value) || '').trim()) {
      toast('لطفاً نام خود را وارد کنید');
      return false;
    }
    return true;
  }

  const btn = document.getElementById('submitBtn');
  if (!btn) {
    console.error('submitBtn not found');
    return;
  }

  btn.addEventListener('click', async function () {
    if (!user) {
      try { user = await getCurrentUser(); } catch (_) {}
    }
    if (!user) {
      toast('برای ثبت درخواست ابتدا وارد حساب شوید');
      setTimeout(function () { goTo('/auth'); }, 600);
      return;
    }

    if (!valid()) return;

    const att = document.getElementById('attachment');
    const f = att && att.files && att.files[0];
    if (f && f.size > 8 * 1024 * 1024) {
      toast('حجم فایل نباید بیشتر از ۸ مگابایت باشد');
      return;
    }

    const typeEl = document.querySelector('input[name="projectType"]:checked');
    const data = {
      packageName: packageName || '',
      projectType: typeEl ? typeEl.value : '',
      budget: ($('#budget') && $('#budget').value) || '',
      deadline: ($('#timeline') && $('#timeline').value) || '',
      description: ($('#description') && $('#description').value || '').trim(),
      features: $$('#featuresGroup input:checked').map(function (i) { return i.value; }),
      contactName: ($('#contactName') && $('#contactName').value || '').trim(),
      contactPhone: ($('#contactPhone') && $('#contactPhone').value || '').trim(),
      contactEmail: ($('#contactEmail') && $('#contactEmail').value || '').trim()
    };

    btn.disabled = true;
    btn.textContent = 'در حال ثبت...';

    try {
      const order = await submitRequest(data, f);
      const formCard = document.getElementById('formCard');
      const doneCard = document.getElementById('doneCard');
      const reqId = document.getElementById('reqId');
      if (formCard) formCard.style.display = 'none';
      if (doneCard) doneCard.style.display = 'block';
      if (reqId) reqId.textContent = (order && order.id) ? order.id : '—';
      toast('✓ درخواست شما با موفقیت ثبت شد');
    } catch (e) {
      console.error(e);
      toast((e && e.message) || 'خطا در ثبت درخواست. دوباره تلاش کنید.');
      btn.disabled = false;
      btn.textContent = 'ثبت درخواست';
    }
  });
})();
