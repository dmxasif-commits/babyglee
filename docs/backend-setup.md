# Babyglee backend: cPanel setup guide

The backend is plain PHP + MySQL, so it runs on normal cPanel hosting (PHP 7.4 or newer, 8.x recommended).
It is part of the same folder as the website: upload everything together.

| Part | Path | What it does |
|---|---|---|
| Order API | `api/order.php` | Receives each order, re-checks it, calculates prices on the server, stores it |
| Live data | `api/live.php` | Gives the website its settings and products from the database |
| Admin panel | `admin/` | Orders, status, analytics, products, settings (GTM, delivery, Meta), backup |
| Product images | `uploads/` | Images uploaded from the admin panel |

## 1. Install (about 10 minutes)

1. **Create the database.** cPanel → *MySQL Databases*: create a database, create a user with a strong password, add the user to the database with **All privileges**.
2. **Upload the site.** Upload all files of this repository to `public_html` (File Manager → Upload the zip → Extract, or Git Version Control).
   Make sure hidden files are uploaded too: `.htaccess` is required.
3. **Create the config file.** Copy `api/config.sample.php` to `api/config.php` and fill in the database name, user and password,
   and write a long random text as `setup_key`.
4. **Create the admin password.** Open `https://yourdomain.com/admin/`. Type the setup key and choose a password (10+ characters).
   The tables and the five Bounce products are created automatically.
5. **Check it.** Open `https://yourdomain.com/assets/config.js`: it must start with `/* live from database */`.
   If you still see the static file, `.htaccess` was not uploaded or `mod_rewrite` is off (ask the host).
6. **Place a test order** on your phone, then find it in Admin → Orders.

Use **HTTPS** (cPanel → SSL/TLS Status → AutoSSL). The admin login cookie is only marked secure on HTTPS.

## 2. Daily use

- **Orders:** every new order is `Pending`. Call the customer, then set `Confirmed` → `Shipped` → `Delivered` (or `Cancelled` / `Returned`).
  Each change is saved in the order history with the time and your comment. Search by order ID, phone or name.
- **Dashboard:** orders and value per day, status, store vs landing pages, top products and sizes, traffic source (UTM), districts.
- **Products:** edit name, price, old price, USD price, colour, sizes in stock, image; hide or remove; add new products.
  A new product appears in the shop automatically and gets a page at `product.html?p=<slug>`.
- **Settings:** GTM container ID, delivery charges, WhatsApp number, Meta Conversions API, password.
  Website changes go live within about a minute.
- **Backup:** Settings → *Download backup (JSON)*. Do it regularly (it contains customer details, keep it private).
  Also use cPanel → *Backup* for a full database backup.

## 3. Things to know

- **Prices are decided by the server.** The browser only sends product, size and quantity; a changed price in the browser is ignored.
- **Landing pages** (`lp/*.html`) follow the price, sizes and name set in the admin for the five built-in products.
  A product added in the admin does not get a landing page automatically: ask for one to be built.
- **Editing page text or layout** still happens in `tools/build.py` (then `python3 tools/build.py`). The admin controls data, not page design.
- **Meta Conversions API:** fill Pixel ID + access token in Settings to send `Purchase` from the server with the same event ID as the browser.
  Use *Test event code* first and check Events Manager → Test events. If a server-side GTM container already sends Purchase, leave these empty.
  The Graph API version is a setting because Meta retires old versions: check the current one in Meta's documentation.
- **Security built in:** hashed admin password, login attempt limit, CSRF protection on every form, HttpOnly session cookie,
  all database queries are prepared statements, uploads accept only real JPG/PNG/WebP images and cannot run scripts,
  private files are blocked by `.htaccess`, order flood limit (8 per hour per connection).
- **Never** commit or share `api/config.php`.

## 4. Local testing (developers)

```bash
BG_CONFIG=/path/to/test-config.php php -S 127.0.0.1:8770 router.php
```

`test-config.php` can use SQLite: `['db' => ['driver' => 'sqlite', 'path' => '/tmp/bg.sqlite'], 'setup_key' => '...']`.
The router must map `/assets/config.js` and `/assets/products.js` to `api/live.php` (what `.htaccess` does on Apache).
