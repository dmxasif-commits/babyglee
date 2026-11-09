(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
const BG = window.BG || {}, PR = window.BG_PRODUCTS || {};
const fmt = n => '৳' + Number(n).toLocaleString('en-US');
const page = document.body.dataset.page;
const toastEl = () => $('#toast');
let toastT;
const toast = m => { const t = toastEl(); if (!t) return; t.textContent = m; t.classList.add('on'); clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('on'), 2200); };

/* ---------- mobile menu ---------- */
const burger = $('#burger'), nav = $('#nav');
if (burger) {
  const set = o => { nav.classList.toggle('open', o); burger.setAttribute('aria-expanded', o); };
  burger.addEventListener('click', () => set(!nav.classList.contains('open')));
  $$('#nav a').forEach(a => a.addEventListener('click', () => set(false)));
  document.addEventListener('keydown', e => e.key === 'Escape' && set(false));
}

/* ---------- copy-link buttons (lp/index.html) ---------- */
$$('[data-copy]').forEach(b => b.addEventListener('click', async () => {
  const url = b.dataset.copy;
  try { await navigator.clipboard.writeText(url); }
  catch (e) { const ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (x) {} document.body.removeChild(ta); }
  const old = b.textContent; b.textContent = 'Copied!'; toast('Link copied'); setTimeout(() => b.textContent = old, 1500);
}));

/* ---------- ship-to selector ---------- */
const shipBtn = $('#shipBtn'), shipMenu = $('#shipMenu');
if (shipBtn) {
  const setShip = o => { shipMenu.hidden = !o; shipBtn.setAttribute('aria-expanded', o); };
  shipBtn.addEventListener('click', e => { e.stopPropagation(); setShip(shipMenu.hidden); });
  document.addEventListener('click', e => { if (!shipMenu.hidden && !shipMenu.contains(e.target)) setShip(false); });
  document.addEventListener('keydown', e => e.key === 'Escape' && setShip(false));
}

/* ---------- 3D tilt (pointer devices only) ---------- */
if (matchMedia('(hover:hover) and (pointer:fine)').matches) {
  $$('.tilt').forEach(el => {
    el.addEventListener('pointermove', e => {
      const r = el.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      el.style.transform = `rotateY(${x * 14}deg) rotateX(${-y * 14}deg) translateY(-4px)`;
    });
    el.addEventListener('pointerleave', () => el.style.transform = '');
  });
  const stage = $('#stage');
  if (stage) {
    const shoes = $$('.shoe', stage);
    stage.addEventListener('pointermove', e => {
      const r = stage.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      shoes.forEach(s => { s.style.setProperty('--ry', x * 22 + 'deg'); s.style.setProperty('--rx', -y * 16 + 'deg'); s.style.setProperty('--z', (s.dataset.depth || 0) + 'px'); });
    });
    stage.addEventListener('pointerleave', () => shoes.forEach(s => { s.style.setProperty('--ry', '0deg'); s.style.setProperty('--rx', '0deg'); s.style.setProperty('--z', '0px'); }));
  }
}

/* ---------- scroll reveal ---------- */
if ('IntersectionObserver' in window) {
  const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .12 });
  $$('.sec-head,.pcard,.feat,.checks li,.faq details,.cc').forEach(el => { el.classList.add('rv'); io.observe(el); });
}

/* ---------- size finder (home) ---------- */
const cm = $('#cm');
if (cm) {
  const age = eu => eu <= 23 ? '≈ 1–2 yrs' : eu <= 26 ? '≈ 2–4 yrs' : eu <= 29 ? '≈ 4–6 yrs' : eu <= 32 ? '≈ 6–8 yrs' : '≈ 8–10 yrs';
  const calc = () => {
    const v = +cm.value, eu = Math.min(36, Math.max(21, Math.round((v + 1.5) * 1.5)));
    $('#cmOut').textContent = v.toFixed(1); $('#sizeOut').textContent = 'EU ' + eu; $('#ageOut').textContent = age(eu);
  };
  cm.addEventListener('input', calc); calc();
}

/* ---------- Meta Pixel (only if PIXEL_ID is set) ---------- */
const track = (ev, data) => { try { window.fbq && window.fbq('track', ev, data || {}); } catch (e) {} };
if (BG.PIXEL_ID) {
  !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); }; if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s); }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
  window.fbq('init', BG.PIXEL_ID); window.fbq('track', 'PageView');
}

