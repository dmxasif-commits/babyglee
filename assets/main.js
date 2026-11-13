(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
const BG = window.BG || {}, PR = window.BG_PRODUCTS || {};
const noop = () => {};
const T = window.BGT || { ecom: noop, push: noop, item: () => null, sum: () => 0, pageData: noop, customerData: () => Promise.resolve({}), ids: () => ({}), eventId: () => String(Date.now()), attribution: () => ({}), uid: () => '' };
const fmt = n => '৳' + Number(n).toLocaleString('en-US');
const page = document.body.dataset.page;
const sleep = ms => new Promise(r => setTimeout(r, ms));
const toast = (() => { let t; return m => { const e = $('#toast'); if (!e) return; e.textContent = m; e.classList.add('on'); clearTimeout(t); t = setTimeout(() => e.classList.remove('on'), 2200); }; })();

/* ---------- page-level data for GTM ---------- */
const PAGE_TYPES = { home: 'home', shop: 'shop', pdp: 'product', cart: 'cart', checkout: 'checkout', product: 'landing_product', about: 'info', contact: 'info', privacy: 'info', terms: 'info', shipping: 'info' };
if (page !== 'lpindex') T.pageData(PAGE_TYPES[page] || 'other', page === 'product' ? 'landing' : 'store');

/* ---------- mobile menu ---------- */
const burger = $('#burger'), nav = $('#nav');
if (burger) {
  const set = o => { nav.classList.toggle('open', o); burger.setAttribute('aria-expanded', o); };
  burger.addEventListener('click', () => set(!nav.classList.contains('open')));
  $$('#nav a').forEach(a => a.addEventListener('click', () => set(false)));
  document.addEventListener('keydown', e => e.key === 'Escape' && set(false));
}

/* ---------- ship-to selector ---------- */
const shipBtn = $('#shipBtn'), shipMenu = $('#shipMenu');
if (shipBtn) {
  const setShip = o => { shipMenu.hidden = !o; shipBtn.setAttribute('aria-expanded', o); };
  shipBtn.addEventListener('click', e => { e.stopPropagation(); setShip(shipMenu.hidden); });
  document.addEventListener('click', e => { if (!shipMenu.hidden && !shipMenu.contains(e.target)) setShip(false); });
  document.addEventListener('keydown', e => e.key === 'Escape' && setShip(false));
}

/* ---------- copy-link buttons (lp/index.html) ---------- */
$$('[data-copy]').forEach(b => b.addEventListener('click', async () => {
  const url = b.dataset.copy;
  try { await navigator.clipboard.writeText(url); }
  catch (e) { const ta = document.createElement('textarea'); ta.value = url; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); } catch (x) {} document.body.removeChild(ta); }
  const old = b.textContent; b.textContent = 'Copied!'; toast('Link copied'); setTimeout(() => b.textContent = old, 1500);
}));

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
const eu4cm = v => Math.min(36, Math.max(21, Math.round((v + 1.5) * 1.5)));
const ageOf = eu => eu <= 23 ? '≈ 1–2 yrs' : eu <= 26 ? '≈ 2–4 yrs' : eu <= 29 ? '≈ 4–6 yrs' : eu <= 32 ? '≈ 6–8 yrs' : '≈ 8–10 yrs';
const cm = $('#cm');
if (cm) {
  const calc = () => { const v = +cm.value, eu = eu4cm(v); $('#cmOut').textContent = v.toFixed(1); $('#sizeOut').textContent = 'EU ' + eu; $('#ageOut').textContent = ageOf(eu); };
  cm.addEventListener('input', calc); calc();
}

/* =====================================================================
   Bottom sheets (size guide + quick add): accessible, body-scroll locked
   ===================================================================== */
