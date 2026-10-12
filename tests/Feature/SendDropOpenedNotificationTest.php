<?php

namespace Tests\Feature;

use App\Jobs\SendDropOpenedNotification;
use App\Support\NotifyUrlPolicy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class SendDropOpenedNotificationTest extends TestCase
{
    public function test_sends_a_plain_text_message_to_the_notification_url(): void
    {
        $url = 'https://ntfy.sh/example-topic';

        $policy = Mockery::mock(NotifyUrlPolicy::class);
        $policy->shouldReceive('resolve')
            ->once()
            ->with($url)
            ->andReturn([
                'host' => 'ntfy.sh',
                'port' => 443,
                'addresses' => ['1.1.1.1'],
            ]);
        $this->app->instance(NotifyUrlPolicy::class, $policy);

        Http::fake();

        (new SendDropOpenedNotification($url, 2))->handle();

        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->method() === 'POST'
            && $request->header('Content-Type')[0] === 'text/plain'
            && $request->body() === 'A drop was opened. Remaining views: 2');
    }
}
