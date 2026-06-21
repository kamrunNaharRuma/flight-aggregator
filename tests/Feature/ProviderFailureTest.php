<?php

namespace Tests\Feature;

use App\Http\Controllers\Mock\ProviderCController;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProviderFailureTest extends TestCase
{
    public function test_partial_results_returned_when_one_provider_fails(): void
    {
        // Mock Provider C's controller to throw an exception.
        // ProviderManager catches any Throwable per provider and records the provider name
        // in the 'providers_failed' list, so the other two providers still return results.
        Cache::flush();

        $this->mock(ProviderCController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andThrow(new \RuntimeException('Provider C is down'));
        });

        $response = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1');

        $response->assertOk();

        // Only 2 providers succeeded — Provider C failed.
        $response->assertJsonPath('meta.providers_queried', 3);
        $response->assertJsonPath('meta.providers_succeeded', 2);

        // The failed provider must be named in the meta so the client knows results are partial.
        $response->assertJsonFragment(['providers_failed' => ['provider_c']]);

        // Flights from A and B are still returned despite C failing.
        $this->assertNotEmpty($response->json('flights'));
    }
}
