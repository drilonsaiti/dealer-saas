<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\Support\AiException;
use App\Domain\Ai\Support\Assistant;
use App\Domain\Listings\Actions\SaveListing;
use App\Domain\Vehicles\Models\StockCycle;

/**
 * Suggests the advert texts (title, description, highlights) in the dealer's languages from
 * the vehicle file's facts only. No customer data is sent; nothing is saved here — the
 * suggestion fills the listing form and a person edits it.
 */
class WriteListingText
{
    public function __construct(private readonly Assistant $assistant) {}

    /**
     * @return array{title: array<string, string>, description: array<string, string>, highlights: list<string>}
     */
    public function __invoke(StockCycle $cycle, ?string $notes = null): array
    {
        $locales = array_values((array) config('dealer.locales'));
        $answer = $this->assistant->ask('listing_text', $this->system($locales), $this->prompt($cycle, $notes), $cycle, 2500);

        return $this->parse($answer, $locales);
    }

    /**
     * @param  list<string>  $locales
     */
    private function system(array $locales): string
    {
        return implode("\n", [
            'You write used-car adverts for a Swiss car dealer (website and AutoScout24).',
            'Use only the facts given. Never invent equipment, history, condition, warranties or numbers. Do not mention the price.',
            'Tone: factual, friendly, trustworthy; short paragraphs; no exaggerations, no exclamation marks, no emojis.',
            'German is Swiss German: never use "ß" (write "ss"), use Swiss terms (MFK, Fahrzeugausweis, Occasion).',
            'French and Italian as used in Switzerland. Numbers with Swiss formatting (e.g. 48\'000 km).',
            'Answer with JSON only, no other text, exactly this shape:',
            '{"title": {'.implode(', ', array_map(fn (string $l): string => "\"{$l}\": \"...\"", $locales)).'}, "description": {'.implode(', ', array_map(fn (string $l): string => "\"{$l}\": \"...\"", $locales)).'}, "highlights": ["...", "..."]}',
            'title: at most 80 characters (make, model, version, the most important feature). description: 2–4 short paragraphs separated by a blank line. highlights: 3–6 short points in German.',
        ]);
    }

    private function prompt(StockCycle $cycle, ?string $notes): string
    {
        $vehicle = $cycle->vehicle;
        $facts = array_filter([
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'version' => $vehicle->variant,
            'body' => $vehicle->body_type?->getLabel(),
            'fuel' => $vehicle->fuel?->getLabel(),
            'gearbox' => $vehicle->transmission?->getLabel(),
            'drive' => $vehicle->drive?->getLabel(),
            'power_kw' => $vehicle->power_kw,
            'power_hp' => $vehicle->power_kw !== null ? (int) round($vehicle->power_kw * 1.36) : null,
            'displacement_cc' => $vehicle->displacement_cc,
            'doors' => $vehicle->doors,
            'seats' => $vehicle->seats,
            'colour_outside' => $vehicle->color_exterior,
            'colour_inside' => $vehicle->color_interior,
            'first_registration' => $vehicle->first_registration_on?->format('m.Y'),
            'mileage_km' => $cycle->mileage_in,
            'last_mfk' => $vehicle->mfk_last_on?->format('m.Y'),
            'last_service' => $vehicle->service_last_on?->format('m.Y'),
            'equipment' => $vehicle->equipment ?? [],
            'description_by_dealer' => array_filter($vehicle->getTranslations('description')) ?: null,
            'notes_by_dealer' => $notes,
        ], fn ($v): bool => $v !== null && $v !== [] && $v !== '');

        return "Vehicle facts (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param  list<string>  $locales
     * @return array{title: array<string, string>, description: array<string, string>, highlights: list<string>}
     */
    private function parse(string $answer, array $locales): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start === false || $end === false ? null : json_decode(substr($answer, $start, $end - $start + 1), true);

        if (! is_array($data) || ! is_array($data['title'] ?? null) || ! is_array($data['description'] ?? null)) {
            throw new AiException(__('The AI answer could not be read. Please try again.'));
        }

        $result = ['title' => [], 'description' => [], 'highlights' => []];

        foreach ($locales as $locale) {
            $title = trim(strip_tags((string) ($data['title'][$locale] ?? '')));
            $text = trim((string) ($data['description'][$locale] ?? ''));

            if ($locale === 'de') {
                $title = str_replace('ß', 'ss', $title);
                $text = str_replace('ß', 'ss', $text);
            }

            if ($title !== '') {
                $result['title'][$locale] = mb_substr($title, 0, 120);
            }

            if ($text !== '') {
                $paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n/', strip_tags($text)) ?: []));
                $result['description'][$locale] = SaveListing::cleanHtml(implode('', array_map(fn (string $p): string => '<p>'.e($p).'</p>', $paragraphs)));
            }
        }

        $result['highlights'] = array_values(array_slice(array_filter(array_map(
            fn ($h): string => mb_substr(str_replace('ß', 'ss', trim(strip_tags((string) $h))), 0, 60),
            (array) ($data['highlights'] ?? []),
        )), 0, 8));

        if (! isset($result['title']['de'])) {
            throw new AiException(__('The AI answer could not be read. Please try again.'));
        }

        return $result;
    }
}