const sheetStack = [];
let lastFocus = null;
const syncLock = () => document.body.classList.toggle('lock', sheetStack.length > 0);
function openSheet(el) {
  if (!el) return;
  lastFocus = lastFocus || document.activeElement;
  el.hidden = false; sheetStack.push(el); syncLock();
  requestAnimationFrame(() => { el.classList.add('on'); const f = $('[data-close].icon-btn', el); if (f) f.focus({ preventScroll: true }); });
}
function closeSheet(el) {
  if (!el || el.hidden) return;
  el.classList.remove('on'); const i = sheetStack.indexOf(el); if (i > -1) sheetStack.splice(i, 1); syncLock();
  setTimeout(() => { if (!el.classList.contains('on')) el.hidden = true; }, 260);
  if (!sheetStack.length && lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} lastFocus = null; }
}
document.addEventListener('click', e => { const c = e.target.closest('[data-close]'); if (c) closeSheet(c.closest('.sheet-wrap')); });
document.addEventListener('keydown', e => {
  const top = sheetStack[sheetStack.length - 1]; if (!top) return;
  if (e.key === 'Escape') closeSheet(top);
  if (e.key === 'Tab') { const f = $$('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])', top).filter(x => !x.disabled && x.offsetParent !== null); if (!f.length) return; const a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
});

/* size guide sheet: foot-length helper + full table; can hand the chosen size back to the caller */
const sizeModal = $('#sizeModal');
let sizeTarget = null;
if (sizeModal) {
  const szCm = $('#szCm'), use = $('#szUse');
  const calc = () => {
    const v = +szCm.value, eu = eu4cm(v);
    $('#szCmOut').textContent = v.toFixed(1); $('#szOut').textContent = 'EU ' + eu; $('#szAge').textContent = ageOf(eu); use.textContent = 'Select EU ' + eu; use.dataset.eu = eu;
    $$('tr[data-eu]', sizeModal).forEach(r => r.classList.toggle('hl', +r.dataset.eu === eu));
  };
  szCm.addEventListener('input', calc); calc();
  use.addEventListener('click', () => { if (sizeTarget) sizeTarget(+use.dataset.eu); closeSheet(sizeModal); });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-open-size]'); if (!b) return;
    sizeTarget = b.sizeTarget || null; use.hidden = !sizeTarget; openSheet(sizeModal);
    T.push({ event: 'size_guide_open', page_type: PAGE_TYPES[page] || 'other' });
  });
}

/* =====================================================================
   Tracking helpers (GA4 e-commerce events + customer data for matching)
   ===================================================================== */
const LISTS = {};
const itemsOf = list => list.map((i, n) => T.item(i.slug, { size: i.size, qty: i.qty, index: n })).filter(Boolean);
const valueOf = items => T.sum(items);

/* =====================================================================
   Shared order helpers (store checkout AND landing pages)
   ===================================================================== */
const el = (form, k) => form.elements.namedItem(k);
const phoneOk = v => /^(?:\+?88)?01[3-9]\d{8}$/.test(v.replace(/[\s-]/g, ''));
const rules = {
  name: v => v.trim().length < 2 ? 'Please enter your name' : '',
  phone: v => !phoneOk(v) ? 'Enter a valid mobile number, e.g. 01712345678' : '',
  email: v => v.trim() && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim()) ? 'Enter a valid email or leave it empty' : '',
  district: v => !v ? 'Please select your district' : '',
  address: v => v.trim().length < 10 ? 'Please enter your full address' : ''
};
const setErr = (f, msg) => { const s = f.closest('.fld').querySelector('.err'); if (s) s.textContent = msg || ''; f.setAttribute('aria-invalid', msg ? 'true' : 'false'); };
/* On blur only that field is checked (no page jumps while the parent is still filling the form). */
function bindFields(form) {
  const keys = Object.keys(rules);
  const check = k => { const f = el(form, k), m = rules[k](f.value); setErr(f, m); return !m; };
  keys.forEach(k => { const f = el(form, k); f.addEventListener(f.tagName === 'SELECT' ? 'change' : 'blur', () => (f.value || f.tagName === 'SELECT') && check(k)); });
  return {
    firstInvalid: () => { const bad = keys.filter(k => !check(k)); return bad.length ? el(form, bad[0]) : null; },
    silentValid: () => keys.every(k => !rules[k](el(form, k).value))
  };
}
/* District decides the default delivery area (Dhaka = inside Dhaka); the parent can still change it. */
function bindDistrict(form) {
  const d = el(form, 'district');
  d.addEventListener('change', () => {
    const r = form.querySelector(`input[name=area][value=${d.value === 'Dhaka' ? 'dhaka' : 'outside'}]`);
    if (d.value && r && !r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
  });
}
const newId = () => 'BG' + Date.now().toString(36).toUpperCase().slice(-7);
const areaLabel = a => a === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka';
const custOf = form => ({ name: el(form, 'name').value, phone: el(form, 'phone').value, email: el(form, 'email').value, address: el(form, 'address').value, district: el(form, 'district').value });
const buildOrder = (form, source, items, area) => {
  const sub = items.reduce((a, i) => a + i.qty * i.price, 0), del = BG.DELIVERY[area], c = custOf(form);
  return { id: newId(), source, items, name: c.name.trim(), phone: c.phone.trim(), email: c.email.trim(), country: 'Bangladesh', district: c.district,
    address: c.address.trim(), area: areaLabel(area), subtotal: sub, delivery: del, total: sub + del, payment: 'Cash on Delivery', page: location.href, time: new Date().toISOString() };
};
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
  $('#rA').textContent = order.address + ', ' + order.district + ' (' + order.area + ')';
  $('#rT').textContent = fmt(order.total); $('#rPh').textContent = order.phone;
  $('#demoNote').hidden = !!BG.ORDER_ENDPOINT;
  $('#done').hidden = false; $('#done').scrollIntoView({ behavior: 'smooth', block: 'center' });
}
const fillCharges = () => { const d = $('#chD'), o = $('#chO'); if (d) d.textContent = fmt(BG.DELIVERY.dhaka); if (o) o.textContent = fmt(BG.DELIVERY.outside); };

