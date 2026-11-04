# 0.1.0

Foundation release, not for live shops yet.

- Settings per sales channel: Chowdeck API key and webhook secret, pickup address and contact, item type, delivery PIN, customer notifications, declared order value, markup, fallback price, price cache time.
- Test connection shows your Chowdeck Relay wallet balance or Chowdeck's error. A test key (sk_test_…) uses the Chowdeck sandbox.
- Optional address lookup with Google Maps: addresses are turned into coordinates for more exact prices (needed in the Chowdeck sandbox); off or without a key, addresses are sent as text.
- Shipping method "Same-day rider delivery (Chowdeck)" created inactive; never deleted, only switched off on deactivation.
- Live Chowdeck price at checkout (plus your optional markup), cached per address. If an address cannot be priced, checkout explains why and the customer picks another method; if Chowdeck is unreachable, an optional fallback price applies and you get an admin notification.
- If Chowdeck cannot be reached, checkout stops asking it for a minute, so customers wait for one timeout per minute instead of one on every page.
- Chowdeck card on the order: price charged and current Chowdeck price, status, rider, delivery PIN and tracking link, with Request rider, Refresh status and Cancel delivery. If the price changed since checkout, you confirm the new price first; the customer's shipping price never changes.
- Tracking: the Chowdeck tracking link is stored as the order's tracking code and shown in the customer account and mails.
- Chowdeck webhooks keep the delivery and the order's delivery status up to date (rider assigned, picked up, delivered, cancelled). Webhooks are signed with your webhook secret; the settings page shows the URL to paste into Chowdeck and when the last webhook arrived.
- Ready-made, active flows: rider-on-the-way email to the customer, delivered email, rider-request-failed alert to you. Flow Builder action "Request Chowdeck rider" and triggers for every delivery status.
- Order permissions "Chowdeck rider deliveries" (view, edit).

<!--
Format notes (this file is rendered by the Shopware Store):

- One `# <version>` heading per release, newest first, separated by `---`.
- Bullets describe user-visible behaviour, not commits. "Refunds no longer
  exceed the captured amount", not "refactor RefundProcessor".
- `make changelog` renders this exactly as the Store will display it.
- Localised variants live in CHANGELOG_de-DE.md / CHANGELOG_fr-FR.md and must
  carry the same version headings.
-->
