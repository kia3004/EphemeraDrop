<?php

namespace App\Jobs;

use App\Support\NotifyUrlPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SendDropOpenedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $url, public int $remainingViews)
    {
    }

    public function handle(): void
    {
        $destination = app(NotifyUrlPolicy::class)->resolve($this->url);
        if (! $destination) {
            throw new RuntimeException('Notification URL is not a safe public HTTPS destination.');
        }

        if (! extension_loaded('curl')) {
            throw new RuntimeException('The cURL extension is required for safe notification delivery.');
        }

        $resolvedAddresses = array_map(
            fn (string $address): string => str_contains($address, ':')
                ? "{$destination['host']}:443:[{$address}]"
                : "{$destination['host']}:443:{$address}",
            $destination['addresses'],
        );

        $message = 'A drop was opened. Remaining views: ' . $this->remainingViews;

        Http::timeout(5)
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => $resolvedAddresses],
            ])
            ->withBody($message, 'text/plain')
            ->post($this->url)
            ->throw();
    }
}