/* =====================================================================
   Shared order helpers (used by the store checkout AND the landing pages)
   ===================================================================== */
const el = (form, k) => form.elements.namedItem(k);
const phoneOk = v => /^(?:\+?88)?01[3-9]\d{8}$/.test(v.replace(/[\s-]/g, ''));
const rules = {
  name: v => v.trim().length < 2 ? 'Please enter your name' : '',
  phone: v => !phoneOk(v) ? 'Enter a valid mobile number, e.g. 01712345678' : '',
  address: v => v.trim().length < 10 ? 'Please enter your full address' : ''
};
const setErr = (f, msg) => { const s = f.closest('.fld').querySelector('.err'); if (s) s.textContent = msg || ''; f.setAttribute('aria-invalid', msg ? 'true' : 'false'); };
/* on blur only that field is checked (no page jumps while the parent is still filling the form) */
function bindFields(form) {
  const keys = Object.keys(rules);
  const check = k => { const f = el(form, k), m = rules[k](f.value); setErr(f, m); return !m; };
  keys.forEach(k => el(form, k).addEventListener('blur', () => el(form, k).value && check(k)));
  return { firstInvalid: () => { const bad = keys.filter(k => !check(k)); return bad.length ? el(form, bad[0]) : null; } };
}
const newId = () => 'BG' + Date.now().toString(36).toUpperCase().slice(-7);
const areaLabel = a => a === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka';
/* Sends the order to ORDER_ENDPOINT. With no endpoint the site is in demo mode: the order is only kept locally. */
async function sendOrder(order) {
  if (BG.ORDER_ENDPOINT) {
    try { await fetch(BG.ORDER_ENDPOINT, { method: 'POST', mode: 'no-cors', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(order) }); return true; }
    catch (e) { return false; }
  }
  try { const k = 'bg_demo_orders', all = JSON.parse(localStorage.getItem(k) || '[]'); all.push(order); localStorage.setItem(k, JSON.stringify(all)); } catch (e) {}
  return true;
}
function showDone(order) {
  $('#oid').textContent = '#' + order.id;
  $('#rItems').textContent = order.items.map(i => `${i.product} — EU ${i.size} × ${i.qty}`).join('\n');
  $('#rA').textContent = order.address + ' (' + order.area + ')';
  $('#rT').textContent = fmt(order.total); $('#rPh').textContent = order.phone;
  $('#demoNote').hidden = !!BG.ORDER_ENDPOINT;
  $('#done').hidden = false; $('#done').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
const buildOrder = (form, source, items, area) => {
  const sub = items.reduce((a, i) => a + i.qty * i.price, 0), del = BG.DELIVERY[area];
  return { id: newId(), source, items, name: el(form, 'name').value.trim(), phone: el(form, 'phone').value.trim(), country: 'Bangladesh',
    address: el(form, 'address').value.trim(), area: areaLabel(area), subtotal: sub, delivery: del, total: sub + del, payment: 'Cash on Delivery', page: location.href, time: new Date().toISOString() };
};
const fillCharges = () => { const d = $('#chD'), o = $('#chO'); if (d) d.textContent = fmt(BG.DELIVERY.dhaka); if (o) o.textContent = fmt(BG.DELIVERY.outside); };

/* =====================================================================
   LANDING PAGES (lp/*.html): single-product, single-page checkout
   ===================================================================== */
const lp = page === 'product' ? $('.pdp') : null, lform = $('#orderForm');
if (lp && lform) {
  const name = lp.dataset.name, PRICE = BG.PRICE;
  const content = { content_name: name, content_ids: [lp.dataset.slug], content_type: 'product', value: PRICE, currency: 'BDT' };
  track('ViewContent', content); fillCharges();
  let qty = 1, started = false, sending = false;
  const area = () => el(lform, 'area').value;
  const size = () => (lform.querySelector('input[name=size]:checked') || {}).value;
  const totals = () => { const sub = PRICE * qty, del = BG.DELIVERY[area()]; return { sub, del, tot: sub + del }; };
  const render = () => {
    const t = totals();
    $('#qv').textContent = qty; $('#sQty').textContent = qty;
    $('#sSub').textContent = fmt(t.sub); $('#sDel').textContent = fmt(t.del); $('#sTot').textContent = fmt(t.tot);
    $('#sSize').textContent = size() ? 'Size: EU ' + size() : 'Size: select';
    $('#submitBtn').textContent = 'Confirm order — ' + fmt(t.tot);
  };
  const begin = () => { if (!started) { started = true; track('InitiateCheckout', content); } };
  lform.addEventListener('input', begin);
  lform.addEventListener('change', () => { render(); begin(); if (size()) $('#sizeErr').textContent = ''; });
  $('#qm').onclick = () => { qty = Math.max(1, qty - 1); render(); };
  $('#qp').onclick = () => { qty = Math.min(10, qty + 1); render(); };
  render();
  const fields = bindFields(lform);
  lform.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending) return;
    const bad = fields.firstInvalid(), noSize = !size();
    $('#sizeErr').textContent = noSize ? 'Please choose a size' : '';
    const first = bad || (noSize ? $('.szs') : null);
    if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    sending = true; $('#submitBtn').disabled = true; $('#sendErr').hidden = true;
    const order = buildOrder(lform, 'landing', [{ product: name, slug: lp.dataset.slug, size: size(), qty, price: PRICE }], area());
    if (!(await sendOrder(order))) { $('#sendErr').hidden = false; $('#submitBtn').disabled = false; sending = false; $('#sendErr').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    track('Purchase', { ...content, value: order.total, num_items: qty });
    $('.co-grid').hidden = true; showDone(order);
  });
  /* mobile sticky bar hides while the checkout is on screen */
  const bar = $('#stickyCta'), target = $('#order');
  if (bar && target && 'IntersectionObserver' in window) new IntersectionObserver(es => bar.classList.toggle('hide', es[0].isIntersecting), { threshold: .08 }).observe(target);
}