/* Funnel events after the customer starts typing: add_shipping_info, add_payment_info, purchase. Customer data is hashed once, here. */
const fired = new Set();
async function stageEvent(name, form, items, area, extra) {
  const c = custOf(form), cd = await T.customerData(c), its = itemsOf(items), value = valueOf(its), del = BG.DELIVERY[area];
  const payload = Object.assign({ user_data: cd.user_data, meta_user_data: cd.meta_user_data }, T.ids(), extra || {});
  const ecommerce = { value, items: its };
  if (name === 'add_shipping_info') ecommerce.shipping_tier = areaLabel(area);
  if (name === 'add_payment_info') { ecommerce.payment_type = 'Cash on Delivery'; ecommerce.shipping_tier = areaLabel(area); }
  if (name === 'purchase') { ecommerce.transaction_id = payload.transaction_id; ecommerce.shipping = del; ecommerce.tax = 0; }
  return T.ecom(name, ecommerce, payload);
}
/* The same details go into the order so a server (Conversions API) can send the matching server-side event with the same event_id. */
async function trackingBlock(form, order) {
  const cd = await T.customerData(custOf(form));
  return Object.assign({ event_id: 'purchase_' + order.id, user_agent: navigator.userAgent, page_url: location.href, referrer: document.referrer, user_data: cd.user_data, meta_user_data: cd.meta_user_data, attribution: T.attribution() }, T.ids());
}
async function firePurchase(form, order, items, area) {
  const k = 'bg_purchased'; let done = []; try { done = JSON.parse(sessionStorage.getItem(k) || '[]'); } catch (e) {}
  if (done.includes(order.id)) return;
  await stageEvent('purchase', form, items, area, { event_id: 'purchase_' + order.id, transaction_id: order.id, order_total: order.total, order_source: order.source, payment_type: 'Cash on Delivery', shipping_tier: order.area });
  try { done.push(order.id); sessionStorage.setItem(k, JSON.stringify(done)); } catch (e) {}
}
/* Fires add_shipping_info once, as soon as name, phone, district and address are all valid. */
function watchShipping(form, fields, getItems, area) {
  const go = () => { if (fired.has('ship') || !fields.silentValid()) return; fired.add('ship'); stageEvent('add_shipping_info', form, getItems(), area()); };
  ['address', 'district', 'phone'].forEach(k => el(form, k).addEventListener(el(form, k).tagName === 'SELECT' ? 'change' : 'blur', go));
}

/* =====================================================================
   LANDING PAGES (lp/*.html): single-product, single-page checkout
   ===================================================================== */
