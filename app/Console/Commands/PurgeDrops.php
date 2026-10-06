<?php

namespace App\Console\Commands;

use App\Jobs\DeleteDrop;
use App\Models\Drop;
use Illuminate\Console\Command;

class PurgeDrops extends Command
{
    protected $signature = 'drops:purge';

    protected $description = 'Purge expired, consumed, and abandoned drops.';

    public function handle(): int
    {
        $cutoff = now()->subHour();
        $filePurgeCutoff = now()->subSeconds((int) config('ephemera.purge_grace_period'));

        foreach (Drop::query()
            ->where('expires_at', '<', now())
            ->where(function ($query) use ($filePurgeCutoff) {
                $query->where('kind', 'text')
                    ->orWhere('expires_at', '<', $filePurgeCutoff);
            })
            ->get() as $drop) {
            DeleteDrop::dispatchSync($drop->id);
        }

        foreach (Drop::query()->where('kind', 'file')->whereNull('finalized_at')->where('created_at', '<', $cutoff)->get() as $drop) {
            DeleteDrop::dispatchSync($drop->id);
        }

        $consumedBefore = now()->subSeconds((int) config('ephemera.purge_grace_period'));
        foreach (Drop::query()->whereNotNull('consumed_at')->where('consumed_at', '<', $consumedBefore)->get() as $drop) {
            DeleteDrop::dispatchSync($drop->id);
        }

        return self::SUCCESS;
    }
}
