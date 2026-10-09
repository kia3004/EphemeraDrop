<?php

namespace App\Http\Controllers;

use App\Actions\ClaimDrop;
use App\Actions\CreateDrop;
use App\Actions\DeleteDropAction;
use App\Http\Requests\StoreDropRequest;
use App\Models\Drop;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class DropController extends Controller
{
    public function index()
    {
        return view('drops.create');
    }

    public function security()
    {
        return view('drops.security');
    }

    public function health()
    {
        try {
            DB::connection()->select('select 1');
        } catch (\Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ok']);
    }

    public function show(string $id)
    {
        return view('drops.reveal', ['dropId' => $id]);
    }

    public function create(StoreDropRequest $request, CreateDrop $createDrop)
    {
        $payload = $request->validated();
        $result = $createDrop($payload);

        return response()->json($result, 201);
    }

    public function uploadChunk(string $id, int $index, Request $request)
    {
        $drop = $this->uploadableDrop($id, $request->header('X-Upload-Token'));
        abort_unless($index >= 0 && $index < (int) $drop->chunk_count, 404);

        $chunk = $request->getContent();
        abort_if($chunk === '' || strlen($chunk) > (int) config('ephemera.chunk_size') + 28, 413);

        $path = $drop->storage_path . '/chunks/' . $index . '.bin';
        $disk = Storage::disk($drop->storage_disk);

        if ($disk instanceof AwsS3V3Adapter) {
            // B2 rejects object-level ACLs; privacy comes from the private bucket.
            $diskConfig = $disk->getConfig();
            $root = trim($diskConfig['root'] ?? '', '/');
            $disk->getClient()->putObject([
                'Bucket' => $diskConfig['bucket'],
                'Key' => ($root === '' ? '' : $root . '/') . $path,
                'Body' => $chunk,
                'ContentType' => 'application/octet-stream',
            ]);
        } else {
            abort_unless($disk->put($path, $chunk), 503, 'The file chunk could not be stored.');
        }

        return response()->json(['uploaded' => true, 'index' => $index]);
    }

    public function finalize(string $id, Request $request)
    {
        $drop = $this->uploadableDrop($id, $request->header('X-Upload-Token'));

        for ($index = 0; $index < (int) $drop->chunk_count; $index++) {
            $path = $drop->storage_path . '/chunks/' . $index . '.bin';
            abort_unless(Storage::disk($drop->storage_disk)->exists($path), 422, 'A file chunk is missing.');
        }

        $drop->finalized_at = now();
        $drop->save();

        return response()->json(['id' => $drop->id, 'finalized' => true]);
    }

    public function claim(string $id, ClaimDrop $claim)
    {
        $payload = $claim($id);

        if ($payload === null) {
            return response()->json(['message' => 'Not Found'], 404);
        }

        if ($payload['kind'] === 'file') {
            $payload['chunks'] = collect($payload['chunks'])
                ->map(fn (string $url): string => URL::to($url))
                ->all();
        }

        return response()->json($payload);
    }

    public function downloadChunk(string $id, int $index)
    {
        $drop = Drop::query()
            ->whereKey($id)
            ->where('kind', 'file')
            ->whereNotNull('finalized_at')
            ->first();

        abort_unless($drop && $index >= 0 && $index < (int) $drop->chunk_count, 404);

        $path = $drop->storage_path . '/chunks/' . $index . '.bin';
        $stream = Storage::disk($drop->storage_disk)->readStream($path);
        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function status(string $id, ClaimDrop $claim)
    {
        $status = $claim->status($id);

        return $status === null
            ? response()->json(['message' => 'Not Found'], 404)
            : response()->json($status);
    }

    public function delete(string $id, Request $request, DeleteDropAction $delete)
    {
        $token = $request->header('X-Delete-Token') ?? $request->query('token');
        $ok = $delete($id, $token);

        return $ok
            ? response()->json(['deleted' => true])
            : response()->json(['message' => 'Invalid delete token'], 403);
    }

    public function deleteForm(string $id)
    {
        return view('drops.delete', ['dropId' => $id]);
    }

    private function uploadableDrop(string $id, ?string $token): Drop
    {
        $drop = Drop::query()
            ->whereKey($id)
            ->where('kind', 'file')
            ->whereNull('finalized_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_unless(
            $drop
            && is_string($token)
            && $drop->upload_token_hash
            && hash_equals($drop->upload_token_hash, hash('sha256', $token)),
            404,
        );

        return $drop;
    }
}
