<x-filament-panels::page>
    <div wire:poll.3s="$refresh" @class(['hidden' => ! $this->getRecord()->status->isBusy()])></div>

    {{ $this->content }}

    {{ $this->table }}
</x-filament-panels::page>
