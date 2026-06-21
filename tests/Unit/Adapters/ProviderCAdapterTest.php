<?php

namespace Tests\Unit\Adapters;

use App\DTOs\FlightDTO;
use App\FlightProviders\Adapters\ProviderCAdapter;
use App\Http\Controllers\Mock\ProviderCController;
use Tests\TestCase;

class ProviderCAdapterTest extends TestCase
{
    public function test_maps_raw_json_to_flight_dtos(): void
    {
        // Mock the controller directly in the container — no HTTP calls, no running server needed.
        $this->mock(ProviderCController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(response()->json([
                'results' => [
                    [
                        // Provider C's format is the most different from A and B:
                        // - Times are Unix timestamps (seconds since epoch), not human-readable strings
                        // - Route is a nested object: { src, dst } instead of flat from/to
                        // - 'layovers' instead of 'stops'/'segments'
                        // - 'total_price' instead of 'fare_usd' or 'price.amount'
                        // - 'code' instead of 'flight_no' or 'number'
                        // - 'iata' instead of 'carrier' or 'airline_code'
                        'iata'        => 'AA',
                        'route'       => ['src' => 'DAC', 'dst' => 'DXB'],
                        'times'       => ['dep' => 1782892800, 'arr' => 1782909000],
                        'layovers'    => 0,
                        'total_price' => 335,
                        'currency'    => 'USD',
                        'code'        => 'AA101',
                    ],
                ],
            ]));
        });

        $adapter = new ProviderCAdapter();
        $results = $adapter->fetch('DAC', 'DXB', '2026-07-01');

        $this->assertCount(1, $results);

        $flight = $results[0];
        $this->assertInstanceOf(FlightDTO::class, $flight);

        // Provider C's 'code' → FlightDTO's 'flightNumber'
        $this->assertSame('AA101', $flight->flightNumber);
        // Provider C's 'iata' → FlightDTO's 'airline'
        $this->assertSame('AA', $flight->airline);
        // Provider C's nested 'route.src'/'route.dst' → FlightDTO's 'from'/'to'
        $this->assertSame('DAC', $flight->from);
        $this->assertSame('DXB', $flight->to);
        // Provider C's 'layovers' → FlightDTO's 'stops'
        $this->assertSame(0, $flight->stops);
        // Provider C's 'total_price' → FlightDTO's 'price'
        $this->assertSame(335.00, $flight->price);
        $this->assertSame('USD', $flight->currency);
        $this->assertSame('provider_c', $flight->source);
        // Unlike providers A and B, Provider C sends Unix timestamps.
        // Carbon::createFromTimestamp() converts them to Carbon objects.
        // We assert on the raw timestamp to confirm no precision was lost in the conversion.
        $this->assertSame(1782892800, $flight->departureAt->timestamp);
        $this->assertSame(1782909000, $flight->arrivalAt->timestamp);
    }

    public function test_returns_empty_array_when_no_flights(): void
    {
        // Provider C uses 'results' as its root key (not 'flights' or 'data').
        // This test confirms the adapter reads from the correct key.
        $this->mock(ProviderCController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(response()->json(['results' => []]));
        });

        $adapter = new ProviderCAdapter();
        $results = $adapter->fetch('DAC', 'DXB', '2026-07-01');

        $this->assertCount(0, $results);
    }

    public function test_get_name_returns_provider_c(): void
    {
        // getName() is used by ProviderManager to identify which provider failed
        // when building the 'providers_failed' list in the API response meta.
        $adapter = new ProviderCAdapter();
        $this->assertSame('provider_c', $adapter->getName());
    }
}
