# Public API v1 and webhooks

For the dealer's website (the WordPress plugin in `integrations/wordpress/dealer-saas-stock`, or any
own website). Format: JSON:API (`application/vnd.api+json`). Base URL: `https://<host>/api/v1`.

## Authentication

Settings → API tokens → **New API token**. The token (`dsk_…`) is shown once; only its SHA-256 is stored.

```
Authorization: Bearer dsk_…
```

Permissions per token: `listings:read` (vehicles, dealer) and `enquiries:write` (enquiry form).
The token decides the dealer: a request can only ever see that dealer's data (row-level security).
Limits: 120 requests per minute per token; enquiries 10 per minute per token and 5 per hour per visitor IP.

## Endpoints

| Method | Path | What |
|---|---|---|
| GET | `/vehicles` | Published cars: available, reserved, and sold within the last 7 days |
| GET | `/vehicles/{id}` | One car |
| GET | `/dealer` | Name, address, phone, e-mail, website |
| POST | `/enquiries` | Enquiry form |
| GET | `/photos/...` | Photo (signed URL from the vehicle data, no token, valid 30 days) |

Query parameters of `/vehicles`: `lang=de|fr|it|en` (or `Accept-Language`), `filter[make]`, `filter[fuel]`,
`filter[body_type]`, `filter[price_max]` (CHF), `filter[availability]=available|reserved|sold`,
`sort=price|-price|published_at|-published_at|mileage|-mileage`, `page[number]`, `page[size]` (max 100).

Vehicle attributes: `title`, `description` (simple HTML: p, br, strong, em, ul, ol, li), `highlights`, `availability`,
`price` (`{amount: "21900.00", currency: "CHF"}` or `null` when hidden), `make`, `model`, `variant`, `body_type`, `fuel`,
`transmission`, `drive`, `first_registration` (YYYY-MM), `mileage_km`, `power_kw`, `power_hp`, `doors`, `seats`,
`color_exterior`, `color_interior`, `equipment`, `mfk_due`, `photos` (`[{url, cover}]`), `published_at`, `updated_at`.

`POST /enquiries` body: `name`, `email` and/or `phone`, `message`, optional `vehicle_id`, `locale`, `visitor_ip`
(the visitor's IP when sent server-side) and `website` (honeypot, must be empty). Answer `201`.
The contact is found by e-mail or phone, otherwise created; the dealer gets an e-mail.

Errors: `{"errors": [{"status": "401", "title": "...", "detail": "..."}]}`.

## Webhooks

Settings → Webhooks: an https address and the events. Every event is a POST with JSON:

```json
{"id": "…", "event": "vehicle.sold", "created_at": "2026-10-10T09:00:00+02:00", "data": {…}}
```

Events: `vehicle.listed`, `vehicle.reserved`, `vehicle.sold`, `vehicle.unlisted`, `enquiry.received`, `invoice.paid`.

Headers: `X-Dealer-Event`, `X-Dealer-Delivery` (id, for de-duplication) and
`X-Dealer-Signature: t=<unix time>,v1=<hex>` where `v1 = HMAC-SHA256(secret, "<t>.<raw body>")`.
Check the signature and that `t` is recent (e.g. 5 minutes):

```php
[$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $_SERVER['HTTP_X_DEALER_SIGNATURE']));
$ok = hash_equals(hash_hmac('sha256', $t.'.'.file_get_contents('php://input'), $secret), $v1) && abs(time() - (int) $t) < 300;
```

Answer with any 2xx. Failed deliveries are retried after 1, 5, 15, 60 minutes and 6 hours; every attempt is in the
delivery log (Settings → Webhooks → open). After 20 failures in a row the address is switched off.
