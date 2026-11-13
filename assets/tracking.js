/* Babyglee tracking layer: GTM + GA4 e-commerce dataLayer + customer-matching data.
 *
 * Everything (GA4, Meta Pixel + Conversions API, Google Ads, Microsoft Clarity) is meant to live in Google Tag Manager.
 * This file only (1) loads GTM, (2) pushes clean e-commerce events to window.dataLayer, and
 * (3) prepares first-party data for event quality: hashed customer data, fbp/fbc, click IDs, UTMs, event_id.
 * Docs: docs/gtm-tracking.md
 */
(function () {
  'use strict';
  var BG = window.BG || {};
  window.dataLayer = window.dataLayer || [];
  var CUR = 'BDT';

  /* ---------- GTM loader (set GTM_ID in assets/config.js; GTM_SRC optional first-party/server-side loader URL) ---------- */
  if (BG.GTM_ID) {
    (function (w, d, s, l, i) {
      w[l] = w[l] || []; w[l].push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
      var f = d.getElementsByTagName(s)[0], j = d.createElement(s);
      j.async = true; j.src = (BG.GTM_SRC || 'https://www.googletagmanager.com/gtm.js') + '?id=' + i;
      f.parentNode.insertBefore(j, f);
    })(window, document, 'script', 'dataLayer', BG.GTM_ID);
  }

  /* ---------- small helpers ---------- */
  function getCookie(n) { var m = document.cookie.match(new RegExp('(?:^|; )' + n.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function setCookie(n, v, days) {
    try { document.cookie = n + '=' + encodeURIComponent(v) + ';max-age=' + Math.round(days * 86400) + ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''); } catch (e) {}
  }
  function lsGet(k) { try { return JSON.parse(localStorage.getItem(k)); } catch (e) { return null; } }
  function lsSet(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  function rand() { return Math.random().toString(36).slice(2, 10); }
  function uuid() { try { return crypto.randomUUID(); } catch (e) { return rand() + rand() + rand(); } }
  function eventId(prefix) { return prefix + '_' + Date.now().toString(36) + '_' + rand(); }

  /* ---------- visitor id (first-party, for Meta external_id) ---------- */
  var uid = getCookie('bg_uid');
  if (!uid) { uid = uuid(); }
  setCookie('bg_uid', uid, 400);

  /* ---------- attribution: UTMs + click IDs (first touch kept, last touch updated) ---------- */
  var KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid'];
  var qs = new URLSearchParams(location.search), seen = {};
  KEYS.forEach(function (k) { var v = qs.get(k); if (v) seen[k] = v; });
  var attr = lsGet('bg_attr') || {};
  if (Object.keys(seen).length) {
    seen.landing_page = location.pathname; seen.ts = Date.now();
    if (!attr.first) attr.first = seen;
    attr.last = seen; lsSet('bg_attr', attr);
  }
  /* _fbc from fbclid when the Pixel has not set it yet (format: fb.1.<timestamp ms>.<fbclid>) */
  if (seen.fbclid && !getCookie('_fbc')) setCookie('_fbc', 'fb.1.' + Date.now() + '.' + seen.fbclid, 90);

  /* ---------- SHA-256 (hex) with a pure-JS fallback for non-secure contexts ---------- */
  var SHA_K = null, SHA_H = null;
  function shaInit() {
    if (SHA_K) return;
    SHA_K = []; SHA_H = [];
    for (var n = 2, c = 0; c < 64; n++) {
      var prime = true;
      for (var d = 2; d * d <= n; d++) if (n % d === 0) { prime = false; break; }
      if (!prime) continue;
      if (c < 8) SHA_H[c] = (Math.pow(n, 0.5) * 4294967296) | 0;
      SHA_K[c++] = (Math.pow(n, 1 / 3) * 4294967296) | 0;
    }
  }
  /* Standard SHA-256 over a binary string; used only when crypto.subtle is unavailable (e.g. plain http). */
  function sha256Fallback(bin) {
    shaInit();
    var rr = function (v, n) { return (v >>> n) | (v << (32 - n)); };
    var h = SHA_H.slice(), k = SHA_K, words = [], i, j, bitLen = bin.length * 8;
    bin += '\x80'; while (bin.length % 64 !== 56) bin += '\x00';
    for (i = 0; i < bin.length; i++) { j = bin.charCodeAt(i); if (j > 255) return ''; words[i >> 2] |= j << ((3 - (i % 4)) * 8); }
    words.push((bitLen / 4294967296) | 0); words.push(bitLen | 0);
    for (j = 0; j < words.length; j += 16) {
      var w = words.slice(j, j + 16), a = h[0], b = h[1], c = h[2], d = h[3], e = h[4], f = h[5], g = h[6], hh = h[7];
      for (i = 0; i < 64; i++) {
        if (i >= 16) {
          var w15 = w[i - 15], w2 = w[i - 2];
          w[i] = (w[i - 16] + (rr(w15, 7) ^ rr(w15, 18) ^ (w15 >>> 3)) + w[i - 7] + (rr(w2, 17) ^ rr(w2, 19) ^ (w2 >>> 10))) | 0;
        }
        var t1 = (hh + (rr(e, 6) ^ rr(e, 11) ^ rr(e, 25)) + ((e & f) ^ (~e & g)) + k[i] + w[i]) | 0;
        var t2 = ((rr(a, 2) ^ rr(a, 13) ^ rr(a, 22)) + ((a & b) ^ (a & c) ^ (b & c))) | 0;
        hh = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h[0] = (h[0] + a) | 0; h[1] = (h[1] + b) | 0; h[2] = (h[2] + c) | 0; h[3] = (h[3] + d) | 0;
      h[4] = (h[4] + e) | 0; h[5] = (h[5] + f) | 0; h[6] = (h[6] + g) | 0; h[7] = (h[7] + hh) | 0;
    }
    return h.map(function (x) { return ('00000000' + (x >>> 0).toString(16)).slice(-8); }).join('');
  }
  function sha256(str) {
    str = String(str == null ? '' : str);
    if (!str) return Promise.resolve('');
    if (window.crypto && crypto.subtle && window.TextEncoder) {
      return crypto.subtle.digest('SHA-256', new TextEncoder().encode(str)).then(function (buf) {
        return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      }).catch(function () { return sha256Fallback(unescape(encodeURIComponent(str))); });
    }
    return Promise.resolve(sha256Fallback(unescape(encodeURIComponent(str))));
  }

  /* ---------- customer data normalisation (Bangladesh) ---------- */
  function phoneParts(raw) {
    var d = String(raw || '').replace(/\D/g, '');
    if (d.indexOf('880') === 0) d = d.slice(3);       // 880171... -> 171...
    if (d.indexOf('0') === 0) d = d.slice(1);         // 0171...  -> 171...
    if (!/^1[3-9]\d{8}$/.test(d)) return null;
    return { e164: '+880' + d, digits: '880' + d };   // Google: E.164 with +; Meta: digits with country code, no +
  }
  function splitName(full) { var p = String(full || '').trim().toLowerCase().split(/\s+/); return { first: p[0] || '', last: p.slice(1).join(' ') }; }
  var stripPunct = function (s) { return String(s || '').toLowerCase().replace(/[^\p{L}\p{N}]/gu, ''); };

  /* Builds hashed customer data for Google (user_data) and Meta (meta_user_data). Everything hashed once, here. */
  function customerData(c) {
    c = c || {};
    var ph = phoneParts(c.phone), nm = splitName(c.name), email = String(c.email || '').trim().toLowerCase();
    var street = String(c.address || '').trim().toLowerCase(), district = String(c.district || '').trim();
    return Promise.all([
      ph ? sha256(ph.e164) : '', email ? sha256(email) : '', nm.first ? sha256(nm.first) : '', nm.last ? sha256(nm.last) : '', street ? sha256(street) : '',
      ph ? sha256(ph.digits) : '', nm.first ? sha256(stripPunct(nm.first)) : '', nm.last ? sha256(stripPunct(nm.last)) : '', district ? sha256(stripPunct(district)) : '',
      sha256('bd'), sha256(uid)
    ]).then(function (r) {
      var g = { address: { country: 'BD' } };
      if (r[0]) g.sha256_phone_number = r[0];
      if (r[1]) g.sha256_email_address = r[1];
      if (r[2]) g.address.sha256_first_name = r[2];
      if (r[3]) g.address.sha256_last_name = r[3];
      if (r[4]) g.address.sha256_street = r[4];
      if (district) g.address.city = district; if (district) g.address.region = district;
      var m = { country: r[9], external_id: r[10] };
      if (r[5]) m.ph = r[5]; if (r[1]) m.em = r[1]; if (r[6]) m.fn = r[6]; if (r[7]) m.ln = r[7]; if (r[8]) m.ct = r[8];
      return { user_data: g, meta_user_data: m };
    });
  }

  /* ---------- identifiers sent with events ---------- */
  function ids() {
    var o = { fbp: getCookie('_fbp'), fbc: getCookie('_fbc') };
    var a = attr.last || {}, f = attr.first || {};
    KEYS.forEach(function (k) { if (a[k]) o[k] = a[k]; });
    if (f.utm_source) o.first_utm_source = f.utm_source;
    if (f.utm_campaign) o.first_utm_campaign = f.utm_campaign;
    ['_ga', '_gcl_aw', '_clck'].forEach(function (n) { var v = getCookie(n); if (v) o[n.replace(/^_/, '')] = v; });
    return o;
  }

  /* ---------- products ---------- */
  function item(slug, opt) {
    var P = (window.BG_PRODUCTS || {})[slug]; if (!P) return null; opt = opt || {};
    var it = { item_id: P.sku, item_name: P.title, item_brand: 'Babyglee', item_category: 'Kids sneakers', item_category2: 'Bounce series',
      item_variant: opt.size ? P.color + ' / EU ' + opt.size : P.color, price: BG.PRICE, discount: Math.max(0, (BG.LIST_PRICE || BG.PRICE) - BG.PRICE), quantity: opt.qty || 1 };
    if (opt.index != null) it.index = opt.index;
    if (opt.listId) { it.item_list_id = opt.listId; it.item_list_name = opt.listName || opt.listId; }
    return it;
  }
  var sum = function (items) { return items.reduce(function (a, i) { return a + i.price * i.quantity; }, 0); };

  /* ---------- pushing ---------- */
  function push(o) { window.dataLayer.push(o); }
  /* GA4 e-commerce push: clears the previous ecommerce object first, adds event_id (used to de-duplicate browser vs server events) */
  function ecom(name, ecommerce, extra) {
    ecommerce = ecommerce || {}; ecommerce.currency = CUR;
    push({ ecommerce: null });
    var o = { event: name, event_id: (extra && extra.event_id) || eventId(name), ecommerce: ecommerce };
    if (extra) for (var k in extra) if (k !== 'event_id') o[k] = extra[k];
    push(o); return o.event_id;
  }
  function pageData(type, section) {
    push({ event: 'page_data', page_type: type, site_section: section, currency: CUR, country: 'BD', language: 'en', store: 'babyglee', visitor: ids() });
  }

  /* ---------- debug overlay: add ?bgdebug=1 to any URL to see every dataLayer push on screen ---------- */
  if (/[?&]bgdebug=1/.test(location.search)) {
    var box = document.createElement('pre');
    box.style.cssText = 'position:fixed;left:0;right:0;bottom:0;max-height:38vh;overflow:auto;margin:0;padding:8px 10px;background:#111;color:#8ff0c8;font:11px/1.4 monospace;z-index:2147483647;white-space:pre-wrap';
    box.textContent = 'dataLayer debug (newest first)\n';
    document.addEventListener('DOMContentLoaded', function () { document.body.appendChild(box); });
    var orig = window.dataLayer.push.bind(window.dataLayer);
    window.dataLayer.push = function (o) { try { if (o && o.event && o.event.indexOf('gtm.') !== 0) box.textContent = 'dataLayer debug (newest first)\n' + o.event + ' ' + JSON.stringify(o).slice(0, 700) + '\n\n' + box.textContent.split('\n').slice(1).join('\n'); } catch (e) {} return orig(o); };
  }

  window.BGT = { push: push, ecom: ecom, item: item, sum: sum, pageData: pageData, customerData: customerData, ids: ids, eventId: eventId, sha256: sha256, phoneParts: phoneParts, getCookie: getCookie, uid: function () { return uid; }, attribution: function () { return attr; } };
})();
