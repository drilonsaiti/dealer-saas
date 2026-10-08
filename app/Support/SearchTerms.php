<?php

namespace App\Support;

/**
 * Turns what a user typed into a search box into parts for SQL: single words, LIKE
 * patterns and PostgreSQL prefix queries ("Kaufv" finds "Kaufvertrag").
 */
final class SearchTerms
{
    /**
     * "Kaufvertrag  Toyota Corolla" → ['Kaufvertrag', 'Toyota', 'Corolla'].
     *
     * @return list<string>
     */
    public static function words(string $search): array
    {
        $words = preg_split('/[\s"\']+/u', trim($search), flags: PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * Case-insensitive "contains" pattern, with LIKE wildcards in the word taken literally.
     */
    public static function like(string $word): string
    {
        return '%'.addcslashes(mb_strtolower($word), '\\%_').'%';
    }

    /**
     * to_tsquery('simple', …) that matches words starting with the given one, e.g.
     * "Kaufv" → "kaufv:*". Dots stay, so "683.737.537" matches the Stammnummer as written.
     * Null when nothing searchable is left.
     */
    public static function prefixQuery(string $word): ?string
    {
        // Strip the tsquery operators; PostgreSQL's parser does the rest.
        $tokens = preg_split('/[\s&|!()<>:*\'"\\\\]+/u', mb_strtolower($word), flags: PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter($tokens === false ? [] : $tokens, fn (string $token): bool => preg_match('/[\p{L}\p{N}]/u', $token) === 1));

        if ($tokens === []) {
            return null;
        }

        return implode(' & ', array_map(fn (string $token): string => $token.':*', $tokens));
    }

    public static function digits(string $word): string
    {
        return preg_replace('/\D/', '', $word) ?? '';
    }
}
