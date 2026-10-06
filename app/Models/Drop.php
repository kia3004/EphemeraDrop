<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Drop extends Model
{
    use HasUlids;

    protected $table = 'drops';

    protected $fillable = [
        'id',
        'kind',
        'ciphertext',
        'storage_path',
        'storage_disk',
        'chunk_count',
        'size_bytes',
        'kdf_salt',
        'has_password',
        'max_views',
        'views',
        'expires_at',
        'delete_token_hash',
        'notify_url',
        'consumed_at',
        'upload_token_hash',
        'finalized_at',
    ];

    protected $casts = [
        'has_password' => 'boolean',
        'size_bytes' => 'integer',
        'chunk_count' => 'integer',
        'views' => 'integer',
        'max_views' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'finalized_at' => 'datetime',
        'notify_url' => 'encrypted',
    ];

    public $incrementing = false;
    protected $keyType = 'string';

    public static function makeId(): string
    {
        return (string) Str::ulid();
    }
}
