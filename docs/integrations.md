# Portal integrations

## AutoScout24 (Switzerland)

**Status:** built against an assumed API shape. AutoScout24 CH documents its DMS API only to
customers (info@autoscout24.ch). Before the first live use, compare the documentation with:

| What | Where |
|---|---|
| Base and token address, paths (`/sellers/{seller}/listings`, `/sellers/{seller}/listings/{id}`) | `config/integrations.php` (`AUTOSCOUT24_BASE_URL`, `AUTOSCOUT24_TOKEN_URL`) |
| Login (OAuth2 client credentials, bearer token, cached until shortly before expiry, one retry on 401) | `AutoScout24Channel::token()` / `send()` |
| Field names and value lists (fuel, gearbox, body) | `AutoScout24Mapper::toPortal()` / `fromPortal()` |
| Photos: sent as permanent signed URLs of our photo endpoint (the portal downloads them) | `AutoScout24Mapper::toPortal()` |
| Answer: `id` (or `listingId`) and `url` of the created vehicle; list answers in `items` / `data` with `totalPages` | `AutoScout24Channel::result()` / `stock()` |

Nothing outside these two classes and the config knows AutoScout24 details. If AutoScout24 only offers the
file import (AS24i / FTP), a second `ListingChannel` implementation replaces the REST calls; the rest stays.

## How syncing works

- Per dealer one account per portal (`integration_accounts`, credentials encrypted).
- `ListingSync` is called when a listing is published, edited, withdrawn and when the vehicle file changes status;
  it queues `SyncListingPublication` per active account (after the commit, de-duplicated).
- The job decides: published and available (or reserved, unless "remove reserved") → create or update;
  otherwise remove. The payload hash skips unchanged listings. A 404 on update creates the vehicle again.
- Errors: 429 / 5xx / network are retried (1, 5, 30, 120 minutes); 4xx are shown at once. The publication shows
  "Failed" with the reason on the vehicle file; every call is in `integration_logs`.
- `listings:sync` (nightly 03:20) queues all listings again; unchanged ones cost no call.
- Drafts are never sent. Imported cars stay drafts until the file is released and published; then the existing
  portal entry is updated, not duplicated.

## Auto-i-DAT (vehicle data)

**Status:** built against an assumed API shape; the interface documentation comes with the Auto-i-DAT licence.

| What | Where |
|---|---|
| Base address, paths (`/vehicles?typeApproval=&vin=`, `/vehicles/{id}/valuation?firstRegistration=&mileage=`) | `config/integrations.php` (`AUTOIDAT_BASE_URL`) |
| Authentication (bearer API key + `X-Customer-Number`) | `AutoIDatProvider::get()` |
| Field names and code lists (body, fuel, gearbox, drive, equipment, prices) | `AutoIDatMapper` |

Lookups are cached for 10 minutes per query (the dialog asks several times), every call is in the sync log.
Taking over data never overwrites filled fields unless "overwrite" is ticked; only the options the user ticks are added
to the equipment. Valuations are kept per vehicle file with date and mileage (`vehicle_valuations`).

## Warranty providers (NSA, MultiPart …)

Neither offers a public API, so registrations and claims go by e-mail (`EmailWarrantyGateway`): a PDF (registration
form or claim report) is filed in the vehicle file and an e-mail draft to the provider is prepared in the inbox, with the
claim documents attached. A person checks and sends it. The route is chosen per warranty product (`submission`); an API
adapter implementing `WarrantyProviderGateway` can be added per provider later without changing the screens.

## WhatsApp Business (Cloud API)

Uses Meta's public Cloud API (`config/integrations.php`, `WHATSAPP_GRAPH_URL`, default `v21.0`).

1. In Meta Business: WhatsApp → API setup → phone number ID; a system user with a permanent token (`whatsapp_business_messaging`);
   App settings → App secret.
2. Settings → Integrations → Connect service → WhatsApp Business: enter the three values, active.
3. The integration page shows the **webhook address** and **verify token**: enter both in the Meta app
   (WhatsApp → Configuration → Webhook) and subscribe to `messages`.

Incoming webhooks are accepted only with a valid `X-Hub-Signature-256` (HMAC with the app secret). Free text replies are
possible within 24 hours after the customer's last message; message templates (needed later) are not built yet.
