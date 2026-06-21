<?php

namespace Tests\Unit\Adapters;

use App\DTOs\FlightDTO;
use App\FlightProviders\Adapters\ProviderAAdapter;
use App\Http\Controllers\Mock\ProviderAController;
use Tests\TestCase;

class ProviderAAdapterTest extends TestCase
{
    public function test_maps_raw_json_to_flight_dtos(): void
    {
        // Instead of Http::fake(), we mock the controller directly in the service container.
        // The adapter calls app(ProviderAController::class)->__invoke(), so binding a mock
        // here intercepts that call without any network activity at all.
        $this->mock(ProviderAController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(response()->json([
                'flights' => [
                    [
                        // This is Provider A's raw format — field names like 'carrier',
                        // 'fare_usd', 'flight_no' are Provider A's own naming convention.
                        // The adapter's job is to translate these into our internal FlightDTO fields.
                        'carrier'   => 'AA',
                        'from'      => 'DAC',
                        'to'        => 'DXB',
                        'depart'    => '2026-07-01T08:00:00',
                        'arrive'    => '2026-07-01T12:30:00',
                        'stops'     => 0,
                        'fare_usd'  => 320.00,
                        'flight_no' => 'AA101',
                    ],
                ],
            ]));
        });

        $adapter = new ProviderAAdapter();

        // fetch() is the single method defined in FlightProviderInterface.
        // We pass route and date even though the mock ignores them —
        // in production, the adapter would forward these as query params to the real provider API.
        $results = $adapter->fetch('DAC', 'DXB', '2026-07-01');

        // The adapter must return exactly as many FlightDTOs as there were flights in the response.
        $this->assertCount(1, $results);

        $flight = $results[0];

        // The result must be a FlightDTO — not a raw array, not a stdClass.
        // This confirms the adapter is doing the translation, not just passing raw data through.
        $this->assertInstanceOf(FlightDTO::class, $flight);

        // Each assertion below verifies one specific field mapping:
        // Provider A's 'flight_no' → FlightDTO's 'flightNumber'
        $this->assertSame('AA101', $flight->flightNumber);
        // Provider A's 'carrier' → FlightDTO's 'airline'
        $this->assertSame('AA', $flight->airline);
        $this->assertSame('DAC', $flight->from);
        $this->assertSame('DXB', $flight->to);
        $this->assertSame(0, $flight->stops);
        // Provider A's 'fare_usd' → FlightDTO's 'price'
        $this->assertSame(320.00, $flight->price);
        // Provider A does not send currency — the adapter hardcodes 'USD'
        $this->assertSame('USD', $flight->currency);
        // 'source' tells downstream services which provider this flight came from —
        // used in meta reporting and deduplication conflict resolution
        $this->assertSame('provider_a', $flight->source);
        // Dates are stored as Carbon objects internally. We convert to string for comparison.
        // This also proves Carbon::parse() correctly handled Provider A's ISO 8601 format.
        $this->assertSame('2026-07-01 08:00:00', $flight->departureAt->toDateTimeString());
        $this->assertSame('2026-07-01 12:30:00', $flight->arrivalAt->toDateTimeString());
    }

    public function test_returns_empty_array_when_no_flights(): void
    {
        // Edge case: provider returns a valid response but with zero flights.
        // The adapter must handle this gracefully and return an empty array,
        // not null or throw an exception — the ProviderManager depends on this.
        $this->mock(ProviderAController::class, function ($mock) {
            $mock->shouldReceive('__invoke')->andReturn(response()->json(['flights' => []]));
        });

        $adapter = new ProviderAAdapter();
        $results = $adapter->fetch('DAC', 'DXB', '2026-07-01');

        $this->assertCount(0, $results);
    }

    public function test_get_name_returns_provider_a(): void
    {
        // getName() is used by ProviderManager to identify which provider failed
        // when building the 'providers_failed' list in the API response meta.
        $adapter = new ProviderAAdapter();
        $this->assertSame('provider_a', $adapter->getName());
    }
}
