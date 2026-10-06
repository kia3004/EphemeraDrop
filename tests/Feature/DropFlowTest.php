<?php

namespace Tests\Feature;

use App\Models\Drop;
use App\Jobs\DeleteDrop;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DropFlowTest extends TestCase
{
    public function test_creates_and_claims_a_text_drop(): void
    {
        $response = $this->postJson('/api/drops', [
            'kind' => 'text',
            'ciphertext' => 'super-secret',
            'expires_in' => 60,
            'max_views' => 2,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'delete_token', 'expires_at']);

        $drop = Drop::query()->findOrFail($response->json('id'));
        $this->assertSame('text', $drop->kind);

        $claim = $this->postJson('/api/drops/' . $drop->id . '/claim');
        $claim->assertStatus(200)
            ->assertJsonPath('kind', 'text')
            ->assertJsonPath('ciphertext', 'super-secret');
    }

    public function test_returns_404_for_expired_exhausted_and_missing_drops(): void
    {
        $expired = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'expired',
            'delete_token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->subMinute(),
            'max_views' => 3,
            'views' => 0,
            'size_bytes' => 0,
        ]);

        $exhausted = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'used',
            'delete_token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addHour(),
            'max_views' => 1,
            'views' => 1,
            'size_bytes' => 0,
        ]);

        $expiredResponse = $this->postJson('/api/drops/' . $expired->id . '/claim')->assertStatus(404);
        $exhaustedResponse = $this->postJson('/api/drops/' . $exhausted->id . '/claim')->assertStatus(404);
        $missingResponse = $this->postJson('/api/drops/' . (string) Str::ulid() . '/claim')->assertStatus(404);

        $this->assertSame($expiredResponse->getContent(), $exhaustedResponse->getContent());
        $this->assertSame($expiredResponse->getContent(), $missingResponse->getContent());
    }

    public function test_does_not_increment_views_when_reveal_page_is_loaded(): void
    {
        $drop = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'message',
            'delete_token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addHour(),
            'max_views' => 5,
            'views' => 0,
            'size_bytes' => 0,
        ]);

        $this->get('/d/' . $drop->id)->assertOk();
        $this->assertSame(0, $drop->fresh()->views);
    }

    public function test_reveal_status_is_non_consuming_and_reports_password_metadata(): void
    {
        $drop = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'opaque-ciphertext',
            'delete_token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addHour(),
            'max_views' => 4,
            'views' => 1,
            'has_password' => true,
            'kdf_salt' => 'MDEyMzQ1Njc4OWFiY2RlZg',
            'size_bytes' => 0,
        ]);

        $this->getJson('/api/drops/' . $drop->id . '/status')
            ->assertOk()
            ->assertJsonPath('remaining_views', 3)
            ->assertJsonPath('has_password', true)
            ->assertJsonPath('kdf_salt', 'MDEyMzQ1Njc4OWFiY2RlZg');

        $this->assertSame(1, $drop->fresh()->views);
    }

    public function test_security_headers_are_present_on_reveal_pages(): void
    {
        $drop = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'opaque-ciphertext',
            'delete_token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->addHour(),
            'max_views' => 2,
            'views' => 0,
            'size_bytes' => 0,
        ]);

        $response = $this->get('/d/' . $drop->id);

        $response
            ->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_private_notification_destinations_are_rejected(): void
    {
        $this->postJson('/api/drops', [
            'kind' => 'text',
            'ciphertext' => 'opaque-ciphertext',
            'notify_url' => 'https://127.0.0.1/notify',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('notify_url');
    }

    public function test_unavailable_file_creation_is_not_accepted_by_the_api(): void
    {
        $this->postJson('/api/drops', [
            'kind' => 'file',
            'size_bytes' => 1024,
            'chunk_count' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('chunk_count');
    }

    public function test_file_chunks_upload_finalize_claim_download_and_delete_on_selected_disk(): void
    {
        Storage::fake('s3');
        config([
            'ephemera.storage_disk' => 's3',
            'ephemera.chunk_size' => 4,
        ]);

        $created = $this->postJson('/api/drops', [
            'kind' => 'file',
            'size_bytes' => 5,
            'chunk_count' => 3,
            'expires_in' => 60,
            'max_views' => 1,
        ])->assertCreated()
            ->assertJsonStructure(['id', 'delete_token', 'upload_token']);

        $id = $created->json('id');
        $token = $created->json('upload_token');

        $this->postJson('/api/drops/' . $id . '/claim')->assertNotFound();

        foreach ([0 => 'encrypted-header', 1 => 'encrypted-one', 2 => 'encrypted-two'] as $index => $chunk) {
            $response = $this->call(
                'PUT',
                '/api/drops/' . $id . '/chunks/' . $index,
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_UPLOAD_TOKEN' => $token],
                $chunk,
            );
            $response->assertOk();
        }

        $this->postJson('/api/drops/' . $id . '/finalize', [], ['X-Upload-Token' => $token])
            ->assertOk()
            ->assertJsonPath('finalized', true);

        Queue::fake();
        $claimed = $this->postJson('/api/drops/' . $id . '/claim')->assertOk();
        $this->assertCount(3, $claimed->json('chunks'));

        $this->travel(61)->seconds();
        foreach ($claimed->json('chunks') as $index => $url) {
            $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
            $this->get($path)->assertOk()->assertHeader('Content-Type', 'application/octet-stream');
        }

        $this->artisan('drops:purge')->assertSuccessful();
        $this->assertDatabaseHas('drops', ['id' => $id]);

        $this->postJson('/api/drops/' . $id . '/claim')->assertNotFound();

        $this->travel(660)->seconds();
        $this->artisan('drops:purge')->assertSuccessful();

        config(['ephemera.storage_disk' => 'local']);
        (new DeleteDrop($id))->handle();
        Storage::disk('s3')->assertMissing('drops/' . $id . '/chunks/0.bin');
        $this->assertDatabaseMissing('drops', ['id' => $id]);
    }

    public function test_create_rate_limiter_rejects_requests_over_the_limit(): void
    {
        config(['ephemera.rate_limits.create_drop_per_ip' => 1]);

        $payload = [
            'kind' => 'text',
            'ciphertext' => 'opaque-ciphertext',
        ];

        $this->postJson('/api/drops', $payload)->assertCreated();
        $this->postJson('/api/drops', $payload)->assertTooManyRequests();
    }

    public function test_requires_a_valid_delete_token(): void
    {
        $drop = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'message',
            'delete_token_hash' => hash('sha256', 'good-token'),
            'expires_at' => now()->addHour(),
            'max_views' => 5,
            'views' => 0,
            'size_bytes' => 0,
        ]);

        $this->deleteJson('/api/drops/' . $drop->id, [], ['X-Delete-Token' => 'bad-token'])
            ->assertStatus(403);

        $this->assertDatabaseHas('drops', ['id' => $drop->id]);

        $this->deleteJson('/api/drops/' . $drop->id, [], ['X-Delete-Token' => 'good-token'])
            ->assertOk();
    }

    public function test_purges_expired_rows(): void
    {
        $drop = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'expired',
            'delete_token_hash' => hash('sha256', 'token'),
            'expires_at' => now()->subMinute(),
            'max_views' => 3,
            'views' => 0,
            'size_bytes' => 0,
        ]);

        $this->artisan('drops:purge')->assertSuccessful();
        $this->assertDatabaseMissing('drops', ['id' => $drop->id]);
    }
}