const lp = page === 'product' ? $('.pdp') : null, lform = $('#orderForm');
if (lp && lform) {
  const name = lp.dataset.name, slug = lp.dataset.slug, PRICE = BG.PRICE;
  fillCharges();
  T.ecom('view_item', { value: PRICE, items: [T.item(slug)] }, { page_type: 'landing_product' });
  let qty = 1, started = false, sending = false;
  const area = () => el(lform, 'area').value;
  const size = () => (lform.querySelector('input[name=size]:checked') || {}).value;
  const lineItems = () => [{ slug, size: size() || '', qty }];
  const totals = () => { const sub = PRICE * qty, del = BG.DELIVERY[area()]; return { sub, del, tot: sub + del }; };
  const render = () => {
    const t = totals();
    $('#qv').textContent = qty; $('#sQty').textContent = qty;
    $('#sSub').textContent = fmt(t.sub); $('#sDel').textContent = fmt(t.del); $('#sTot').textContent = fmt(t.tot);
    $('#sSize').textContent = size() ? 'Size: EU ' + size() : 'Size: select';
    $('#submitBtn').textContent = 'Confirm order — ' + fmt(t.tot);
  };
  const begin = () => { if (!started) { started = true; T.ecom('begin_checkout', { value: PRICE * qty, items: itemsOf(lineItems()) }, { page_type: 'landing_product' }); } };
  lform.addEventListener('input', begin);
  lform.addEventListener('change', () => { render(); begin(); if (size()) $('#sizeErr').textContent = ''; });
  $('#qm').onclick = () => { qty = Math.max(1, qty - 1); render(); };
  $('#qp').onclick = () => { qty = Math.min(10, qty + 1); render(); };
  render();
  const fields = bindFields(lform); bindDistrict(lform);
  watchShipping(lform, fields, lineItems, area);
  lform.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending) return;
    const bad = fields.firstInvalid(), noSize = !size();
    $('#sizeErr').textContent = noSize ? 'Please choose a size' : '';
    const first = bad || (noSize ? $('.szs') : null);
    if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    sending = true; $('#submitBtn').disabled = true; $('#sendErr').hidden = true;
    const items = [{ product: name, slug, size: size(), qty, price: PRICE }];
    const order = buildOrder(lform, 'landing', items, area());
    order.tracking = await trackingBlock(lform, order);
    await stageEvent('add_payment_info', lform, lineItems(), area());
    if (!(await sendOrder(order))) { $('#sendErr').hidden = false; $('#submitBtn').disabled = false; sending = false; $('#sendErr').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    await firePurchase(lform, order, lineItems(), area());
    $('.co-grid').hidden = true; showDone(order);
  });
  /* mobile sticky bar hides while the checkout is on screen */
  const bar = $('#stickyCta'), target = $('#order');
  if (bar && target && 'IntersectionObserver' in window) new IntersectionObserver(es => bar.classList.toggle('hide', es[0].isIntersecting), { threshold: .08 }).observe(target);
}

/* =====================================================================
   STORE: cart, floating buttons, product page, quick add, cart page, checkout
   ===================================================================== */
const KEY = 'bg_cart', BN = 'bg_buynow';
const loadCart = () => { try { const c = JSON.parse(localStorage.getItem(KEY) || '[]'); return (Array.isArray(c) ? c : []).filter(i => PR[i.slug] && i.size && i.qty > 0).map(i => ({ ...i, k: i.slug + '|' + i.size })); } catch (e) { return []; } };
let cart = loadCart();
const persist = () => { try { localStorage.setItem(KEY, JSON.stringify(cart.map(({ slug, size, qty }) => ({ slug, size, qty })))); } catch (e) {} };
const count = () => cart.reduce((a, i) => a + i.qty, 0);
const subtotal = () => count() * BG.PRICE;
const toOrderItems = list => list.map(i => ({ product: PR[i.slug].title, slug: i.slug, size: i.size, qty: i.qty, price: BG.PRICE }));
const buyNowItem = () => { try { const b = JSON.parse(sessionStorage.getItem(BN) || 'null'); return b && PR[b.slug] && b.size ? { ...b, k: b.slug + '|' + b.size } : null; } catch (e) { return null; } };

