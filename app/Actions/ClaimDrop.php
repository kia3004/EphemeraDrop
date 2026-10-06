<?php

namespace App\Actions;

use App\Jobs\DeleteDrop;
use App\Jobs\SendDropOpenedNotification;
use App\Models\Drop;
use Illuminate\Support\Facades\URL;

class ClaimDrop
{
    public function __invoke(string $id): ?array
    {
        $claimed = Drop::query()
            ->whereKey($id)
            ->where('expires_at', '>', now())
            ->whereColumn('views', '<', 'max_views')
            ->where(function ($query) {
                $query->where('kind', 'text')
                    ->orWhereNotNull('finalized_at');
            })
            ->increment('views');

        if ($claimed === 0) {
            return null;
        }

        $drop = Drop::query()->find($id);
        if (! $drop) {
            return null;
        }
        $remaining = max(0, (int) $drop->max_views - (int) $drop->views);

        if (! empty($drop->notify_url)) {
            SendDropOpenedNotification::dispatch($drop->notify_url, $remaining);
        }

        if ((int) $drop->views >= (int) $drop->max_views) {
            $drop->consumed_at = now();
            $drop->save();
            DeleteDrop::dispatch($drop->id)
                ->delay(now()->addSeconds((int) config('ephemera.purge_grace_period')));
        }

        return [
            'id' => $drop->id,
            'kind' => $drop->kind,
            'views' => (int) $drop->views,
            'max_views' => (int) $drop->max_views,
            'remaining_views' => $remaining,
            'ciphertext' => $drop->ciphertext,
            'chunk_count' => $drop->chunk_count,
            'size_bytes' => $drop->size_bytes,
            'expires_at' => $drop->expires_at?->toIso8601String(),
            'chunks' => $drop->kind === 'file'
                ? collect(range(0, (int) $drop->chunk_count - 1))
                    ->map(fn (int $index): string => URL::temporarySignedRoute(
                        'drops.chunks.download',
                        now()->addSeconds((int) config('ephemera.signed_url_ttl')),
                        ['id' => $drop->id, 'index' => $index],
                    ))
                    ->all()
                : [],
        ];
    }

    public function status(string $id): ?array
    {
        $drop = Drop::query()
            ->whereKey($id)
            ->where('expires_at', '>', now())
            ->whereColumn('views', '<', 'max_views')
            ->where(function ($query) {
                $query->where('kind', 'text')
                    ->orWhereNotNull('finalized_at');
            })
            ->first();

        if (! $drop) {
            return null;
        }

        return [
            'kind' => $drop->kind,
            'remaining_views' => max(0, (int) $drop->max_views - (int) $drop->views),
            'expires_at' => $drop->expires_at?->toIso8601String(),
            'has_password' => $drop->has_password,
            'kdf_salt' => $drop->kdf_salt,
            'kdf_iterations' => (int) config('ephemera.kdf_iterations'),
        ];
    }
}
