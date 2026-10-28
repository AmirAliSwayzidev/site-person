function renderGrid(cat){
  const grid = $('#productGrid');
  const list = cat === 'all' ? PRODUCTS : PRODUCTS.filter(p => p.cat === cat);
  grid.innerHTML = list.map(p => `
    <div class="card prod-card">
      <div class="prod-thumb">${p.icon}</div>
      <div class="prod-body">
        <span class="cat">${p.cat === 'web' ? 'وب‌سایت' : p.cat === 'bot' ? 'ربات تلگرام' : 'بازی'}</span>
        <h3>${p.title}</h3>
        <p>${p.desc}</p>
        <div class="prod-foot">
          <span class="prod-price">${toman(p.price)}</span>
          <button type="button" class="btn btn-primary btn-sm" data-add-to-cart="${p.id}">افزودن به سبد</button>
        </div>
      </div>
    </div>
  `).join('');
}

$$('.filter-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    $$('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    renderGrid(btn.dataset.cat);
  });
});

renderGrid('all');

$('#productGrid').addEventListener('click', (e) => {
  const btn = e.target.closest('[data-add-to-cart]');
  if (btn) addToCart(btn.dataset.addToCart);
});
