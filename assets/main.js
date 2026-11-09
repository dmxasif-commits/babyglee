(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s), $$ = (s, r = document) => [...r.querySelectorAll(s)];
const BG = window.BG || {};
const fmt = n => '৳' + Number(n).toLocaleString('en-US');

/* ---------- mobile menu ---------- */
const burger = $('#burger'), nav = $('#nav');
if (burger) {
  const set = o => { nav.classList.toggle('open', o); burger.setAttribute('aria-expanded', o); };
  burger.addEventListener('click', () => set(!nav.classList.contains('open')));
  $$('#nav a').forEach(a => a.addEventListener('click', () => set(false)));
  document.addEventListener('keydown', e => e.key === 'Escape' && set(false));
}

/* ---------- 3D tilt (pointer devices only) ---------- */
const fine = matchMedia('(hover:hover) and (pointer:fine)').matches;
if (fine) {
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

/* ---------- product page: checkout ---------- */
const pdp = $('.pdp'), form = $('#orderForm');
if (pdp && form) {
  const name = pdp.dataset.name, PRICE = BG.PRICE, DEL = BG.DELIVERY;
  const content = { content_name: name, content_ids: [pdp.dataset.slug], content_type: 'product', value: PRICE, currency: 'BDT' };
  track('ViewContent', content);
  $('#chD').textContent = fmt(DEL.dhaka); $('#chO').textContent = fmt(DEL.outside);
  let qty = 1, started = false;
  const el = k => form.elements.namedItem(k);
  const area = () => el('area').value;
  const size = () => (form.querySelector('input[name=size]:checked') || {}).value;
  const totals = () => { const sub = PRICE * qty, del = DEL[area()], tot = sub + del; return { sub, del, tot }; };
  const render = () => {
    const t = totals();
    $('#qv').textContent = qty; $('#sQty').textContent = qty;
    $('#sSub').textContent = fmt(t.sub); $('#sDel').textContent = fmt(t.del); $('#sTot').textContent = fmt(t.tot);
    $('#sSize').textContent = size() ? 'Size: EU ' + size() : 'Size: select';
    $('#submitBtn').textContent = 'Confirm order — ' + fmt(t.tot);
  };
  const begin = () => { if (!started) { started = true; track('InitiateCheckout', content); } };
  form.addEventListener('input', begin); form.addEventListener('change', () => { render(); begin(); });
  $('#qm').onclick = () => { qty = Math.max(1, qty - 1); render(); };
  $('#qp').onclick = () => { qty = Math.min(10, qty + 1); render(); };
  render();

  const setErr = (el, msg) => { const s = el.closest('.fld').querySelector('.err'); if (s) s.textContent = msg || ''; el.setAttribute && el.setAttribute('aria-invalid', msg ? 'true' : 'false'); };
  const phoneOk = v => /^(?:\+?88)?01[3-9]\d{8}$/.test(v.replace(/[\s-]/g, ''));
  const rules = {
    name: v => v.trim().length < 2 ? 'Please enter your name' : '',
    phone: v => !phoneOk(v) ? 'Enter a valid mobile number, e.g. 01712345678' : '',
    address: v => v.trim().length < 10 ? 'Please enter your full address' : ''
  };
  const checkField = k => { const f = el(k), m = rules[k](f.value); setErr(f, m); return !m; };
  const validate = () => {
    const bad = Object.keys(rules).filter(k => !checkField(k));
    const noSize = !size();
    $('#sizeErr').textContent = noSize ? 'Please choose a size' : '';
    const first = bad.length ? el(bad[0]) : noSize ? $('.szs') : null;
    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return !first;
  };
  /* on blur only that field is checked (no page jumps while the parent is still filling the form) */
  Object.keys(rules).forEach(k => el(k).addEventListener('blur', () => el(k).value && checkField(k)));
  form.addEventListener('change', () => { if (size()) $('#sizeErr').textContent = ''; });

  let sending = false;
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (sending || !validate()) return;
    sending = true; $('#submitBtn').disabled = true;
    const t = totals(), id = 'BG' + Date.now().toString(36).toUpperCase().slice(-7);
    const order = { id, product: name, slug: pdp.dataset.slug, size: 'EU ' + size(), qty, name: el('name').value.trim(), phone: el('phone').value.trim(),
      address: el('address').value.trim(), area: area() === 'dhaka' ? 'Inside Dhaka' : 'Outside Dhaka', subtotal: t.sub, delivery: t.del, total: t.tot, payment: 'Cash on Delivery', page: location.href, time: new Date().toISOString() };
    $('#sendErr').hidden = true;
    let sent = true;
    if (BG.ORDER_ENDPOINT) {
      try { await fetch(BG.ORDER_ENDPOINT, { method: 'POST', mode: 'no-cors', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(order) }); }
      catch (err) { sent = false; }
    } else {
      /* Demo mode: no backend connected yet. Keep a local copy so nothing is silently lost during testing. */
      try { const k = 'bg_demo_orders', all = JSON.parse(localStorage.getItem(k) || '[]'); all.push(order); localStorage.setItem(k, JSON.stringify(all)); } catch (err) {}
    }
    if (!sent) { $('#sendErr').hidden = false; $('#submitBtn').disabled = false; sending = false; $('#sendErr').scrollIntoView({ behavior: 'smooth', block: 'center' }); return; }
    track('Purchase', { ...content, value: t.tot, num_items: qty });
    $('#oid').textContent = '#' + id; $('#rP').textContent = name + ' × ' + qty; $('#rS').textContent = order.size;
    $('#rA').textContent = order.address + ' (' + order.area + ')'; $('#rT').textContent = fmt(t.tot); $('#rPh').textContent = order.phone;
    $('#demoNote').hidden = !!BG.ORDER_ENDPOINT;
    $('.co-grid').hidden = true; $('#done').hidden = false;
    $('#done').scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  /* mobile sticky bar hides while the checkout is on screen */
  const bar = $('#stickyCta'), target = $('#order');
  if (bar && target && 'IntersectionObserver' in window) new IntersectionObserver(es => bar.classList.toggle('hide', es[0].isIntersecting), { threshold: .08 }).observe(target);
}
})();
