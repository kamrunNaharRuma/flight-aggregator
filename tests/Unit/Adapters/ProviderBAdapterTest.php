<?php

namespace Tests\Unit\Adapters;

use App\DTOs\FlightDTO;
use App\FlightProviders\Adapters\ProviderBAdapter;
use App\Http\Controllers\Mock\ProviderBController;
use Tests\TestCase;

class ProviderBAdapterTest extends TestCase
{
    public function test_maps_raw_json_to_flight_dtos(): void
    {
        // Mock the controller directly in the container — no HTTP calls, no running server needed.
        $this->mock(ProviderBController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(response()->json([
                'data' => [
                    [
                        // Provider B's naming is different from Provider A:
                        // 'airline_code' instead of 'carrier'
                        // 'origin'/'destination' instead of 'from'/'to'
                        // 'segments' instead of 'stops'
                        // 'number' instead of 'flight_no'
                        // 'price.amount' (nested) instead of 'fare_usd' (flat)
                        // departure/arrival times use "Y-m-d H:i" format (no T separator)
                        'airline_code'   => 'EK',
                        'origin'         => 'DAC',
                        'destination'    => 'DXB',
                        'departure_time' => '2026-07-01 03:45',
                        'arrival_time'   => '2026-07-01 06:50',
                        'segments'       => 0,
                        'price'          => ['amount' => 399, 'currency' => 'USD'],
                        'number'         => 'EK585',
                    ],
                ],
            ]));
        });

        $adapter = new ProviderBAdapter();
        $results = $adapter->fetch('DAC', 'DXB', '2026-07-01');

        $this->assertCount(1, $results);

        $flight = $results[0];
        $this->assertInstanceOf(FlightDTO::class, $flight);

        // Provider B's 'number' → FlightDTO's 'flightNumber'
        $this->assertSame('EK585', $flight->flightNumber);
        // Provider B's 'airline_code' → FlightDTO's 'airline'
        $this->assertSame('EK', $flight->airline);
        // Provider B's 'origin'/'destination' → FlightDTO's 'from'/'to'
        $this->assertSame('DAC', $flight->from);
        $this->assertSame('DXB', $flight->to);
        // Provider B's 'segments' → FlightDTO's 'stops'
        $this->assertSame(0, $flight->stops);
        // Provider B's nested 'price.amount' → FlightDTO's 'price'
        $this->assertSame(399.00, $flight->price);
        // Provider B sends currency inside the nested price object — we use it directly
        $this->assertSame('USD', $flight->currency);
        $this->assertSame('provider_b', $flight->source);
        // Carbon::parse() handles Provider B's "Y-m-d H:i" format (without the T separator)
        $this->assertSame('2026-07-01 03:45:00', $flight->departureAt->toDateTimeString());
        $this->assertSame('2026-07-01 06:50:00', $flight->arrivalAt->toDateTimeString());
    }

    public function test_returns_empty_array_when_no_flights(): void
    {
        // Provider B uses 'data' as its root key (not 'flights' like Provider A).
        // This test confirms the adapter reads from the correct key.
        $this->mock(ProviderBController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(response()->json(['data' => []]));
        });

        $adapter = new ProviderBAdapter();
        $results = $adapter->fetch('DAC', 'DXB', '2026-07-01');

        $this->assertCount(0, $results);
    }

    public function test_get_name_returns_provider_b(): void
    {
        // getName() is used by ProviderManager to identify which provider failed
        // when building the 'providers_failed' list in the API response meta.
        $adapter = new ProviderBAdapter();
        $this->assertSame('provider_b', $adapter->getName());
    }
}
