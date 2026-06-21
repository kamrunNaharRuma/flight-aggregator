<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlightSearchTest extends TestCase
{
    private function mockAllProviders(): void
    {
        // Intercept all three provider HTTP calls with the exact assignment data.
        // Without Http::fake(), the test would make real HTTP calls which would fail
        // since no server is running during testing.
        Http::fake([
            '*/mock/providers/a' => Http::response([
                'flights' => [
                    ['carrier' => 'AA', 'from' => 'DAC', 'to' => 'DXB', 'depart' => '2026-07-01T08:00:00', 'arrive' => '2026-07-01T12:30:00', 'stops' => 0, 'fare_usd' => 320.00, 'flight_no' => 'AA101'],
                    ['carrier' => 'AA', 'from' => 'DAC', 'to' => 'DXB', 'depart' => '2026-07-01T22:10:00', 'arrive' => '2026-07-02T02:40:00', 'stops' => 0, 'fare_usd' => 280.00, 'flight_no' => 'AA205'],
                    ['carrier' => 'BS', 'from' => 'DAC', 'to' => 'DXB', 'depart' => '2026-07-01T09:15:00', 'arrive' => '2026-07-01T15:00:00', 'stops' => 1, 'fare_usd' => 310.00, 'flight_no' => 'BS220'],
                    ['carrier' => 'EK', 'from' => 'DAC', 'to' => 'DXB', 'depart' => '2026-07-01T03:45:00', 'arrive' => '2026-07-01T06:50:00', 'stops' => 0, 'fare_usd' => 410.00, 'flight_no' => 'EK585'],
                ],
            ], 200),
            '*/mock/providers/b' => Http::response([
                'data' => [
                    ['airline_code' => 'BS', 'origin' => 'DAC', 'destination' => 'DXB', 'departure_time' => '2026-07-01 09:15', 'arrival_time' => '2026-07-01 15:00', 'segments' => 1, 'price' => ['amount' => 295, 'currency' => 'USD'], 'number' => 'BS220'],
                    ['airline_code' => 'BS', 'origin' => 'DAC', 'destination' => 'DXB', 'departure_time' => '2026-07-01 14:30', 'arrival_time' => '2026-07-01 19:20', 'segments' => 1, 'price' => ['amount' => 265, 'currency' => 'USD'], 'number' => 'BS118'],
                    ['airline_code' => 'EK', 'origin' => 'DAC', 'destination' => 'DXB', 'departure_time' => '2026-07-01 03:45', 'arrival_time' => '2026-07-01 06:50', 'segments' => 0, 'price' => ['amount' => 399, 'currency' => 'USD'], 'number' => 'EK585'],
                ],
            ], 200),
            '*/mock/providers/c' => Http::response([
                'results' => [
                    ['iata' => 'AA', 'route' => ['src' => 'DAC', 'dst' => 'DXB'], 'times' => ['dep' => 1782892800, 'arr' => 1782909000], 'layovers' => 0, 'total_price' => 335, 'currency' => 'USD', 'code' => 'AA101'],
                    ['iata' => 'CJ', 'route' => ['src' => 'DAC', 'dst' => 'DXB'], 'times' => ['dep' => 1782885600, 'arr' => 1782903600], 'layovers' => 2, 'total_price' => 270, 'currency' => 'USD', 'code' => 'CJ300'],
                    ['iata' => 'EK', 'route' => ['src' => 'DAC', 'dst' => 'DXB'], 'times' => ['dep' => 1782877500, 'arr' => 1782888600], 'layovers' => 0, 'total_price' => 405, 'currency' => 'USD', 'code' => 'EK585'],
                ],
            ], 200),
        ]);
    }

    public function test_search_returns_deduplicated_flights_with_correct_meta(): void
    {
        // Clear cache to ensure this is a fresh (non-cached) search.
        Cache::flush();
        $this->mockAllProviders();

        $response = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1');

        $response->assertOk();

        // The response must have both top-level keys.
        $response->assertJsonStructure(['meta', 'flights']);

        // All 3 providers were called and none failed.
        $response->assertJsonPath('meta.providers_queried', 3);
        $response->assertJsonPath('meta.providers_succeeded', 3);
        $this->assertEmpty($response->json('meta.providers_failed'));
            $response->assertJsonPath('meta.cached', false);
            $response->assertJsonPath('meta.passengers', 1);

            // 10 raw flights across 3 providers → 6 unique after deduplication.
            $response->assertJsonPath('meta.total_unique_flights', 6); // full count, never changes
            $response->assertJsonPath('meta.filtered_count', 6);       // no filters applied, same as total
            $this->assertCount(6, $response->json('flights'));
    }

    public function test_ek585_price_is_399_from_provider_b(): void
    {
        // EK585 appears in all 3 providers at different prices (410, 399, 405).
        // The deduplication service must select Provider B's price of $399 (cheapest).
        Cache::flush();
        $this->mockAllProviders();

        $response = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1&sort=price&order=asc');

        $response->assertOk();

        $flights = $response->json('flights');
        $ek585   = collect($flights)->firstWhere('flight_number', 'EK585');

        $this->assertNotNull($ek585, 'EK585 must be present in results');
        $this->assertEquals(399, $ek585['price']);
        $this->assertEquals(399, $ek585['total_price']); // 1 passenger × $399
        $this->assertSame(1, $ek585['passengers']);
        $this->assertSame('provider_b', $ek585['source']);
    }

    public function test_second_request_returns_cached_response(): void
    {
        // First request populates the cache.
        Cache::flush();
        $this->mockAllProviders();
        $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1');

        // Second request should be served from cache — providers are NOT called again.
        // Http::fake() with no routes set means any real HTTP call would fail,
        // proving the cache is being used.
        Http::fake();
        $response = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1');

        $response->assertOk();
        $response->assertJsonPath('meta.cached', true);
    }

    public function test_filter_by_stops_returns_only_direct_flights(): void
    {
        // Filter for non-stop flights only (stops=0).
        // BS220 has 1 stop and CJ300 has 2 stops — they must be excluded.
        Cache::flush();
        $this->mockAllProviders();

        $response = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1&filter[stops]=0');

        $response->assertOk();

        $flights = $response->json('flights');
        foreach ($flights as $flight) {
            $this->assertSame(0, $flight['stops'], "Flight {$flight['flight_number']} should have 0 stops");
        }
    }

    public function test_total_unique_flights_is_full_count_not_filtered_count(): void
    {
        // total_unique_flights must always reflect the full deduplicated dataset.
        // filtered_count reflects what the current filter/sort combination returned.
        // A user seeing total_unique_flights=1 would wrongly think only 1 flight exists.
        Cache::flush();
        $this->mockAllProviders();

        $response = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1&filter[stops]=0&filter[max_price]=300');

        $response->assertOk();

        // Full deduplicated count is always 6 — filter does not change this.
        $response->assertJsonPath('meta.total_unique_flights', 6);

        // filtered_count reflects only flights matching the active filters.
        $filteredCount = $response->json('meta.filtered_count');
        $this->assertLessThan(6, $filteredCount);
        $this->assertSame(count($response->json('flights')), $filteredCount);
    }

    public function test_validation_fails_when_from_is_missing(): void
    {
        // The FlightSearchRequest must reject requests missing required params.
        // Laravel returns 422 with validation errors automatically.
        $response = $this->getJson('/api/flights/search?to=DXB&date=2026-07-01');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['from']);
    }
}
