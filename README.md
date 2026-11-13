# Babyglee

Static, mobile-first website for Babyglee kids' sneakers (teal `#24babc` theme).

**Two separate parts (not mixed):**

1. **Regular store** (normal e-commerce): `index.html`, `shop.html` → `product-<colour>.html` (choose size, Add to cart or Buy now)
   → cart drawer / `cart.html` → `checkout.html`. Also About, Contact, Privacy, Terms, Shipping & Returns.
2. **Ad landing pages** (single-product, single-page checkout, no site navigation, `noindex`): `lp/sky-blue.html`,
   `lp/blue-beige.html`, `lp/grey.html`, `lp/navy-brown.html`, `lp/black.html`. They link only to the policy pages and never to the shop.

## Edit
- Pages are generated: edit `tools/build.py`, then run `python3 tools/build.py` (it also writes `assets/products.js`).
- Mobile shopping UX: floating WhatsApp and cart buttons, size guide and Quick add bottom sheets, sticky buy bar on product pages, Buy now checks out one item without touching the cart.
- Settings (price, delivery charges, WhatsApp support number, order endpoint, Meta Pixel ID): `assets/config.js`.
- Product photos and logo: `img/`.

## Tracking
All pixels live in Google Tag Manager. The site pushes GA4 e-commerce events and hashed customer data to `dataLayer`. Setup guide: [`docs/gtm-tracking.md`](docs/gtm-tracking.md). Add `?bgdebug=1` to any URL to see the events on screen.

## Backend (cPanel, PHP + MySQL)
Order API (`api/`), admin panel (`admin/`: orders, status, analytics, products, settings, backup) and uploaded images (`uploads/`).
Setup guide: [`docs/backend-setup.md`](docs/backend-setup.md). On a static host (GitHub Pages) the backend does not run and the site stays in demo mode.

## Checkout / backend status
The checkout form places the order on the page (order ID + confirmation). It posts each order as JSON to
`ORDER_ENDPOINT` in `assets/config.js`. **While that is empty the site is in demo mode: orders are NOT sent anywhere**
(the confirmation shows a demo notice). Connect a backend before running ads.

## Host
GitHub Pages: Settings → Pages → Deploy from branch → `main` / root.

## Changelog
Newest first. Every change is recorded here (see `CLAUDE.md`).

### 2026-11-14: invoices, stickers, purchase price and profit, ads (UTM) analytics
- **Backup first:** `backups/site-2026-11-14-before-invoices.zip`.
- **A4 invoice and 3 × 4 inch courier sticker** (`admin/index.php?p=print`, `admin/print.css`): address, product details, COD amount, courier and tracking ID; print one order or many at once.
- **Bulk actions** on the orders list: tick orders, then print invoices / stickers or change status for all of them.
- **Purchase price** per product (private, never sent to the website). The cost is saved on each order; profit is shown on the order, the dashboard and the ads page.
- **Ads & UTM page** (`admin/index.php?p=utm`): orders per campaign, ad set, ad / creative and source, split into pending, confirmed, delivered and cancelled, with cancel %, delivered value and estimated profit.
- **Courier and tracking ID** on each order; **customer history** (earlier orders and cancellations from the same phone number) on the order and as a flag in the list.
- **Settings:** business name, phone, address and footer note for invoices.
- **Order spam limit** raised to 30 per hour per address and made configurable (`order_limit_per_hour` in `api/config.php`), because mobile networks share addresses.
- **Database:** upgrades itself from version 1 to 2 (new columns: `products.cost`, `orders.cost`, `orders.courier`, `orders.tracking_code`). Existing orders are kept.
- Files: `admin/index.php`, `admin/admin.css`, `admin/print.css`, `api/lib.php`, `api/order.php`, `api/config.sample.php`, `docs/backend-setup.md`.

### 2026-11-13: backend, admin panel, live catalogue
- **Backup first:** `backups/site-2026-11-13-before-backend.zip` (the live site before this work).
- **Backend added** (`api/`): order API with server-side validation and pricing, duplicate and flood protection; live settings/products (`api/live.php`); optional Meta Conversions API Purchase (`api/capi.php`).
- **Admin panel added** (`admin/`): login and first-time setup, orders list with search and status filter, order detail with status history and notes, dashboard analytics, product add / edit / remove with image upload and sizes in stock, settings (GTM ID, delivery charges, WhatsApp, Meta CAPI, password), CSV export and JSON backup.
- **Storefront connected to the backend:** per-product price, old price, USD price and sizes; product grids, product pages, landing pages and quick add follow admin data; new `product.html` template for products added in the admin; checkout shows the server's error message and total.
- **Site settings** moved to the database when the backend is present (`.htaccess` serves `assets/config.js` and `assets/products.js` from `api/live.php`).
- **Mobile UX:** floating WhatsApp and cart buttons, size guide and Quick add bottom sheets, sticky buy bar, Buy now (single item, cart untouched), collapsible checkout summary, sticky Place order button, district dropdown, optional email.
- **Tracking:** GTM loader and full GA4 e-commerce data layer with hashed customer data (`assets/tracking.js`, `docs/gtm-tracking.md`).
- **Docs and rules:** `docs/backend-setup.md`, `CLAUDE.md` (changelog + backup rules).
- Files: `api/*`, `admin/*`, `.htaccess`, `uploads/.htaccess`, `.gitignore`, `assets/main.js`, `assets/tracking.js`, `assets/style.css`, `assets/config.js`, `tools/build.py`, generated pages.

### 2026-11-13: hidden list of ad landing links
- `lp/index.html` with copy buttons (not linked from anywhere, noindex).

### 2026-11-09: store and landing pages split
- Regular store (product pages, cart, checkout) and five separate single-page-checkout landing pages in `lp/`.

### 2026-11-09: first version
- Home, shop, policies, international positioning (English copy, BDT and USD prices, Shipping to Bangladesh).
