/* Babyglee site settings: edit these values, no other code changes needed. */
window.BG = {
  WHATSAPP: '8801974974973',          // floating WhatsApp button + support links
  PRICE: 1290,                        // selling price (BDT)
  PRICE_USD: 15,                      // display only: international price in USD, no discount (rate used: ~123 BDT per USD)
  LIST_PRICE: 1800,                   // crossed-out price (BDT)
  DELIVERY: { dhaka: 80, outside: 130 }, // TODO: confirm delivery charges (BDT), placeholders
  ORDER_ENDPOINT: '',                 // REQUIRED before going live: backend URL that receives each order as JSON (empty = demo mode)
  GTM_ID: '',                         // Google Tag Manager web container, e.g. 'GTM-XXXXXXX'. All pixels/tags (GA4, Meta, Google Ads, Clarity) live inside GTM
  GTM_SRC: ''                         // optional: first-party / server-side GTM loader URL, e.g. 'https://sgtm.yourdomain.com/gtm.js'
};
