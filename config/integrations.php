<?php

/*
| External services (concept 11). Credentials are per dealer (Settings → Integrations); this file
| only holds the technical addresses.
|
| AutoScout24 Switzerland: the "DMS API" is documented only for customers on request
| (info@autoscout24.ch). The addresses and field names used by
| App\Domain\Integrations\Channels\AutoScout24\AutoScout24Mapper / AutoScout24Channel follow a
| common REST + OAuth2 shape and MUST be checked against that documentation before the first
| live use; everything AutoScout24-specific is in those two classes and the values below.
*/
return [
    'autoscout24' => [
        'base_url' => env('AUTOSCOUT24_BASE_URL', 'https://api.autoscout24.ch/dms/v1'),
        'token_url' => env('AUTOSCOUT24_TOKEN_URL', 'https://api.autoscout24.ch/oauth/token'),
        'paths' => [
            'listings' => '/sellers/{seller}/listings',
            'listing' => '/sellers/{seller}/listings/{id}',
        ],
        'page_size' => 100,
        'max_photos' => 30,
        'timeout' => 20,
    ],

    /*
    | Auto-i-DAT (vehicle identification, technical data, equipment, valuation). Licensed
    | service; the interface documentation comes with the contract. Addresses and field names
    | used by App\Domain\VehicleData\Providers\AutoIDat\* are an assumption to be checked.
    */
    'autoidat' => [
        'base_url' => env('AUTOIDAT_BASE_URL', 'https://api.auto-i-dat.ch/v1'),
        'paths' => [
            'vehicles' => '/vehicles',
            'vehicle' => '/vehicles/{id}',
            'valuation' => '/vehicles/{id}/valuation',
        ],
        'timeout' => 15,
    ],

    /*
    | WhatsApp Business Platform, Cloud API (Meta). The dealer's own phone number id, access
    | token and app secret (webhook signatures).
    */
    'whatsapp' => [
        'base_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
        'timeout' => 20,
        'service_window_hours' => 24, // free text only within 24 h after the customer's last message
    ],

    // Failed portal calls are retried after these pauses (seconds); then they stay "failed"
    // until the next change or the nightly catch-up (listings:sync).
    'retry_backoff' => [60, 300, 1800, 7200],
];
