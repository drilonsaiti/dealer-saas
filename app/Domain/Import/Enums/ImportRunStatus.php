<?php

namespace App\Domain\Import\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ImportRunStatus: string implements HasColor, HasLabel
{
    case Uploaded = 'uploaded';
    case DryRunning = 'dry_running';
    case Checked = 'checked';
    case Committing = 'committing';
    case Committed = 'committed';
    case RolledBack = 'rolled_back';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Uploaded => __('Uploaded'),
            self::DryRunning => __('Checking…'),
            self::Checked => __('Checked – ready to import'),
            self::Committing => __('Importing…'),
            self::Committed => __('Imported'),
            self::RolledBack => __('Rolled back'),
            self::Failed => __('Failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Uploaded => 'gray',
            self::DryRunning => 'info',
            self::Checked => 'warning',
            self::Committing => 'info',
            self::Committed => 'success',
            self::RolledBack => 'gray',
            self::Failed => 'danger',
        };
    }

    public function isBusy(): bool
    {
        return in_array($this, [self::DryRunning, self::Committing], true);
    }
}