const lineHTML = (i, edit) => {
  const p = PR[i.slug];
  return `<div class="cl" data-k="${i.k}"><a href="${p.url}"><img src="${p.img}" alt="" loading="lazy"></a>
    <div class="cl-i"><b>${p.title}</b><small>Size EU ${i.size}</small>
    ${edit ? `<div class="qty sm"><button type="button" data-act="dec" aria-label="Decrease quantity">−</button><output>${i.qty}</output><button type="button" data-act="inc" aria-label="Increase quantity">+</button></div>` : `<small>Qty ${i.qty}</small>`}</div>
    <div class="cl-p"><b>${fmt(i.qty * BG.PRICE)}</b>${edit ? '<button type="button" class="rm" data-act="rm">Remove</button>' : ''}</div></div>`;
};

let onCartChange = () => {};
function refresh() {
  const n = count();
  $$('.js-cart-count').forEach(c => { c.textContent = n; c.classList.toggle('on', n > 0); });
  const dl = $('#drawerLines');
  if (dl) { dl.innerHTML = cart.length ? cart.map(i => lineHTML(i, true)).join('') : '<div class="empty"><span>🧸</span>Your cart is empty</div>'; $('#drawerSub').textContent = fmt(subtotal()); const co = $('#drawerCheckout'); if (co) co.style.display = cart.length ? '' : 'none'; }
  const cl = $('#cartLines');
  if (cl) { cl.innerHTML = cart.length ? cart.map(i => lineHTML(i, true)).join('') : ''; $('#cCount').textContent = n; $('#cSub').textContent = fmt(subtotal()); $('#cartGrid').hidden = !cart.length; $('#cartEmpty').hidden = !!cart.length; }
  onCartChange();
}
function change(k, d) {
  const i = cart.find(x => x.k === k); if (!i) return;
  if (d < 0) T.ecom('remove_from_cart', { value: BG.PRICE, items: [T.item(i.slug, { size: i.size, qty: 1 })] });
  i.qty += d; if (i.qty < 1) cart = cart.filter(x => x !== i); else i.qty = Math.min(10, i.qty);
  persist(); refresh();
}
function removeLine(k) {
  const i = cart.find(x => x.k === k); if (!i) return;
  T.ecom('remove_from_cart', { value: BG.PRICE * i.qty, items: [T.item(i.slug, { size: i.size, qty: i.qty })] });
  cart = cart.filter(x => x !== i); persist(); refresh();
}
function addToCart(slug, size, qty) {
  const k = slug + '|' + size, ex = cart.find(i => i.k === k);
  if (ex) ex.qty = Math.min(10, ex.qty + qty); else cart.push({ k, slug, size, qty: Math.min(10, qty) });
  persist(); refresh();
  T.ecom('add_to_cart', { value: BG.PRICE * qty, items: [T.item(slug, { size, qty })] });
  const fab = $('#cartFab'); if (fab) { fab.classList.remove('bump'); void fab.offsetWidth; fab.classList.add('bump'); }
}
function buyNow(slug, size, qty) {
  try { sessionStorage.setItem(BN, JSON.stringify({ slug, size, qty })); } catch (e) {}
  T.ecom('add_to_cart', { value: BG.PRICE * qty, items: [T.item(slug, { size, qty })] }, { buy_now: true });
  location.href = 'checkout.html?buy=1';
}

/* cart drawer + floating cart button */
const drawer = $('#drawer'), scrim = $('#scrim');
const openCart = () => {
  if (!drawer) return; drawer.classList.add('on'); scrim.classList.add('on'); drawer.setAttribute('aria-hidden', 'false');
  if (cart.length) T.ecom('view_cart', { value: subtotal(), items: itemsOf(cart) });
};
const closeCart = () => { if (!drawer) return; drawer.classList.remove('on'); scrim.classList.remove('on'); drawer.setAttribute('aria-hidden', 'true'); };
if (drawer) {
  $('#closeCart').onclick = closeCart; scrim.onclick = closeCart;
  document.addEventListener('keydown', e => e.key === 'Escape' && !sheetStack.length && closeCart());
  [$('#cartBtn'), $('#cartFab')].forEach(b => b && (b.onclick = openCart));
  const cont = document.createElement('button'); cont.type = 'button'; cont.className = 'cont-link'; cont.textContent = 'Continue shopping'; cont.onclick = closeCart; $('footer', drawer).appendChild(cont);
}
[$('#drawerLines'), $('#cartLines')].forEach(box => box && box.addEventListener('click', e => {
  const b = e.target.closest('[data-act]'); if (!b) return;
  const k = b.closest('.cl').dataset.k, a = b.dataset.act;
  a === 'inc' ? change(k, 1) : a === 'dec' ? change(k, -1) : removeLine(k);
}));

