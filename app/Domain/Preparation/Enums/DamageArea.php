<?php

namespace App\Domain\Preparation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where on the car a damage is.
 */
enum DamageArea: string implements HasLabel
{
    case Front = 'front';
    case Rear = 'rear';
    case Left = 'left';
    case Right = 'right';
    case Roof = 'roof';
    case Interior = 'interior';
    case Glass = 'glass';
    case Wheels = 'wheels';
    case Engine = 'engine';
    case Underbody = 'underbody';

    public function getLabel(): string
    {
        return match ($this) {
            self::Front => __('Front'),
            self::Rear => __('Rear'),
            self::Left => __('Left side'),
            self::Right => __('Right side'),
            self::Roof => __('Roof'),
            self::Interior => __('Interior'),
            self::Glass => __('Glass'),
            self::Wheels => __('Wheels / tyres'),
            self::Engine => __('Engine / technology'),
            self::Underbody => __('Underbody'),
        };
    }
}