/* =====================================================================
   STORE: cart, product page, cart page, checkout
   ===================================================================== */
const KEY = 'bg_cart';
const loadCart = () => { try { const c = JSON.parse(localStorage.getItem(KEY) || '[]'); return (Array.isArray(c) ? c : []).filter(i => PR[i.slug] && i.size && i.qty > 0).map(i => ({ ...i, k: i.slug + '|' + i.size })); } catch (e) { return []; } };
let cart = loadCart();
const persist = () => { try { localStorage.setItem(KEY, JSON.stringify(cart.map(({ slug, size, qty }) => ({ slug, size, qty })))); } catch (e) {} };
const count = () => cart.reduce((a, i) => a + i.qty, 0);
const subtotal = () => count() * BG.PRICE;
const lineItems = () => cart.map(i => ({ product: PR[i.slug].title, slug: i.slug, size: i.size, qty: i.qty, price: BG.PRICE }));

const lineHTML = (i, edit) => {
  const p = PR[i.slug];
  return `<div class="cl" data-k="${i.k}"><a href="${p.url}"><img src="${p.img}" alt="" loading="lazy"></a>
    <div class="cl-i"><b>${p.title}</b><small>Size EU ${i.size}</small>
    ${edit ? `<div class="qty sm"><button type="button" data-act="dec" aria-label="Decrease quantity">−</button><output>${i.qty}</output><button type="button" data-act="inc" aria-label="Increase quantity">+</button></div>` : `<small>Qty ${i.qty}</small>`}</div>
    <div class="cl-p"><b>${fmt(i.qty * BG.PRICE)}</b>${edit ? '<button type="button" class="rm" data-act="rm">Remove</button>' : ''}</div></div>`;
};
const emptyLines = '<p class="empty-note">Your cart is empty.</p>';

