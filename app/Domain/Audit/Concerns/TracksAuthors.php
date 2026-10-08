<?php

namespace App\Domain\Audit\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Fills created_by / updated_by with the signed-in user (null in jobs, imports and seeders).
 *
 * @mixin Model
 */
trait TracksAuthors
{
    public static function bootTracksAuthors(): void
    {
        static::creating(function (Model $model): void {
            $userId = auth()->id();

            if ($userId !== null) {
                $model->setAttribute('created_by', $model->getAttribute('created_by') ?? $userId);
                $model->setAttribute('updated_by', $userId);
            }
        });

        static::updating(function (Model $model): void {
            $userId = auth()->id();

            if ($userId !== null) {
                $model->setAttribute('updated_by', $userId);
            }
        });
    }
}
