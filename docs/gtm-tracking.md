# Babyglee: Google Tag Manager & server-side tracking guide

All pixels (GA4, Meta Pixel + Conversions API, Google Ads, Microsoft Clarity) live **inside Google Tag Manager**.
The website only does three things: loads GTM, pushes clean e-commerce events to `window.dataLayer`, and prepares first-party
customer data (hashed) so event match quality is high.

Code: `assets/tracking.js` (data layer) and `assets/main.js` (where events fire). Settings: `assets/config.js`.

## 1. Turn it on
1. Create a **Web** container in GTM. Put its ID in `assets/config.js` → `GTM_ID: 'GTM-XXXXXXX'`.
2. For server-side tagging, create a **Server** container and map it to a first-party sub-domain (e.g. `sgtm.yourdomain.com`).
   Then set `GTM_SRC: 'https://sgtm.yourdomain.com/gtm.js'` so GTM loads from your own domain (better cookie lifetime, fewer blockers).
3. Without `GTM_ID` the events are still pushed to `dataLayer` (harmless) but nothing is sent anywhere.

**QA:** add `?bgdebug=1` to any page URL to see every dataLayer push on screen. Use GTM **Preview** mode as well.

## 2. Events the site pushes

Every e-commerce push first clears the old object (`{ ecommerce: null }`), then pushes the event, as Google recommends.
All values are in **BDT**. Every event has a unique `event_id`.

| Event | When | Notes |
|---|---|---|
| `page_data` | every page load | `page_type` (home, shop, product, cart, checkout, landing_product, info), `site_section` (store / landing), `currency`, `country`, `visitor` (fbp, fbc, UTMs, click IDs) |
| `view_item_list` | a product grid scrolls into view | `item_list_id`: `home_featured`, `shop`, `related` |
| `select_item` | tap a product card | list + item |
| `view_item` | product page / landing page load | |
| `add_to_cart` | Add to cart, Quick add, Buy now | Buy now also sends `buy_now: true` |
| `remove_from_cart` | remove / decrease in cart | |
| `view_cart` | cart page, or cart drawer opened | |
| `begin_checkout` | checkout page load (store); first form interaction (landing page) | |
| `add_shipping_info` | name, phone, district and address are all valid | `shipping_tier`, customer data attached |
| `add_payment_info` | order submitted | `payment_type: "Cash on Delivery"` |
| `purchase` | order accepted | see below. Fires once per order |
| `size_guide_open`, `select_size`, `quick_add_open`, `whatsapp_click` | UX events | custom events (not GA4 recommended events) |

**Item object** (GA4 format): `item_id` (SKU `BSTD-01-GREY`), `item_name`, `item_brand`, `item_category` (`Kids sneakers`),
`item_category2` (`Bounce series`), `item_variant` (`Grey / EU 28`), `price` (selling price), `discount`, `quantity`, `index`,
`item_list_id`, `item_list_name`.

**`purchase` push** (example):

```js
{
  event: 'purchase',
  event_id: 'purchase_BGW9G21R1',          // same id is stored in the order, use it for Meta CAPI de-duplication
  order_total: 1420,                        // products + delivery (use this as the Meta / Google Ads conversion value if you want "money collected")
  order_source: 'store' | 'landing',
  payment_type: 'Cash on Delivery', shipping_tier: 'Outside Dhaka',
  ecommerce: { currency: 'BDT', transaction_id: 'BGW9G21R1', value: 1290, shipping: 130, tax: 0, items: [ ... ] },
  user_data: { sha256_phone_number, sha256_email_address, address: { sha256_first_name, sha256_last_name, sha256_street, city, region, country: 'BD' } },
  meta_user_data: { ph, em, fn, ln, ct, country, external_id },   // all SHA-256 hashed, already normalised
  fbp, fbc, utm_source, utm_medium, utm_campaign, fbclid, gclid, gbraid, wbraid, ga, ...
}
```

