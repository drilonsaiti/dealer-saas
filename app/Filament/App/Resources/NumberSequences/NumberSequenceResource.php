<?php

namespace App\Filament\App\Resources\NumberSequences;

use App\Domain\Settings\Models\NumberSequence;
use App\Filament\App\Resources\NumberSequences\Pages\ManageNumberSequences;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Settings → Numbering: prefix/pattern and start number per document type.
 * Once numbers have been issued, the next number can only go up (no duplicates, no reuse).
 */
class NumberSequenceResource extends Resource
{
    protected static ?string $model = NumberSequence::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHashtag;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Number range');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Numbering');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                TextInput::make('pattern')
                    ->label(__('Pattern'))
                    ->helperText(__('{0000} = running number with leading zeros, {YYYY} = year, {YY} = two-digit year. Example: RE-{00000}'))
                    ->required()
                    ->maxLength(60)
                    ->live(onBlur: true)
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            if (! is_string($value) || ! NumberSequence::isValidPattern($value)) {
                                $fail(__('The pattern needs a {0000} placeholder for the running number.'));
                            }
                        },
                    ]),
                TextInput::make('next_value')
                    ->label(__('Next number'))
                    ->helperText(__('When you switch from another program, enter the number after the last one you used there.'))
                    ->numeric()
                    ->required()
                    ->live(onBlur: true)
                    ->minValue(fn (?NumberSequence $record): int => $record !== null && $record->hasIssuedNumbers() ? $record->getOriginal('next_value') : 1),
                Toggle::make('reset_yearly')
                    ->label(__('Start again at 1 every year'))
                    ->live(),
                Text::make(fn (Get $get, ?NumberSequence $record): string => __('Next number will be: :number', [
                    'number' => self::previewFromForm($get, $record),
                ])),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')->label(__('Document')),
                TextColumn::make('pattern')->label(__('Pattern'))->fontFamily('mono'),
                TextColumn::make('preview')
                    ->label(__('Next number'))
                    ->state(fn (NumberSequence $record): string => $record->preview())
                    ->fontFamily('mono'),
                IconColumn::make('reset_yearly')->label(__('Yearly reset'))->boolean(),
                TextColumn::make('issued_count')->label(__('Issued'))->numeric(),
            ])
            ->paginated(false)
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageNumberSequences::route('/'),
        ];
    }

    private static function previewFromForm(Get $get, ?NumberSequence $record): string
    {
        $pattern = (string) $get('pattern');

        if (! NumberSequence::isValidPattern($pattern)) {
            return '–';
        }

        $preview = new NumberSequence([
            'pattern' => $pattern,
            'next_value' => max(1, (int) $get('next_value')),
            'reset_yearly' => (bool) $get('reset_yearly'),
            'current_year' => $record?->current_year,
        ]);

        return $preview->preview();
    }
}
