<?php

use Illuminate\Support\Facades\File;

/*
 * Every text shown to users must exist in German, French, Italian and English.
 * Fails CI when someone adds __('...') without translating it.
 */

function translationKeysUsedInCode(): array
{
    $keys = [];

    foreach (File::allFiles([app_path(), resource_path('views')]) as $file) {
        preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", $file->getContents(), $matches);

        foreach ($matches[1] as $key) {
            $keys[] = stripslashes($key);
        }
    }

    return array_values(array_unique($keys));
}

it('has every UI text translated in all four languages', function (string $locale) {
    $translations = json_decode(File::get(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);

    $missing = array_values(array_diff(translationKeysUsedInCode(), array_keys($translations)));

    expect($missing)->toBe([], "Missing in lang/{$locale}.json");
})->with(['de', 'fr', 'it', 'en']);

it('keeps the language files in sync', function () {
    $keys = fn (string $locale) => array_keys(json_decode(File::get(lang_path("{$locale}.json")), true));

    expect($keys('fr'))->toEqualCanonicalizing($keys('de'))
        ->and($keys('it'))->toEqualCanonicalizing($keys('de'))
        ->and($keys('en'))->toEqualCanonicalizing($keys('de'));
});

it('uses Swiss German spelling without ß', function () {
    expect(File::get(lang_path('de.json')))->not->toContain('ß');
});
