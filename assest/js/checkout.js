let selectedGateway = null;

async function renderCart(){
  const items = cartProducts();
  const list = $('#cartList');
  if(!list) return;

  if(!items.length){
    list.innerHTML = '<div class="empty-state"><b>سبد خرید شما خالی است</b><br><br><a href="/shop.html" class="btn btn-primary btn-sm">رفتن به فروشگاه</a></div>';
    const btn = $('#payBtn');
    if(btn) btn.setAttribute('disabled','disabled');
  }else{
    list.innerHTML = items.map(p => `
      <div class="cart-item">
        <div>
          <div class="name">${escapeHTML(p.title)}</div>
          <div class="cat">${escapeHTML(p.cat)}</div>
        </div>
        <div class="cart-item-actions">
          <span class="prod-price">${toman(p.price)}</span>
          <button type="button" class="btn btn-danger btn-sm" data-remove-item="${escapeHTML(p.id)}">حذف</button>
        </div>
      </div>`).join('');
    const btn = $('#payBtn');
    if(btn) btn.removeAttribute('disabled');
  }
  const sub = $('#sumSubtotal');
  const tot = $('#sumTotal');
  if(sub) sub.textContent = toman(cartTotal());
  if(tot) tot.textContent = toman(cartTotal());
  updateCartCount();
}

function removeItem(id){
  removeFromCartOnce(id);
  renderCart();
}

const cartListEl = $('#cartList');
if(cartListEl){
  cartListEl.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-remove-item]');
    if(btn) removeItem(btn.dataset.removeItem);
  });
}

$$('.gateway-option').forEach(el => el.addEventListener('click', () => {
  if(el.dataset.gw !== 'card') return;
  $$('.gateway-option').forEach(o => o.classList.remove('selected'));
  el.classList.add('selected');
  selectedGateway = 'card';
  const box = $('#cardPaymentBox');
  if(box) box.style.display = 'block';
}));

const payBtn = $('#payBtn');
if(payBtn){
  payBtn.addEventListener('click', async () => {
    if(!cartProducts().length) return;
    if(selectedGateway !== 'card'){
      toast('لطفاً روش کارت‌به‌کارت را انتخاب کنید.');
      return;
    }
    const btn = payBtn;
    btn.textContent = 'در حال ثبت سفارش...';
    btn.disabled = true;
    try{
      const u = await requireAuth();
      if(!u){
        btn.disabled = false;
        btn.textContent = 'ثبت سفارش و ارسال برای تأیید';
        return;
      }
      const items = cartProducts();
      const data = {
        packageName: 'خرید فروشگاه',
        projectType: 'product',
        description: 'خرید محصولات: ' + items.map(x => x.title).join('، '),
        budget: toman(cartTotal()),
        deadline: '—',
        features: [],
        contactName: u.name,
        contactPhone: u.phone || '',
        amount: cartTotal(),
        items: items.map(x => ({ id: x.id, title: x.title, price: x.price })),
        gateway: 'card'
      };
      const order = await submitRequest(data, null);
      $('#cartView').style.display = 'none';
      $('#paidView').style.display = 'block';
      $('#orderId').textContent = order.id;
      saveCart([]);
      updateCartCount();
    }catch(e){
      toast(e.message);
      btn.disabled = false;
      btn.textContent = 'ثبت سفارش و ارسال برای تأیید';
    }
  });
}

// Show cart immediately for everyone (auth only required at pay time)
renderCart();
