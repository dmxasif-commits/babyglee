# Babyglee

Static, mobile-first website for Babyglee kids' sneakers (teal `#24babc` theme).

**Pages:** Home, Shop, five single-page-checkout landing pages (`bounce-*.html`, one per colour, for ads),
About, Contact, Privacy, Terms, Shipping & Returns.

## Edit
- Pages are generated: edit `tools/build.py`, then run `python3 tools/build.py`.
- Settings (price, delivery charges, WhatsApp support number, order endpoint, Meta Pixel ID): `assets/config.js`.
- Product photos and logo: `img/`.

## Checkout / backend status
The checkout form places the order on the page (order ID + confirmation). It posts each order as JSON to
`ORDER_ENDPOINT` in `assets/config.js`. **While that is empty the site is in demo mode: orders are NOT sent anywhere**
(the confirmation shows a demo notice). Connect a backend before running ads.

## Host
GitHub Pages: Settings → Pages → Deploy from branch → `main` / root.