/* floating WhatsApp: pre-filled with what the visitor is looking at */
const wa = $('#waFab');
if (wa) wa.href = `https://wa.me/${BG.WHATSAPP}?text=` + encodeURIComponent('Hi Babyglee, I need help with: ' + document.title.replace(/\s*[—|-]\s*Babyglee.*$/, ''));
document.addEventListener('click', e => { const a = e.target.closest('a[href*="wa.me"]'); if (a) T.push({ event: 'whatsapp_click', page_type: PAGE_TYPES[page] || 'other', link_location: a.id === 'waFab' ? 'floating_button' : 'page_link' }); });

/* product lists: view_item_list when a grid scrolls into view, select_item on click */
$$('.grid[data-list]').forEach(g => {
  const id = g.dataset.list, nm = g.dataset.listName || id, slugs = $$('.pcard', g).map(c => c.dataset.slug);
  LISTS[id] = { id, nm, slugs };
  const send = () => T.ecom('view_item_list', { item_list_id: id, item_list_name: nm, items: slugs.map((s, n) => T.item(s, { index: n, listId: id, listName: nm })) });
  if ('IntersectionObserver' in window) { const o = new IntersectionObserver(es => { if (es[0].isIntersecting) { send(); o.disconnect(); } }, { threshold: .25 }); o.observe(g); } else send();
  g.addEventListener('click', e => {
    const a = e.target.closest('[data-select]'); if (!a) return;
    const s = a.closest('.pcard').dataset.slug;
    T.ecom('select_item', { item_list_id: id, item_list_name: nm, items: [T.item(s, { index: slugs.indexOf(s), listId: id, listName: nm })] });
  });
});

/* quick add (bottom sheet on shop/home cards) */
const qa = $('#qaSheet');
if (qa) {
  let slug = null, q = 1;
  const size = () => (qa.querySelector('input[name=qasize]:checked') || {}).value;
  const draw = () => $('#qaQv').textContent = q;
  const open = s => {
    slug = s; q = 1; draw(); $$('input[name=qasize]', qa).forEach(r => r.checked = false); $('#qaErr').textContent = '';
    $('#qaImg').src = PR[s].img; $('#qaName').textContent = PR[s].title; qa.querySelector('[data-open-size]').sizeTarget = eu => { const r = qa.querySelector(`input[name=qasize][value="${eu}"]`); if (r) { r.checked = true; $('#qaErr').textContent = ''; } };
    openSheet(qa); T.push({ event: 'quick_add_open', item_id: PR[s].sku });
  };
  document.addEventListener('click', e => { const b = e.target.closest('[data-qa]'); if (b) { lastFocus = b; open(b.dataset.qa); } });
  $('#qaQm').onclick = () => { q = Math.max(1, q - 1); draw(); };
  $('#qaQp').onclick = () => { q = Math.min(10, q + 1); draw(); };
  qa.addEventListener('change', () => { if (size()) $('#qaErr').textContent = ''; });
  const need = () => { if (size()) return true; $('#qaErr').textContent = 'Please select a size'; $('#qaSizes').scrollIntoView({ block: 'center', behavior: 'smooth' }); return false; };
  $('#qaAdd').onclick = () => { if (!need()) return; addToCart(slug, size(), q); closeSheet(qa); lastFocus = null; setTimeout(openCart, 180); };
  $('#qaBuy').onclick = () => { if (!need()) return; buyNow(slug, size(), q); };
}