function refresh() {
  const n = count();
  $$('#cartCount').forEach(c => { c.textContent = n; c.classList.toggle('on', n > 0); });
  const dl = $('#drawerLines');
  if (dl) { dl.innerHTML = cart.length ? cart.map(i => lineHTML(i, true)).join('') : '<div class="empty"><span>🧸</span>Your cart is empty</div>'; $('#drawerSub').textContent = fmt(subtotal()); const co = $('#drawerCheckout'); if (co) co.style.display = cart.length ? '' : 'none'; }
  const cl = $('#cartLines');
  if (cl) {
    cl.innerHTML = cart.length ? cart.map(i => lineHTML(i, true)).join('') : '';
    $('#cCount').textContent = n; $('#cSub').textContent = fmt(subtotal());
    $('#cartGrid').hidden = !cart.length; $('#cartEmpty').hidden = !!cart.length;
  }
  const col = $('#coLines');
  if (col) { col.innerHTML = cart.length ? cart.map(i => lineHTML(i, false)).join('') : emptyLines; checkoutTotals(); }
}
function change(k, d) {
  const i = cart.find(x => x.k === k); if (!i) return;
  i.qty += d; if (i.qty < 1) cart = cart.filter(x => x !== i); i.qty = Math.min(10, i.qty);
  persist(); refresh();
}
const remove = k => { cart = cart.filter(x => x.k !== k); persist(); refresh(); };
function addToCart(slug, size, qty) {
  const k = slug + '|' + size, ex = cart.find(i => i.k === k);
  if (ex) ex.qty = Math.min(10, ex.qty + qty); else cart.push({ k, slug, size, qty: Math.min(10, qty) });
  persist(); refresh();
  track('AddToCart', { content_name: PR[slug].title, content_ids: [slug], content_type: 'product', value: BG.PRICE * qty, currency: 'BDT' });
}
/* cart drawer */
const drawer = $('#drawer'), scrim = $('#scrim');
const openCart = () => { if (!drawer) return; drawer.classList.add('on'); scrim.classList.add('on'); drawer.setAttribute('aria-hidden', 'false'); };
const closeCart = () => { if (!drawer) return; drawer.classList.remove('on'); scrim.classList.remove('on'); drawer.setAttribute('aria-hidden', 'true'); };
if (drawer) {
  $('#closeCart').onclick = closeCart; scrim.onclick = closeCart;
  document.addEventListener('keydown', e => e.key === 'Escape' && closeCart());
  const cb = $('#cartBtn'); if (cb) cb.onclick = openCart;
}
[$('#drawerLines'), $('#cartLines')].forEach(box => box && box.addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const k = b.closest('.cl').dataset.k, a = b.dataset.act;
  a === 'inc' ? change(k, 1) : a === 'dec' ? change(k, -1) : remove(k);
}));

/* store product page: choose size, add to cart */
if (page === 'pdp') {
  const pd = $('#pdp'), slug = pd.dataset.slug;
  let q = 1;
  const size = () => (pd.querySelector('input[name=size]:checked') || {}).value;
  const draw = () => $('#qv').textContent = q;
  $('#qm').onclick = () => { q = Math.max(1, q - 1); draw(); };
  $('#qp').onclick = () => { q = Math.min(10, q + 1); draw(); };
  pd.addEventListener('change', () => { if (size()) $('#sizeErr').textContent = ''; });
  track('ViewContent', { content_name: PR[slug].title, content_ids: [slug], content_type: 'product', value: BG.PRICE, currency: 'BDT' });
  const need = () => { if (size()) return true; $('#sizeErr').textContent = 'Please select a size'; $('#pdpSizes').scrollIntoView({ behavior: 'smooth', block: 'center' }); return false; };
  $('#addBtn').onclick = () => { if (!need()) return; addToCart(slug, size(), q); toast('Added to cart'); openCart(); };
  $('#buyNow').onclick = () => { if (!need()) return; addToCart(slug, size(), q); location.href = 'checkout.html'; };
}

/* store checkout page */
let checkoutTotals = () => {};
if (page === 'checkout') {
  const form = $('#coForm'); fillCharges();
  const area = () => el(form, 'area').value;
  checkoutTotals = () => { const sub = subtotal(), del = cart.length ? BG.DELIVERY[area()] : 0; $('#sSub').textContent = fmt(sub); $('#sDel').textContent = fmt(del); $('#sTot').textContent = fmt(sub + del); $('#submitBtn').textContent = 'Place order — ' + fmt(sub + del); };
  form.addEventListener('change', checkoutTotals);
  if (!cart.length) { $('#coGrid').hidden = true; $('#coEmpty').hidden = false; }
  else track('InitiateCheckout', { content_ids: cart.map(i => i.slug), content_type: 'product', num_items: count(), value: subtotal(), currency: 'BDT' });
  const fields = bindFields(form); let sending = false;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending || !cart.length) return;
    const bad = fields.firstInvalid();
    if (bad) { bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    sending = true; $('#submitBtn').disabled = true; $('#sendErr').hidden = true;
    const order = buildOrder(form, 'store', lineItems(), area());
    if (!(await sendOrder(order))) { $('#sendErr').hidden = false; $('#submitBtn').disabled = false; sending = false; $('#sendErr').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    track('Purchase', { content_ids: order.items.map(i => i.slug), content_type: 'product', num_items: count(), value: order.total, currency: 'BDT' });
    cart = []; persist(); refresh();
    $('#coGrid').hidden = true; showDone(order);
  });
}
refresh();
})();
