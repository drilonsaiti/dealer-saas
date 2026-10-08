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

];
