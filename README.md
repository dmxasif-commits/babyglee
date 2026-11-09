# Babyglee

Static, mobile-first website for Babyglee kids' sneakers (teal `#24babc` theme).

**Two separate parts (not mixed):**

1. **Regular store** (normal e-commerce): `index.html`, `shop.html` → `product-<colour>.html` (choose size, Add to cart or Buy now)
   → cart drawer / `cart.html` → `checkout.html`. Also About, Contact, Privacy, Terms, Shipping & Returns.
2. **Ad landing pages** (single-product, single-page checkout, no site navigation, `noindex`): `lp/sky-blue.html`,
   `lp/blue-beige.html`, `lp/grey.html`, `lp/navy-brown.html`, `lp/black.html`. They link only to the policy pages and never to the shop.

## Edit
- Pages are generated: edit `tools/build.py`, then run `python3 tools/build.py` (it also writes `assets/products.js`).
- Settings (price, delivery charges, WhatsApp support number, order endpoint, Meta Pixel ID): `assets/config.js`.
- Product photos and logo: `img/`.

## Checkout / backend status
The checkout form places the order on the page (order ID + confirmation). It posts each order as JSON to
`ORDER_ENDPOINT` in `assets/config.js`. **While that is empty the site is in demo mode: orders are NOT sent anywhere**
(the confirmation shows a demo notice). Connect a backend before running ads.

## Host
GitHub Pages: Settings → Pages → Deploy from branch → `main` / root.
