<?php

namespace Tests\Feature;

use App\Actions\ClaimDrop;
use App\Models\Drop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

class ConcurrentClaimTest extends TestCase
{
    public function test_twenty_concurrent_claims_consume_exactly_one_view(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite' || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Run this concurrency test in the MySQL/PostgreSQL Docker test matrix.');
        }

        Queue::fake();

        $drop = Drop::create([
            'id' => (string) Str::ulid(),
            'kind' => 'text',
            'ciphertext' => 'opaque-ciphertext',
            'delete_token_hash' => hash('sha256', 'test-token'),
            'expires_at' => now()->addHour(),
            'max_views' => 1,
            'views' => 0,
            'size_bytes' => 0,
            'finalized_at' => now(),
        ]);

        $connection = DB::connection();
        while ($connection->transactionLevel() > 0) {
            $connection->commit();
        }
        DB::disconnect();

        $workerCount = 20;
        $barrier = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ephemeradrop-claims-' . Str::random(16);
        mkdir($barrier);
        $children = [];

        try {
            for ($worker = 0; $worker < $workerCount; $worker++) {
                $pid = pcntl_fork();
                $this->assertNotSame(-1, $pid, 'Could not start a concurrent claim worker.');

                if ($pid === 0) {
                    try {
                        file_put_contents($barrier . DIRECTORY_SEPARATOR . 'ready-' . $worker, '1');

                        $deadline = microtime(true) + 15;
                        while (! file_exists($barrier . DIRECTORY_SEPARATOR . 'start') && microtime(true) < $deadline) {
                            usleep(10000);
                        }

                        DB::purge();
                        $claim = app(ClaimDrop::class)($drop->id);
                        file_put_contents(
                            $barrier . DIRECTORY_SEPARATOR . 'result-' . $worker,
                            $claim === null ? '0' : '1',
                        );
                        exit(0);
                    } catch (Throwable) {
                        file_put_contents($barrier . DIRECTORY_SEPARATOR . 'result-' . $worker, 'error');
                        exit(1);
                    }
                }

                $children[] = $pid;
            }

            $deadline = microtime(true) + 15;
            while (count(glob($barrier . DIRECTORY_SEPARATOR . 'ready-*') ?: []) < $workerCount
                && microtime(true) < $deadline) {
                usleep(10000);
            }
            file_put_contents($barrier . DIRECTORY_SEPARATOR . 'start', '1');

            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status), 'A concurrent claim worker did not exit cleanly.');
                $this->assertSame(0, pcntl_wexitstatus($status), 'A concurrent claim worker failed.');
            }

            $results = array_map(
                fn (string $path): string => file_get_contents($path),
                glob($barrier . DIRECTORY_SEPARATOR . 'result-*') ?: [],
            );

            $this->assertCount($workerCount, $results);
            $this->assertSame(1, count(array_filter($results, fn (string $result): bool => $result === '1')));
            $this->assertSame(0, count(array_filter($results, fn (string $result): bool => $result === 'error')));
            $this->assertSame(1, (int) Drop::query()->whereKey($drop->id)->value('views'));
        } finally {
            if (! file_exists($barrier . DIRECTORY_SEPARATOR . 'start')) {
                file_put_contents($barrier . DIRECTORY_SEPARATOR . 'start', '1');
            }

            foreach (glob($barrier . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($barrier);
        }
    }
}