GA4 `value` = products only (Google's rule: exclude shipping and tax). `order_total` is the cash the customer pays.

## 3. Customer data (event quality)

The site hashes customer data **once**, in the browser, with SHA-256 (hex). No plain personal data is pushed to the dataLayer.

| Data | Google (`user_data`) | Meta (`meta_user_data`) |
|---|---|---|
| Phone | E.164 with `+`, e.g. `+8801712345678`, then hashed (`sha256_phone_number`) | digits with country code, no `+`, e.g. `8801712345678`, then hashed (`ph`) |
| Email | trimmed, lowercase, hashed (`sha256_email_address`) | same hash (`em`). Optional field on the form |
| Name | lowercase, hashed (`address.sha256_first_name`, `sha256_last_name`) | `fn`, `ln` (lowercase, no punctuation, hashed) |
| Address | street hashed; `city`/`region` = district, `country` = `BD` | `ct` hashed, `country` = hash of `bd` |
| Visitor | | `external_id` = hash of a first-party visitor ID (cookie `bg_uid`, 400 days) |

Other identifiers sent with events: `fbp` (Meta browser cookie), `fbc` (built from `fbclid` when you arrive from a Meta ad),
UTMs, `gclid` / `gbraid` / `wbraid`, `ttclid`. First-touch and last-touch attribution is kept in `localStorage` (`bg_attr`).

**Hash only once.** Because the data is already hashed, tell each tag it is already hashed (Google: "data is already hashed" / `sha256_` keys;
Meta CAPI tag: pre-hashed). The Meta *browser* Pixel hashes plain values itself; to avoid double hashing, do not feed it `meta_user_data`.
Send the hashed values through the **server** CAPI tag instead.

## 4. GTM web container: what to create

**Variables (Data Layer Variable, version 2):** `event_id`, `order_total`, `order_source`, `page_type`, `fbp`, `fbc`, `user_data`,
`meta_user_data` (and `ecommerce` is read automatically by the GA4 tags).

**Triggers (Custom Event):** one per event above, e.g. `purchase`, `add_to_cart`, `begin_checkout`, `view_item`, `view_item_list`, `select_item`,
`remove_from_cart`, `view_cart`, `add_shipping_info`, `add_payment_info`, `whatsapp_click`.

**Tags**
1. **Google tag / GA4 configuration** (All Pages), with "Send ecommerce data" from the Data Layer. For server-side, set `server_container_url`.
2. **GA4 event tags** (or one tag with the event name from `{{Event}}`) for the e-commerce events. Pass `ecommerce` from the dataLayer.
3. **Meta Pixel** base (All Pages) and event tags: `ViewContent` (view_item), `AddToCart`, `InitiateCheckout` (begin_checkout),
   `AddPaymentInfo`, `Purchase`. Set the **Event ID** to `{{event_id}}` (field name can vary by template version) so Meta can de-duplicate it
   against the server event. Use `content_ids` = item SKUs, `content_type` = `product`, `currency` = `BDT`, `value` = `order_total`
   for Purchase if you want collected cash, or `ecommerce.value` for product value (choose one and keep it the same in the server tag).
4. **Google Ads conversion** (purchase) with `transaction_id`, value, currency, and **Enhanced conversions** reading `user_data`
   from the Data Layer ("Include user-provided data from your website").
5. **Microsoft Clarity** (All Pages). Add custom tags from `page_type`, `order_source`; fire a Clarity event on `purchase` if you like.
6. Optional custom tag on `whatsapp_click` (GA4 event / Meta `Contact`).

## 5. Server-side (sGTM) container

- **GA4 client** receives the GA4 hits from the web container.
- **GA4 tag** (server) forwards to Google. **Meta Conversions API tag** sends `Purchase`, `AddToCart`, `InitiateCheckout`, `ViewContent`:
  `event_id` from the event, `action_source: website`, `event_source_url`, client IP and user agent (from the request),
  `fbp`, `fbc`, and the pre-hashed `meta_user_data` fields (`em`, `ph`, `fn`, `ln`, `ct`, `country`, `external_id`).
- Browser (Pixel) and server events **must use the same `event_id`** and event name, so Meta counts them once.
- **Google Ads** conversion + enhanced conversions via the server tag if you prefer to keep it server-side.

## 6. Orders and the backend

Each order sent to `ORDER_ENDPOINT` contains a `tracking` block: `event_id` (`purchase_<order id>`), `fbp`, `fbc`, click IDs, UTMs,
`user_agent`, `page_url`, `referrer`, and the hashed `user_data` / `meta_user_data`. When the backend exists it can send the
**authoritative Purchase** to Meta CAPI (and later refunds/cancellations) using that same `event_id`.

This matters for Cash on Delivery: you can later send "delivered" or "cancelled" orders back to Meta / Google as offline or
server events, so the ad algorithm learns from real sales rather than from every placed order.

## 7. Go-live checklist
- [ ] `GTM_ID` (and `GTM_SRC` for server-side) set in `assets/config.js`
- [ ] Use GTM Preview with `?bgdebug=1`: the whole funnel fires, once each, with `BDT` and correct values
- [ ] Meta Events Manager: Pixel and CAPI both show `Purchase`, marked **Deduplicated**, good Event Match Quality
- [ ] Google Ads: enhanced conversions status OK; GA4 DebugView shows the ecommerce events
- [ ] Remove any hard-coded pixel from pages (the site has none; all tags come from GTM)
- [ ] Test an order on a phone, then check it in your backend

## References
- GA4 e-commerce: https://developers.google.com/analytics/devguides/collection/ga4/ecommerce
- Google Ads enhanced conversions (Google tag): https://support.google.com/google-ads/answer/9888145
- Meta Conversions API customer information parameters: https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters
