#!/usr/bin/env python3
"""Generates the static Babyglee site into the repo root (run: python3 tools/build.py)."""
import json, pathlib

OUT = pathlib.Path(__file__).resolve().parent.parent
BASE = "https://dmxasif-commits.github.io/babyglee/"  # change if you move to a custom domain
PRICE, LIST = 1290, 1800
PRICE_USD = 15  # international price, no discount (1,800 BDT list price at ~123 BDT/USD = $14.63)
WA = "8801974974973"
PHONE_DISPLAY = "+880 1974-974973"

PRODUCTS = [
    dict(slug="bounce-sky-blue", img="sky-blue-yellow", name="Sky Blue & Yellow", short="Sky Blue", color="#3fb4e8"),
    dict(slug="bounce-blue-beige", img="blue-beige", name="Blue & Beige", short="Blue & Beige", color="#3d7fa5"),
    dict(slug="bounce-grey", img="grey", name="Grey", short="Grey", color="#8a9096"),
    dict(slug="bounce-navy-brown", img="navy-brown", name="Navy & Brown", short="Navy & Brown", color="#26335a"),
    dict(slug="bounce-black", img="black", name="Black", short="Black", color="#262626"),
]

ICONS = {
 "truck": '<path d="M3 7h11v9H3z"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="7" cy="18" r="1.8"/><circle cx="17" cy="18" r="1.8"/>',
 "cash": '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/>',
 "exchange": '<path d="M4 8h13l-3-3"/><path d="M20 16H7l3 3"/>',
 "shield": '<path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="M9 12l2 2 4-4"/>',
 "ruler": '<rect x="3" y="8" width="18" height="8" rx="1.5"/><path d="M7 8v3M11 8v4M15 8v3M19 8v4"/>',
 "phone": '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 005 5L15 13l5 2v4a2 2 0 01-2 2A16 16 0 013 6a2 2 0 012-2z"/>',
 "whatsapp": '<path d="M4 20l1.3-4A8 8 0 1112 20a8 8 0 01-3.7-.9z"/><path d="M9 9.5c.4 2.2 2.6 4.4 5 5l1.2-1.2-2-1-.8.8a4 4 0 01-2-2l.8-.8-1-2z"/>',
 "mail": '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
 "pin": '<path d="M12 21s-7-6-7-11a7 7 0 0114 0c0 5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
 "layers": '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
 "cushion": '<path d="M4 15c0-4 3-7 8-7s8 3 8 7v2H4z"/><path d="M8 12.5c1-.8 2.2-1.2 4-1.2"/>',
 "expand": '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>',
 "bolt": '<path d="M13 2L5 14h6l-1 8 8-12h-6z"/>',
 "grid": '<path d="M4 8h16M4 12h16M4 16h16"/><path d="M8 5v14M12 5v14M16 5v14"/>',
 "menu": '<path d="M4 7h16M4 12h16M4 17h16"/>',
 "close": '<path d="M6 6l12 12M18 6L6 18"/>',
 "check": '<path d="M5 12l5 5 9-10"/>',
 "facebook": '<path d="M14 8h3V4h-3a4 4 0 00-4 4v3H7v4h3v6h4v-6h3l1-4h-4V8.5z"/>',
 "chev": '<path d="M6 9l6 6 6-6"/>',
 "clock": '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 "star": '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>',
}
SPRITE = '<svg width="0" height="0" style="position:absolute" aria-hidden="true">' + "".join(
    f'<symbol id="i-{k}" viewBox="0 0 24 24">{v}</symbol>' for k, v in ICONS.items()) + "</svg>"

def ic(n, cls=""):
    return f'<svg class="ic {cls}" aria-hidden="true"><use href="#i-{n}"/></svg>'

def fmt(n):
    return f"৳{n:,}"

# ---------- size data ----------
def age_band(eu):
    if eu <= 23: return "≈ 1–2 yrs"
    if eu <= 26: return "≈ 2–4 yrs"
    if eu <= 29: return "≈ 4–6 yrs"
    if eu <= 32: return "≈ 6–8 yrs"
    return "≈ 8–10 yrs"

def size_table():
    rows = "".join(
        f"<tr><td><b>EU {eu}</b></td><td>{eu/1.5-1.5:.1f} cm</td><td>{age_band(eu)}</td></tr>" for eu in range(21, 37))
    return (f'<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Shoe size</th><th>Foot length</th><th>Age (approx.)</th></tr></thead>'
            f'<tbody>{rows}</tbody></table></div>'
            '<p class="fine">Measure from heel to the longest toe. Sizes are approximate, so our team also confirms the size with you by call or message before shipping.</p>')

# ---------- shared chrome ----------
FAVICON = ("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='16' fill='%2324babc'/%3E"
           "%3Ctext x='32' y='46' font-size='40' font-family='Arial' font-weight='700' text-anchor='middle' fill='white'%3Eb%3C/text%3E%3C/svg%3E")

def head(title, desc, page, img="img/sky-blue-yellow.webp", extra=""):
    return f'''<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
<meta name="description" content="{desc}">
<meta property="og:title" content="{title}">
<meta property="og:description" content="{desc}">
<meta property="og:type" content="website">
<meta property="og:image" content="{BASE}{img}">
<meta name="theme-color" content="#24babc">
<link rel="icon" href="{FAVICON}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fredoka:wght@500;600;700&family=Hind+Siliguri:wght@400;500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
{extra}
</head>
<body data-page="{page}">
{SPRITE}
'''

def header(active):
    def a(href, label, key):
        return f'<a href="{href}"{" class=on" if active == key else ""}>{label}</a>'
    return f'''<div class="topbar"><div class="wrap"><span>{ic("pin")} Shipping to Bangladesh</span><span>Designed for European markets</span><span>{ic("cash")} Cash on Delivery</span><span>{ic("exchange")} 72-hour exchange</span></div></div>
<header class="hdr"><div class="wrap hdr-in">
  <a class="brand" href="index.html" aria-label="Babyglee home"><img src="img/logo.webp" alt="Babyglee — Because we love them!" width="117" height="87"></a>
  <nav class="nav" id="nav" aria-label="Main">
    {a("index.html","Home","home")}{a("shop.html","Shop","shop")}{a("about.html","About us","about")}{a("contact.html","Contact","contact")}
    <a class="btn sm nav-cta" href="shop.html">Shop now</a>
  </nav>
  <div class="ship" id="ship">
    <button class="ship-btn" id="shipBtn" aria-expanded="false" aria-controls="shipMenu"><span class="cc-code">BD</span><span class="ship-t"><small>Shipping to</small>Bangladesh · ৳ BDT</span><svg class="ic chev" aria-hidden="true"><use href="#i-chev"/></svg></button>
    <div class="ship-menu" id="shipMenu" hidden>
      <p class="sm-h">Shipping destination</p>
      <div class="sm-opt on"><span class="cc-code">BD</span><b>Bangladesh</b>{ic("check")}</div>
      <p class="sm-n">Prices on this site are in Bangladeshi taka (৳ BDT). The international price in USD is shown for reference.</p>
      <ul class="sm-l"><li>{ic("truck")} Home delivery</li><li>{ic("cash")} Cash on Delivery</li><li>{ic("exchange")} 72-hour exchange</li></ul>
    </div>
  </div>
  <button class="burger" id="burger" aria-label="Open menu" aria-expanded="false">{ic("menu")}</button>
</div></header>
<main>
'''

def footer():
    return f'''</main>
<footer class="ftr">
  <div class="wrap ftr-grid">
    <div class="ftr-brand">
      <img src="img/logo.webp" alt="Babyglee" width="117" height="87">
      <p>An international kids' sneaker brand, designed for European markets and now shipping to Bangladesh.</p>
      <div class="soc"><a href="https://facebook.com/Babyglee.Official" target="_blank" rel="noopener" aria-label="Babyglee on Facebook">{ic("facebook")}</a>
      <a href="https://wa.me/{WA}" target="_blank" rel="noopener" aria-label="WhatsApp">{ic("whatsapp")}</a></div>
    </div>
    <div><h4>Shop</h4>{"".join(f'<a href="{p["slug"]}.html">Bounce — {p["short"]}</a>' for p in PRODUCTS)}</div>
    <div><h4>Company</h4><a href="about.html">About us</a><a href="contact.html">Contact us</a><a href="shipping-returns.html">Shipping &amp; returns</a><a href="privacy.html">Privacy policy</a><a href="terms.html">Terms &amp; conditions</a></div>
    <div><h4>Get in touch</h4>
      <a href="https://wa.me/{WA}">{ic("whatsapp")} {PHONE_DISPLAY}</a>
      <a href="mailto:info@babygleebd.com">{ic("mail")} info@babygleebd.com</a>
      <span class="ln">{ic("pin")} Pallabi, Mirpur 12, Dhaka</span>
      <span class="ln">{ic("truck")} Shipping to Bangladesh</span>
      <span class="ln">{ic("clock")} 9 AM – 7 PM (BST)</span></div>
  </div>
  <div class="wrap ftr-bottom"><span>© 2025 Babyglee® — A concern of AMC Knit Composite Ltd.</span><span>Trademark Reg. No. 210926 (Class 25) · Trade licence TRAD/DNCC/090476/2022</span></div>
</footer>
<script src="assets/config.js"></script>
<script src="assets/main.js"></script>
</body>
</html>
'''

def write(name, html):
    (OUT / name).write_text(html, encoding="utf-8")

# ---------- reusable blocks ----------
TRUST = [("cash", "Cash on Delivery", "Pay when it arrives"), ("truck", "Fast home delivery", "Across Bangladesh"),
         ("exchange", "72-hour exchange", "Easy size swaps"), ("shield", "Kid-safe materials", "REACH-compliant")]

def trust_strip():
    return '<section class="trust"><div class="wrap trust-grid">' + "".join(
        f'<div class="tr">{ic(i)}<div><b>{t}</b><span>{s}</span></div></div>' for i, t, s in TRUST) + "</div></section>"

def card(p):
    return f'''<a class="pcard" href="{p["slug"]}.html">
  <div class="pic"><img src="img/{p["img"]}.webp" alt="Babyglee Bounce sneaker — {p["name"]}" loading="lazy"></div>
  <div class="pbody"><span class="sw" style="background:{p["color"]}"></span><h3>Bounce — {p["name"]}</h3>
  <p class="pmeta">Kids' casual sneaker · EU 21–36</p>
  <div class="prow"><span class="price"><s>{fmt(LIST)}</s> <b>{fmt(PRICE)}</b></span><span class="go">View &amp; order →</span></div>
  <p class="usd-line">US${PRICE_USD} <span>international price</span></p></div>
</a>'''

FEATURES = [
 ("layers", "Multi-density sole", "Cushions every step and helps protect growing joints."),
 ("cushion", "Memory-foam insole", "Soft Ortholite-style insole for all-day comfort."),
 ("expand", "Wide toe box", "Shaped around natural foot development."),
 ("bolt", "Fast-lock strap", "Velcro strap with stretch bands, so kids can put them on alone."),
 ("grid", "Anti-slip rubber tread", "Shock-absorbing grip for playgrounds, courts and parks."),
 ("shield", "Safe materials", "Microfiber leather and breathable mesh; REACH, phthalate and azo-dye limits."),
]

def feature_cards():
    return '<div class="feat-grid">' + "".join(
        f'<article class="feat tilt"><span class="ficon">{ic(i)}</span><h3>{t}</h3><p>{d}</p></article>' for i, t, d in FEATURES) + "</div>"

FAQ = [
 ("How does delivery work?", "We deliver to your home across the areas we serve. Our team calls or messages you to confirm your size and address before shipping."),
 ("Can I pay on delivery?", "Yes. Cash on Delivery is available on every order."),
 ("What if the size doesn't fit?", "You can exchange within 72 hours of delivery. Delivery and return charges apply. See our Shipping &amp; Returns page."),
 ("Which sizes do you have?", "The Bounce series is available in EU sizes 21 to 36, suitable for roughly ages 1 to 10."),
 ("Are the shoes safe for kids?", "They are made from microfiber synthetic leather and breathable mesh, with chemical limits for REACH, phthalates and azo dyes."),
]

def faq_block():
    return '<div class="faq">' + "".join(f"<details><summary>{q}</summary><p>{a}</p></details>" for q, a in FAQ) + "</div>"

def faq_ld():
    return ('<script type="application/ld+json">' + json.dumps({"@context": "https://schema.org", "@type": "FAQPage", "mainEntity": [
        {"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a.replace("&amp;", "&")}} for q, a in FAQ]}) + "</script>")

# ---------- pages ----------
def page_home():
    hero_a, hero_b = PRODUCTS[0], PRODUCTS[2]
    h = head("Babyglee — International Kids' Sneakers, Shipping to Bangladesh", "International kids' sneaker brand designed for European markets, now shipping to Bangladesh. EU sizes 21–36, Cash on Delivery and 72-hour exchange.", "home", extra=faq_ld())
    h += header("home")
    h += f'''<section class="hero">
  <div class="blob b1"></div><div class="blob b2"></div>
  <div class="wrap hero-in">
    <div class="hero-copy">
      <span class="pill">International brand · Shipping to Bangladesh</span>
      <h1>Happy feet.<br><span class="grad">Bouncy steps.</span></h1>
      <p class="lead">International kids' sneakers, designed for European markets and now shipping to Bangladesh: memory-foam insole, wide toe box and an anti-slip sole that parents can trust.</p>
      <div class="cta"><a class="btn" href="shop.html">Shop the Bounce series</a><a class="btn ghost" href="#size">Find my kid's size</a></div>
      <div class="price-tag"><s>{fmt(LIST)}</s><b>{fmt(PRICE)}</b><span>Cash on Delivery</span></div>
      <p class="usd-note">International price: <b>US${PRICE_USD}</b></p>
      <p class="fine bd-note">Prices for Bangladesh shown in BDT (৳).</p>
    </div>
    <div class="stage" id="stage">
      <div class="disc"></div>
      <img class="shoe s-main" data-depth="46" src="img/{hero_a["img"]}.webp" alt="Babyglee Bounce sneakers in sky blue and yellow" fetchpriority="high">
      <img class="shoe s-side" data-depth="80" src="img/{hero_b["img"]}.webp" alt="Babyglee Bounce sneakers in grey" loading="lazy">
      <div class="chip c1">{ic("bolt")} Fast-lock strap</div>
      <div class="chip c2">{ic("cushion")} Memory-foam insole</div>
    </div>
  </div>
</section>
{trust_strip()}
<section class="sec"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">Babyglee® Bounce series</span><h2>Pick your favourite colour</h2><p>Five colours, one super-comfy design. Every pair comes in EU sizes 21–36.</p></div>
  <div class="grid five">{"".join(card(p) for p in PRODUCTS)}</div>
  <p class="center"><a class="btn ghost" href="shop.html">See the full shop</a></p>
</div></section>
<section class="sec intl"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">An international brand</span><h2>Designed for Europe. Delivered in Bangladesh.</h2><p>Prices on this site are for Bangladesh, in taka (৳ BDT). The international price in USD is shown for reference.</p></div>
  <div class="feat-grid">
    <article class="feat tilt"><span class="ficon">{ic("layers")}</span><h3>Built to European standards</h3><p>Designed in-house for European markets: EU sizes 21–36 and chemical limits for REACH, phthalates and azo dyes.</p></article>
    <article class="feat tilt"><span class="ficon">{ic("pin")}</span><h3>EU office in Italy</h3><p>Babyglee has an EU office in Pontedera, Italy, and a registered trademark (Reg. No. 210926).</p></article>
    <article class="feat tilt"><span class="ficon">{ic("truck")}</span><h3>Shipping to Bangladesh</h3><p>Stock held in Dhaka for home delivery across Bangladesh. Pay in BDT, Cash on Delivery.</p></article>
  </div>
</div></section>
<section class="sec alt"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">Why parents choose Babyglee</span><h2>Comfort you can feel, quality you can trust</h2></div>
  {feature_cards()}
</div></section>
<section class="sec" id="size"><div class="wrap size-box">
  <div>
    <span class="eyebrow">Size finder</span><h2>Find the right size in seconds</h2>
    <p class="sub">Measure your child's foot from heel to longest toe, then slide to match.</p>
    <label class="sl" for="cm">Foot length: <b id="cmOut">14.0</b> cm</label>
    <input type="range" id="cm" min="12" max="23" step="0.5" value="14">
    <div class="size-res"><span>Suggested size</span><b id="sizeOut">EU 23</b><em id="ageOut">≈ 1–2 yrs</em></div>
    <p class="fine">Approximate. We also confirm size with you by call or message before shipping.</p>
  </div>
  <div class="size-img"><img src="img/{PRODUCTS[1]["img"]}.webp" alt="Babyglee Bounce sneakers in blue and beige" loading="lazy"></div>
</div></section>
<section class="sec alt"><div class="wrap about-teaser">
  <div><span class="eyebrow">Our story</span><h2>Because we love them</h2>
  <p>Babyglee started in 2016 with a simple idea: kids deserve comfortable, well-made shoes at honest prices. Our sneakers are designed in-house for European markets, with an EU office in Italy, and are now delivered to families across Bangladesh. Every pair is made with foot development in mind, from materials tested against international chemical-safety limits.</p>
  <a class="btn ghost" href="about.html">More about Babyglee</a></div>
  <ul class="checks"><li>{ic("check")} Designed in-house for European markets</li><li>{ic("check")} Registered trademark (Reg. No. 210926)</li><li>{ic("check")} Every batch inspected before packing</li><li>{ic("check")} EU office in Italy, support team in Dhaka</li></ul>
</div></section>
<section class="sec"><div class="wrap narrow"><div class="sec-head"><span class="eyebrow">FAQ</span><h2>Quick answers</h2></div>{faq_block()}</div></section>
<section class="cta-band"><div class="wrap"><h2>Questions? Talk to a real person.</h2><p>We reply 9 AM – 7 PM (BST).</p>
  <div class="cta center"><a class="btn wa" href="https://wa.me/{WA}" target="_blank" rel="noopener">{ic("whatsapp")} WhatsApp us</a><a class="btn ghost light" href="contact.html">Contact details</a></div></div></section>
'''
    h += footer()
    write("index.html", h)

def page_shop():
    h = head("Shop — Babyglee Bounce Kids' Sneakers", "Shop the Babyglee Bounce series: five colours, EU sizes 21–36, Cash on Delivery across Bangladesh.", "shop")
    h += header("shop")
    h += f'''<section class="page-hero"><div class="wrap"><span class="eyebrow">Shop</span><h1>Babyglee® Bounce series</h1>
<p>Kids' casual sneakers with breathable mesh upper, velcro strap and anti-slip shock-absorbing sole. EU 21–36.</p></div></section>
<section class="sec"><div class="wrap">
  <div class="grid five">{"".join(card(p) for p in PRODUCTS)}</div>
</div></section>
{trust_strip()}
<section class="sec alt"><div class="wrap narrow"><div class="sec-head"><span class="eyebrow">Size guide</span><h2>Not sure about the size?</h2></div>{size_table()}</div></section>
'''
    h += footer()
    write("shop.html", h)

def page_product(p):
    url = BASE + p["slug"] + ".html"
    ld = {"@context": "https://schema.org", "@type": "Product", "name": f"Babyglee Bounce — {p['name']}", "image": BASE + f"img/{p['img']}.webp",
          "brand": {"@type": "Brand", "name": "Babyglee"}, "description": "Kids' casual sneaker with breathable mesh upper, velcro strap and anti-slip shock-absorbing sole.",
          "offers": {"@type": "Offer", "eligibleRegion": "BD", "url": url, "priceCurrency": "BDT", "price": str(PRICE), "availability": "https://schema.org/InStock"}}
    extra = f'<script type="application/ld+json">{json.dumps(ld)}</script>'
    h = head(f"Babyglee Bounce — {p['name']} | Kids' Sneakers {fmt(PRICE)}", f"Babyglee Bounce kids' sneakers in {p['name']}. EU 21–36, memory-foam insole, anti-slip sole. {fmt(PRICE)} with Cash on Delivery.", "product", img=f"img/{p['img']}.webp", extra=extra)
    h += header("shop").replace('<a class="btn sm nav-cta" href="shop.html">Shop now</a>', '<a class="btn sm nav-cta" href="#order">Order now</a>')
    sizes = "".join(f'<label class="szc"><input type="radio" name="size" value="{n}" required><span>{n}</span></label>' for n in range(21, 37))
    h += f'''<div class="wrap crumbs"><a href="index.html">Home</a> / <a href="shop.html">Shop</a> / <span>Bounce — {p["name"]}</span></div>
<section class="pdp" data-slug="{p["slug"]}" data-name="Babyglee Bounce — {p["name"]}" data-img="img/{p["img"]}.webp">
  <div class="wrap pdp-in">
    <div class="pdp-stage tilt-stage" id="stage"><div class="disc"></div><img class="shoe" data-depth="50" src="img/{p["img"]}.webp" alt="Babyglee Bounce sneakers — {p["name"]}" fetchpriority="high"><span class="badge">Save {round((LIST-PRICE)/LIST*100)}%</span></div>
    <div class="pdp-info">
      <span class="pill">Babyglee® Bounce · BSTD-01</span>
      <h1>Bounce — {p["name"]}</h1>
      <p class="pmeta">Kids' casual sneaker · EU 21–36 · Ages 1–10</p>
      <p class="pl">Price for Bangladesh (BDT)</p>
      <div class="price-tag lg"><s>{fmt(LIST)}</s><b>{fmt(PRICE)}</b><span>You save {fmt(LIST-PRICE)}</span></div>
      <p class="usd-note">International price: <b>US${PRICE_USD}</b> <span>USD price is shown for reference. You pay in BDT on delivery.</span></p>
      <ul class="bul">
        <li>{ic("check")} Breathable mesh &amp; microfiber synthetic leather upper</li>
        <li>{ic("check")} Velcro strap with stretch bands, so kids can put them on alone</li>
        <li>{ic("check")} Anti-slip rubber sole with shock absorption</li>
        <li>{ic("check")} Memory-foam insole &amp; wide toe box for growing feet</li>
      </ul>
      <a class="btn lg block-m" href="#order">Order now</a>
      <div class="mini-trust"><span>{ic("cash")} Cash on Delivery</span><span>{ic("exchange")} 72-hr exchange</span><span>{ic("truck")} Home delivery</span></div>
    </div>
  </div>
</section>
{trust_strip()}
<section class="sec" id="order"><div class="wrap narrow-lg">
  <div class="sec-head"><span class="eyebrow">Secure checkout</span><h2>Order now</h2><p>Delivering to Bangladesh. Fill in your details and we'll call to confirm your size and delivery. Pay in BDT on delivery.</p></div>
  <div class="co-grid">
    <form id="orderForm" novalidate>
      <div class="fld"><label for="f-name">Your name</label><input id="f-name" name="name" autocomplete="name" required><small class="err"></small></div>
      <div class="fld"><label for="f-phone">Mobile number</label><input id="f-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="01XXXXXXXXX" required><small class="err"></small></div>
      <div class="fld"><label for="f-country">Country</label><div class="country"><span class="cc-code">BD</span><input id="f-country" name="country" value="Bangladesh" readonly aria-readonly="true"></div><small class="fine">We currently deliver to Bangladesh.</small></div>
      <div class="fld"><label for="f-addr">Full address</label><textarea id="f-addr" name="address" rows="3" autocomplete="street-address" placeholder="House, road, area, thana, district" required></textarea><small class="err"></small></div>
      <fieldset class="fld"><legend>Delivery area</legend>
        <div class="opts"><label class="opt"><input type="radio" name="area" value="dhaka" checked><span><b>Inside Dhaka</b><em id="chD"></em></span></label>
        <label class="opt"><input type="radio" name="area" value="outside"><span><b>Outside Dhaka</b><em id="chO"></em></span></label></div></fieldset>
      <fieldset class="fld"><legend>Shoe size (EU) <a href="#sizes" class="lnk">Size guide</a></legend>
        <div class="szs">{sizes}</div><small class="err" id="sizeErr"></small></fieldset>
      <div class="fld inl"><label>Quantity</label><div class="qty"><button type="button" id="qm" aria-label="Decrease">−</button><output id="qv">1</output><button type="button" id="qp" aria-label="Increase">+</button></div></div>
      <p class="err-box" id="sendErr" role="alert" hidden>Sorry, we couldn't place your order. Please check your connection and try again, or call us on {PHONE_DISPLAY}.</p>
      <button class="btn lg block" type="submit" id="submitBtn">Confirm order</button>
      <p class="fine center">{ic("shield")} Cash on Delivery. Your details are used only to deliver your order.</p>
    </form>
    <aside class="summary" aria-label="Order summary">
      <div class="sum-head"><img src="img/{p["img"]}.webp" alt=""><div><b>Bounce — {p["name"]}</b><span id="sSize">Size: select</span></div></div>
      <dl><div><dt>Price × <span id="sQty">1</span></dt><dd id="sSub"></dd></div><div><dt>Delivery</dt><dd id="sDel"></dd></div><div class="tot"><dt>Total (COD)</dt><dd id="sTot"></dd></div></dl>
      <p class="fine">Prefer to talk first? <a href="https://wa.me/{WA}" target="_blank" rel="noopener">Chat on WhatsApp</a></p>
    </aside>
  </div>
  <div class="done" id="done" hidden>
    <span class="okc">{ic("check")}</span><h3>Thank you! Your order is placed.</h3>
    <p>Order ID <b id="oid"></b></p>
    <dl class="recap"><div><dt>Product</dt><dd id="rP"></dd></div><div><dt>Size</dt><dd id="rS"></dd></div><div><dt>Deliver to</dt><dd id="rA"></dd></div><div class="tot"><dt>Pay on delivery</dt><dd id="rT"></dd></div></dl>
    <p>Our team will call you on <b id="rPh"></b> to confirm your size and delivery. Please keep your phone nearby.</p>
    <p class="demo" id="demoNote" hidden>Demo mode: ordering is not connected yet, so this order was not sent to the Babyglee team.</p>
    <p class="fine">Need help? <a href="https://wa.me/{WA}" target="_blank" rel="noopener">Chat on WhatsApp</a> or call {PHONE_DISPLAY}</p>
    <a class="btn ghost" href="shop.html">Continue shopping</a>
  </div>
</div></section>
<section class="sec alt"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">Built for little feet</span><h2>Why parents love the Bounce</h2></div>{feature_cards()}</div></section>
<section class="sec" id="sizes"><div class="wrap narrow"><div class="sec-head"><span class="eyebrow">Size guide</span><h2>Choose the right size</h2></div>{size_table()}</div></section>
<section class="sec alt"><div class="wrap">
  <div class="sec-head"><span class="eyebrow">More colours</span><h2>You may also like</h2></div>
  <div class="grid">{"".join(card(q) for q in PRODUCTS if q is not p)}</div></div></section>
<section class="sec"><div class="wrap narrow"><div class="sec-head"><span class="eyebrow">FAQ</span><h2>Quick answers</h2></div>{faq_block()}</div></section>
<div class="sticky-cta" id="stickyCta"><div><s>{fmt(LIST)}</s> <b>{fmt(PRICE)}</b></div><a class="btn" href="#order">Order now</a></div>
'''
    h += footer()
    write(p["slug"] + ".html", h)

def simple_page(fname, title, desc, key, eyebrow, h1, body):
    h = head(f"{title} — Babyglee", desc, key) + header(key)
    h += f'<section class="page-hero"><div class="wrap"><span class="eyebrow">{eyebrow}</span><h1>{h1}</h1></div></section><section class="sec"><div class="wrap narrow prose">{body}</div></section>'
    write(fname, h + footer())

def page_about():
    body = f'''<p>Babyglee makes comfortable, safe and affordable sneakers for children aged 1 to 10. Our shoes are designed in-house for European markets, with the sizes, materials and details that growing feet actually need, and are now delivered to families across Bangladesh.</p>
<h2>Our story</h2><p>Babyglee was founded in 2016 on a simple belief: kids deserve well-made shoes at honest prices. Our tagline says it best: <em>Because we love them!</em> Every design starts with comfort and foot development, then adds the colours and details kids love.</p>
<h2>What goes into every pair</h2>
<ul class="checks plain"><li>{ic("check")} Multi-density sole for joint protection</li><li>{ic("check")} Memory-foam insole for all-day comfort</li><li>{ic("check")} Wide toe box designed with foot development in mind</li><li>{ic("check")} Fast-lock velcro strap so children can put shoes on alone</li><li>{ic("check")} Anti-slip rubber tread for playgrounds, courts and skate parks</li></ul>
<h2>Quality &amp; safety</h2><p>We follow chemical limits for REACH, phthalates and azo dyes, and test for flex resistance, outsole abrasion and strap tensile strength. Every batch is inspected before packing, and batch testing is available on request.</p>
<h2>Design &amp; production</h2><p>Designed with our design partner Bengalstitch and produced in Bangladesh. Babyglee is a registered trademark in Bangladesh (Reg. No. 210926, Class 25) and operates under trade licence TRAD/DNCC/090476/2022. Babyglee is a concern of AMC Knit Composite Ltd.</p>
<h2>Where to find us</h2><p>EU office: Viale R. Piaggio 5/a, 56025 Pontedera, Italy. Dhaka office: Pallabi, Mirpur 12. Factory: Chittagong, Bangladesh. Need help choosing a size? <a href="contact.html">Talk to our team</a>.</p>
<p><a class="btn" href="shop.html">Shop the Bounce series</a></p>'''
    simple_page("about.html", "About Us", "Babyglee makes comfortable, safe and affordable kids' sneakers designed and made in Bangladesh since 2016.", "about", "About us", "Because we love them", body)

def page_contact():
    cards = f'''<div class="contact-grid">
<a class="cc" href="https://wa.me/{WA}" target="_blank" rel="noopener">{ic("whatsapp")}<b>WhatsApp &amp; phone</b><span>{PHONE_DISPLAY}<br>+880 1601-261620</span></a>
<a class="cc" href="mailto:info@babygleebd.com">{ic("mail")}<b>Email</b><span>info@babygleebd.com</span></a>
<a class="cc" href="https://facebook.com/Babyglee.Official" target="_blank" rel="noopener">{ic("facebook")}<b>Facebook</b><span>Babyglee.Official</span></a>
<div class="cc">{ic("pin")}<b>Dhaka office</b><span>Pallabi, Mirpur 12, Dhaka</span></div>
<div class="cc">{ic("pin")}<b>EU office</b><span>Viale R. Piaggio 5/a<br>56025 Pontedera, Italy</span></div>
<div class="cc">{ic("clock")}<b>Support hours</b><span>9 AM – 7 PM (BST)</span></div></div>
<p class="center fine">Not sure which size to order? Send us your child's foot length and we'll help.</p>'''
    simple_page("contact.html", "Contact Us", "Contact Babyglee by WhatsApp, phone or email. Dhaka office at Pallabi, Mirpur 12.", "contact", "Contact", "We'd love to hear from you", cards)

def page_shipping():
    body = '''<p class="fine">Last updated: 2025</p>
<h2>Shipping destination</h2><p>Babyglee is an international brand, and we currently ship to <b>Bangladesh</b>. Prices are in Bangladeshi taka (৳ BDT).</p>
<h2>Delivery</h2><p>We deliver to your home across the areas we serve in Bangladesh. After you place an order, our team contacts you by call or message to confirm your child's size and your delivery address, and then ships the parcel.</p>
<ul><li>Inside Dhaka and outside Dhaka delivery charges are shown at checkout before you confirm.</li><li>Delivery usually takes a few working days depending on your location.</li><li>Please keep your phone available so the courier can reach you.</li></ul>
<h2>Payment</h2><p>Cash on Delivery is available on all orders: you pay the courier when you receive your parcel. Please check the parcel before paying.</p>
<h2>Exchange</h2><p>If the size doesn't fit, you can request an exchange within <b>72 hours</b> of delivery.</p>
<ul><li>Shoes must be unused, clean and in their original packaging.</li><li>Delivery and return charges apply to exchanges.</li><li>Contact us by WhatsApp or phone with your order details to start an exchange.</li></ul>
<h2>Damaged or wrong item</h2><p>If your parcel arrives damaged or with the wrong item, contact us within 72 hours with photos and your order details, and we will make it right.</p>
<h2>Contact</h2><p>WhatsApp / phone: +880 1974-974973 · Email: info@babygleebd.com</p>'''
    simple_page("shipping-returns.html", "Shipping & Returns", "Babyglee delivery, Cash on Delivery and 72-hour exchange policy.", "shipping", "Policy", "Shipping to Bangladesh", body)

def page_privacy():
    body = '''<p class="fine">Last updated: 2025</p>
<p>This policy explains how Babyglee ("we", "us") collects and uses your information when you visit babygleebd.com or place an order.</p>
<h2>Information we collect</h2><ul><li><b>Order details:</b> your name, mobile number, delivery address, chosen product, size and quantity.</li><li><b>Messages:</b> anything you send us by WhatsApp, phone or email.</li><li><b>Usage data:</b> basic, anonymous information about how the site is used (pages visited, device type), which may be collected through cookies and analytics or advertising tools such as Meta Pixel.</li></ul>
<h2>How we use it</h2><ul><li>To confirm, deliver and support your order, including calling or messaging you about size and delivery.</li><li>To handle exchanges and customer service requests.</li><li>To understand how our site and ads perform and to improve them.</li></ul>
<h2>Sharing</h2><p>We share your name, phone number and address only with courier and delivery partners so your parcel can reach you. We do not sell your personal information.</p>
<h2>Cookies &amp; advertising</h2><p>We may use cookies and similar technologies, including the Meta Pixel, to measure ad performance and show relevant ads. You can control cookies in your browser settings.</p>
<h2>Data retention &amp; security</h2><p>We keep order information for as long as needed to fulfil your order and meet legal and accounting requirements. We take reasonable steps to protect your information.</p>
<h2>Your choices</h2><p>You may ask us to access, correct or delete your personal information by contacting us. We will respond as soon as we reasonably can.</p>
<h2>Children</h2><p>Our products are for children, but our website is intended for parents and guardians. We do not knowingly collect information from children.</p>
<h2>Contact</h2><p>Babyglee, Pallabi, Mirpur 12, Dhaka · info@babygleebd.com · +880 1974-974973</p>'''
    simple_page("privacy.html", "Privacy Policy", "How Babyglee collects, uses and protects your information.", "privacy", "Policy", "Privacy policy", body)

def page_terms():
    body = '''<p class="fine">Last updated: 2025</p>
<h2>Using this website</h2><p>By using babygleebd.com or placing an order, you agree to these terms. Please read them with our Privacy Policy and Shipping &amp; Returns policy.</p>
<h2>Products &amp; prices</h2><p>We try to show colours and details accurately, but screens differ slightly. Prices are in Bangladeshi Taka (BDT) and may change without notice. The price shown at the time you place your order applies. USD prices are shown for reference only; orders are charged in BDT.</p>
<h2>Orders</h2><ul><li>An order is confirmed after our team contacts you to verify your details and size.</li><li>We may cancel an order if details cannot be verified or an item is unavailable.</li><li>Please provide a correct phone number and address so delivery is not delayed.</li></ul>
<h2>Payment</h2><p>Payment is by Cash on Delivery unless we tell you otherwise.</p>
<h2>Exchanges</h2><p>Exchanges are handled under our <a href="shipping-returns.html">Shipping &amp; Returns</a> policy (72 hours from delivery; delivery and return charges apply).</p>
<h2>Intellectual property</h2><p>Babyglee® is a registered trademark in Bangladesh (Reg. No. 210926, Class 25). Website content, images and designs belong to Babyglee and may not be copied without permission.</p>
<h2>Liability</h2><p>To the extent permitted by law, we are not liable for indirect or consequential loss arising from use of this website or our products. Nothing limits your rights under applicable consumer law.</p>
<h2>Governing law</h2><p>These terms are governed by the laws of Bangladesh.</p>
<h2>Contact</h2><p>Babyglee, Pallabi, Mirpur 12, Dhaka · info@babygleebd.com · +880 1974-974973</p>'''
    simple_page("terms.html", "Terms & Conditions", "Terms and conditions for using the Babyglee website and placing orders.", "terms", "Policy", "Terms &amp; conditions", body)

if __name__ == "__main__":
    OUT.mkdir(exist_ok=True)
    page_home(); page_shop(); page_about(); page_contact(); page_shipping(); page_privacy(); page_terms()
    for p in PRODUCTS:
        page_product(p)
    print("built", len(list(OUT.glob("*.html"))), "pages")
