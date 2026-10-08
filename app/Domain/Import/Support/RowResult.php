<?php

namespace App\Domain\Import\Support;

use App\Domain\Import\Enums\ImportRowAction;
use Illuminate\Database\Eloquent\Model;

/**
 * What an importer did (or would do) with one row.
 */
final class RowResult
{
    public ImportRowAction $action = ImportRowAction::Skip;

    /** @var list<string> */
    public array $messages = [];

    public ?Model $record = null;

    /** @var list<array{0: string, 1: string}> */
    public array $created = [];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly string $ref, public readonly array $payload) {}

    public function action(ImportRowAction $action, ?Model $record = null): self
    {
        $this->action = $action;
        $this->record = $record ?? $this->record;

        return $this;
    }

    public function note(string $message): self
    {
        $this->messages[] = $message;

        return $this;
    }

    public function created(Model $model): Model
    {
        $this->created[] = [$model->getMorphClass(), (string) $model->getKey()];

        return $model;
    }
}
