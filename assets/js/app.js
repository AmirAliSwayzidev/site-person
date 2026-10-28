/* amirali.dev frontend API + auth + cart layer */
const STATIC_PORTS = ['5500','5501','5502','5503'];
const isStaticPreview = STATIC_PORTS.includes(location.port) || location.protocol === 'file:';
// PHP-only build: never call the removed Node.js API. When opened through the PHP
// development server the API is same-origin; static previews can point to PHP on 8000.
const API_BASE = (isStaticPreview && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1')
  ? 'http://localhost:8000' : (isStaticPreview ? 'http://localhost:8000' : '');
function escapeHTML(value){return String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
const $ = (sel, ctx=document) => ctx.querySelector(sel);
const $$ = (sel, ctx=document) => [...ctx.querySelectorAll(sel)];
function toast(msg){let t=$('#toast');if(!t){t=document.createElement('div');t.id='toast';t.className='toast';document.body.appendChild(t)}t.textContent=msg;t.classList.add('show');clearTimeout(t._timer);t._timer=setTimeout(()=>t.classList.remove('show'),3500)}
async function api(url, options={}){
  const controller=new AbortController(); const timer=setTimeout(()=>controller.abort(),15000);
  try{const target=/^https?:\/\//.test(url)?url:API_BASE+url; const r=await fetch(target,{credentials:'include',...options,signal:controller.signal}); const text=await r.text(); let data={}; try{data=text?JSON.parse(text):{}}catch{data={error:text}}; if(!r.ok){const e=new Error(data.error||`خطای API (${r.status})`);e.status=r.status;throw e} return data}
  catch(e){if(e.name==='AbortError')throw new Error('پاسخ سرور بیش از حد طول کشید. مطمئن شوید سرور PHP در حال اجراست.');if(e instanceof TypeError)throw new Error('ارتباط با سرور برقرار نشد. سرور PHP را روی http://localhost:8000 اجرا کنید.');throw e}
  finally{clearTimeout(timer)}
}
// Root-relative "pretty" pages (/auth, /dashboard, ...) only exist because
// router.php / .htaccess rewrite them to the matching .html file. That
// rewriting is only in effect when the PHP server itself serves the page.
// If the project is instead opened through a plain static file server (e.g.
// the VS Code "Live Server" extension on port 5500), those routes 404 and
// login/register/logout look "broken" even though the API call succeeded.
// goTo() sends the browser to the same PHP origin that api() already uses,
// so navigation and API calls always agree on where the backend lives.
function goTo(path){location.href=(API_BASE||'')+path}
async function getCurrentUser(){try{return (await api('/api/auth/me')).user}catch{return null}}
async function requireAuth(){const u=await getCurrentUser();if(!u){toast('برای ادامه ابتدا وارد حساب شوید.');setTimeout(()=>goTo('/auth'),250);return null}return u}
async function logoutUser(){try{await api('/api/auth/logout',{method:'POST'})}catch(e){toast(e.message)}finally{goTo('/auth')}}
// Rewrite plain <a href="/..."> links to the pretty routes so they also work
// during a static preview, instead of only fixing JS-driven redirects.
function fixInternalLinks(){
  if(!isStaticPreview) return;
  const prettyPaths=new Set(['/','/auth','/dashboard','/admin','/shop','/request','/checkout','/index.html','/auth.html','/dashboard.html','/admin.html','/shop.html','/request.html','/checkout.html']);
  $$('a[href^="/"]').forEach(a=>{
    const href=a.getAttribute('href');
    const path=href.split(/[?#]/)[0];
    if(prettyPaths.has(path)) a.setAttribute('href',API_BASE+href);
  });
}
async function myOrders(){return (await api('/api/orders')).orders||[]}
async function submitContact(data){return await api('/api/contact',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})}
async function submitRequest(data,file){const fd=new FormData();Object.entries(data).forEach(([k,v])=>fd.append(k,Array.isArray(v)?JSON.stringify(v):v??''));if(file)fd.append('attachment',file);const r=await api('/api/orders',{method:'POST',body:fd});return r.order}
const PRODUCTS=[
 {id:'landing-kit',title:'وبسایت محصولات دیجیتال',cat:'web',price:40000000,icon:'◧',desc:'وبساییت کامل با پنل مدیریت درگاه پرداخت انیمیشن های 3 بعدی '},
 {id:'shop-kit',title:'بات دانلود فیلم',cat:'bot',price:3000000,icon:'⌘',desc:'ربات دانلود از اینستا یوتیوب تیک تاک'},
 {id:'telegram-bot',title:'بات بازی',cat:'bot',price:5000000,icon:'✈',desc:'بازی مافیا با دوستان انلاینت بازی کن '},
 {id:'game-kit',title:'بازی ران بی پایان',cat:'game',price:13000000,icon:'▣',desc:'بازی مشابه با subway surf با خروجی PC|ANDROID'}
];
function readCart(){try{const x=JSON.parse(localStorage.getItem('amirali_cart')||'[]');return Array.isArray(x)?x:[]}catch{return []}}
function saveCart(items){localStorage.setItem('amirali_cart',JSON.stringify(items));updateCartCount()}
function cartProducts(){return readCart().map(id=>PRODUCTS.find(p=>p.id===id)).filter(Boolean)}
function cartTotal(){return cartProducts().reduce((s,p)=>s+p.price,0)}
function addToCart(id){const cart=readCart();if(!cart.includes(id)){cart.push(id);saveCart(cart);toast('محصول به سبد اضافه شد.')}else toast('این محصول قبلاً در سبد است.')}
function removeFromCartOnce(id){saveCart(readCart().filter(x=>x!==id))}
function updateCartCount(){const el=$('#cartCount');if(!el)return;const n=readCart().length;el.textContent=n;el.style.display=n?'inline-flex':'none'}
function toman(n){return Number(n||0).toLocaleString('fa-IR')+' تومان'}
async function placeOrder(gateway='manual'){
  const u=await requireAuth();if(!u)throw new Error('AUTH_REQUIRED'); const items=cartProducts();if(!items.length)throw new Error('سبد خرید خالی است.');
  const data={packageName:'خرید فروشگاه',projectType:'product',description:'خرید محصولات: '+items.map(x=>x.title).join('، '),budget:toman(cartTotal()),deadline:'—',features:[],contactName:u.name,contactPhone:u.phone||'',amount:cartTotal(),items:items.map(x=>({id:x.id,title:x.title,price:x.price})),gateway};
  return submitRequest(data,null);
}

async function renderNavAuth(){
  const slots=$$('[id="navAuth"]');
  if(!slots.length) return;
  let user=null;
  try{ user=await getCurrentUser(); }catch{}
  slots.forEach(el=>{
    if(user){
      const adminLink=user.role==='admin' ? `<a href="${API_BASE}/admin" class="btn btn-primary btn-sm">مدیریت سایت</a>` : '';
      el.innerHTML=adminLink+`<a href="${API_BASE}/dashboard" class="btn btn-ghost btn-sm">حساب کاربری</a>`;
    }else{
      el.innerHTML=`<a href="${API_BASE}/auth" class="btn btn-primary btn-sm">ورود / ثبت‌نام</a>`;
    }
  });
}

updateCartCount();
renderNavAuth();
fixInternalLinks();
window.$=$;window.$$=$$;window.escapeHTML=escapeHTML;window.api=api;window.toast=toast;window.requireAuth=requireAuth;window.logoutUser=logoutUser;window.myOrders=myOrders;window.submitContact=submitContact;window.submitRequest=submitRequest;window.PRODUCTS=PRODUCTS;window.toman=toman;window.addToCart=addToCart;window.cartProducts=cartProducts;window.cartTotal=cartTotal;window.removeFromCartOnce=removeFromCartOnce;window.saveCart=saveCart;window.placeOrder=placeOrder;
