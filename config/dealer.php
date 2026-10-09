<?php

return [

    /*
    | Languages offered in the UI and for customer documents.
    | Formatting (numbers, dates, CHF) always follows Swiss conventions.
    */
    'locales' => ['de', 'fr', 'it', 'en'],

    'locale_names' => [
        'de' => 'Deutsch',
        'fr' => 'Français',
        'it' => 'Italiano',
        'en' => 'English',
    ],

    'currency' => 'CHF',

    /*
    | Two-factor authentication is required for administrators and accounting (see
    | Role::requiresMultiFactorAuthentication()) and can be required for everyone else per
    | dealer. This master switch is on by default everywhere, including production.
    | Turn it off only in your own local .env (DEALER_ENFORCE_MFA=false) when the six-digit
    | code gets in the way of manual testing — never in a shared or deployed environment.
    */
    'enforce_mfa' => env('DEALER_ENFORCE_MFA', true),

    /*
    | Documents: stored under tenants/{tenant_id}/documents/ on this disk (S3-compatible
    | object storage in production). OCR languages are those installed for Tesseract.
    */
    'documents' => [
        'disk' => env('DOCUMENTS_DISK', 'local'),
        'max_upload_kb' => (int) env('DOCUMENTS_MAX_UPLOAD_KB', 20480),
        'ocr_languages' => env('OCR_LANGUAGES', 'deu+fra+ita+eng'),
    ],

    'gotenberg_url' => env('GOTENBERG_URL'),

    /*
    | Own simple electronic signature (technical concept 8.5).
    | - code_channel: how a customer signing by link gets the one-time code: "email" or "sms"
    |   (sms needs a provider; until one is connected, "log" writes the SMS to the log).
    | - seal: every signed PDF is sealed (PAdES) with the platform certificate through pyHanko.
    |   Without seal_key/seal_cert a self-signed certificate is created (fine for testing; use a
    |   certificate from a trusted CA in production). tsa_url adds a trusted RFC 3161 timestamp.
    */
    'signatures' => [
        'link_valid_days' => (int) env('SIGNATURE_LINK_VALID_DAYS', 14),
        'code_channel' => env('SIGNATURE_CODE_CHANNEL', 'email'),
        'sms_driver' => env('SIGNATURE_SMS_DRIVER', 'log'),
        'pyhanko' => env('SIGNATURE_PYHANKO', 'pyhanko'),
        'seal_key' => env('SIGNATURE_SEAL_KEY'),
        'seal_cert' => env('SIGNATURE_SEAL_CERT'),
        'tsa_url' => env('SIGNATURE_TSA_URL'),
    ],

    /*
    | VAT export (eCH-0217 v2, ESTV "MWST-Abrechnung pro"). Put eCH-0217-2-0-0.xsd from
    | https://www.ech.ch/fr/ech/ech-0217/2.0.0 (Beilagen) into resources/schemas/ (or point
    | VAT_ECH0217_XSD elsewhere): every export is then validated and refused if invalid. Without the
    | file the export is marked "not validated". The schema imports eCH-0058 and eCH-0108 by URL.
    */
    'vat' => [
        'ech0217_xsd' => env('VAT_ECH0217_XSD') ?: base_path('resources/schemas/eCH-0217-2-0-0.xsd'),
    ],

    /*
    | Imports can run for many minutes (a 1 GB document folder). In production they go to a
    | queue connection whose retry_after is longer than the job (redis-long / database-long).
    | Empty: the default connection.
    */
    'imports' => [
        'queue_connection' => env('IMPORT_QUEUE_CONNECTION'),
    ],

];
