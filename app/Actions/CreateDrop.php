<?php

namespace App\Actions;

use App\Models\Drop;
use Illuminate\Support\Str;

class CreateDrop
{
    public function __invoke(array $data): array
    {
        $drop = new Drop();
        $drop->id = Drop::makeId();
        $drop->kind = $data['kind'];
        $drop->max_views = (int) ($data['max_views'] ?? config('ephemera.default_views'));
        $drop->expires_at = now()->addMinutes((int) ($data['expires_in'] ?? 60));
        $drop->has_password = (bool) ($data['has_password'] ?? false);
        $drop->size_bytes = $data['size_bytes'] ?? 0;
        $drop->views = 0;
        $drop->notify_url = $data['notify_url'] ?? null;
        $drop->kdf_salt = $data['kdf_salt'] ?? null;

        $deleteToken = Str::random(config('ephemera.delete_token_length'));
        $drop->delete_token_hash = hash('sha256', $deleteToken);
        $uploadToken = null;

        if ($data['kind'] === 'text') {
            $drop->ciphertext = (string) ($data['ciphertext'] ?? '');
            $drop->chunk_count = null;
            $drop->storage_path = null;
            $drop->storage_disk = null;
            $drop->finalized_at = now();
        } else {
            $drop->ciphertext = null;
            $drop->chunk_count = (int) ($data['chunk_count'] ?? 0);
            $drop->storage_path = 'drops/' . $drop->id;
            $drop->storage_disk = config('ephemera.storage_disk');
            $uploadToken = Str::random(48);
            $drop->upload_token_hash = hash('sha256', $uploadToken);
        }

        $drop->save();

        $result = [
            'id' => $drop->id,
            'delete_token' => $deleteToken,
            'expires_at' => $drop->expires_at->toIso8601String(),
        ];

        if ($uploadToken !== null) {
            $result['upload_token'] = $uploadToken;
        }

        return $result;
    }
}
