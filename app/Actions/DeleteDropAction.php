<?php

namespace App\Actions;

use App\Jobs\DeleteDrop;
use App\Models\Drop;

class DeleteDropAction
{
    public function __invoke(string $id, ?string $token = null): bool
    {
        $drop = Drop::query()->whereKey($id)->first();

        if (! $drop || ! is_string($token)) {
            return false;
        }

        if (! hash_equals($drop->delete_token_hash, hash('sha256', $token))) {
            return false;
        }

        DeleteDrop::dispatchSync($drop->id);

        return true;
    }
}
