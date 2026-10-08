<?php

namespace App\Domain\Documents\Support;

use App\Domain\Documents\Models\Document;
use App\Support\BusinessRuleException;

/**
 * The exact same file (same SHA-256) already exists for this dealer.
 */
class DuplicateDocument extends BusinessRuleException
{
    public ?Document $existing = null;

    public static function of(Document $existing): self
    {
        $exception = new self(__('This file is already stored as ":title".', ['title' => $existing->title]));
        $exception->existing = $existing;

        return $exception;
    }
}
