=== Dealer SaaS – Fahrzeugbestand ===
Requires at least: 6.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later

Shows the dealer's published vehicles from Dealer SaaS on the website, with detail pages and an enquiry form.

== Installation ==
1. Upload the folder "dealer-saas-stock" to wp-content/plugins and activate the plugin.
2. In Dealer SaaS: Settings → API tokens → New API token (permissions: read vehicles, send enquiries).
3. In WordPress: Settings → Dealer SaaS: API address (https://<your Dealer SaaS>/api/v1), token, detail page, language.
   "Verbindung testen" shows the dealer name when it works.
4. Create a page "Fahrzeuge" with [dealer_stock] and a page "Fahrzeug" with [dealer_vehicle]; enter the second one as
   detail page. A contact page can use [dealer_enquiry].

== Notes ==
* The vehicle data is cached for a few minutes (setting "Cache"); a reserved or sold car shows a badge.
* Enquiries go to Dealer SaaS (Anfragen) and to the dealer's e-mail; a hidden field stops simple spam bots.
* Photos load directly from Dealer SaaS through signed links.