/* store product page: choose size, add to cart / buy now (+ sticky bar on phones) */
if (page === 'pdp') {
  const pd = $('#pdp'), slug = pd.dataset.slug;
  let q = 1;
  const size = () => (pd.querySelector('input[name=size]:checked') || {}).value;
  const draw = () => $('#qv').textContent = q;
  $('#qm').onclick = () => { q = Math.max(1, q - 1); draw(); };
  $('#qp').onclick = () => { q = Math.min(10, q + 1); draw(); };
  pd.addEventListener('change', () => { if (size()) { $('#sizeErr').textContent = ''; T.push({ event: 'select_size', item_id: PR[slug].sku, size: 'EU ' + size() }); } });
  const sg = pd.querySelector('[data-open-size]'); if (sg) sg.sizeTarget = eu => { const r = pd.querySelector(`input[name=size][value="${eu}"]`); if (r) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); } };
  T.ecom('view_item', { value: BG.PRICE, items: [T.item(slug)] }, { page_type: 'product' });
  const need = () => {
    if (size()) return true;
    $('#sizeErr').textContent = 'Please select a size';
    const box = $('#pdpSizes'); box.scrollIntoView({ behavior: 'smooth', block: 'center' }); box.classList.remove('shake'); void box.offsetWidth; box.classList.add('shake');
    return false;
  };
  const add = () => { if (!need()) return; addToCart(slug, size(), q); setTimeout(openCart, 120); };
  const now = () => { if (!need()) return; buyNow(slug, size(), q); };
  $('#addBtn').onclick = add; $('#buyNow').onclick = now; $('#barAdd').onclick = add; $('#barBuy').onclick = now;
  /* sticky buy bar shows only once the main buttons have scrolled out of view */
  const bar = $('#pdpBar'), main = $('#addBtn');
  if ('IntersectionObserver' in window) new IntersectionObserver(es => { const on = !es[0].isIntersecting && es[0].boundingClientRect.top < 0; bar.classList.toggle('on', on); document.body.classList.toggle('bar-on', on); }, { threshold: 0 }).observe(main);
}

/* cart page */
if (page === 'cart') { if (cart.length) T.ecom('view_cart', { value: subtotal(), items: itemsOf(cart) }); }

/* store checkout page (supports "Buy now": checks out only that item and leaves the cart untouched) */
let checkoutTotals = () => {};
if (page === 'checkout') {
  const form = $('#coForm'); fillCharges();
  const bn = new URLSearchParams(location.search).get('buy') === '1' ? buyNowItem() : null;
  const lines = () => bn ? [bn] : cart;
  const area = () => el(form, 'area').value;
  const colLines = $('#coLines');
  const draw = () => {
    const l = lines(), sub = l.reduce((a, i) => a + i.qty * BG.PRICE, 0), del = l.length ? BG.DELIVERY[area()] : 0;
    colLines.innerHTML = l.length ? l.map(i => lineHTML(i, false)).join('') : '<p class="empty-note">Your cart is empty.</p>';
    $('#sSub').textContent = fmt(sub); $('#sDel').textContent = fmt(del); $('#sTot').textContent = fmt(sub + del); $('#sumTop').textContent = fmt(sub + del);
    $('#submitBtn').textContent = 'Place order — ' + fmt(sub + del);
  };
  checkoutTotals = draw; onCartChange = () => { if (!bn) draw(); };
  form.addEventListener('change', draw); draw();
  if (bn) { const ec = $('.summary .lnk-b'); if (ec) ec.hidden = true; }
  if (matchMedia('(max-width:980px)').matches) $('#sumD').open = false;
  if (!lines().length) { $('#coGrid').hidden = true; $('#coEmpty').hidden = false; }
  else T.ecom('begin_checkout', { value: valueOf(itemsOf(lines())), items: itemsOf(lines()) }, { page_type: 'checkout', buy_now: !!bn });
  const fields = bindFields(form); bindDistrict(form);
  watchShipping(form, fields, lines, area);
  let sending = false;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending || !lines().length) return;
    const bad = fields.firstInvalid();
    if (bad) { bad.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    sending = true; $('#submitBtn').disabled = true; $('#sendErr').hidden = true;
    const order = buildOrder(form, 'store', toOrderItems(lines()), area());
    order.tracking = await trackingBlock(form, order);
    await stageEvent('add_payment_info', form, lines(), area());
    if (!(await sendOrder(order))) { $('#sendErr').hidden = false; $('#submitBtn').disabled = false; sending = false; $('#sendErr').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    await firePurchase(form, order, lines(), area());
    if (bn) { try { sessionStorage.removeItem(BN); } catch (e) {} } else { cart = []; persist(); refresh(); }
    $('#coGrid').hidden = true; showDone(order);
  });
}
refresh();
})();
