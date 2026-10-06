<?php

namespace App\Jobs;

use App\Models\Drop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class DeleteDrop implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $dropId)
    {
    }

    public function handle(): void
    {
        $drop = Drop::query()->whereKey($this->dropId)->first();

        if (! $drop) {
            return;
        }

        if ($drop->storage_path) {
            $disk = Storage::disk($drop->storage_disk ?: config('ephemera.storage_disk'));
            $disk->deleteDirectory($drop->storage_path);
        }

        $drop->delete();
    }
}
