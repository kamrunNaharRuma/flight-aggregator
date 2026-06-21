<?php

namespace Tests\Unit\Services;

use App\DTOs\FlightDTO;
use App\Services\DeduplicationService;
use Carbon\Carbon;
use Tests\TestCase;

class DeduplicationServiceTest extends TestCase
{
    private DeduplicationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // A fresh service instance for every test — no shared state between tests.
        $this->service = new DeduplicationService();
    }

    // Helper to build a minimal FlightDTO without repeating all fields in every test.
    // Only the fields that affect deduplication (flightNumber, price, source, departure)
    // are parameterised — the rest are fixed dummies.
    private function makeFlightDTO(
        string $flightNumber,
        float $price,
        string $source,
        string $departure = '2026-07-01T03:45:00',
    ): FlightDTO {
        return new FlightDTO(
            id: '',
            flightNumber: $flightNumber,
            airline: strtok($flightNumber, '0123456789'),
            from: 'DAC',
            to: 'DXB',
            departureAt: Carbon::parse($departure),
            arrivalAt: Carbon::parse($departure)->addHours(3),
            stops: 0,
            price: $price,
            currency: 'USD',
            source: $source,
        );
    }

    public function test_returns_6_unique_flights_from_full_assignment_dataset(): void
    {
        // Full dataset exactly as defined in the assignment.
        // 10 raw flights from 3 providers with 3 overlapping flight numbers.
        // Expected unique flights: AA101, AA205, BS118, BS220, CJ300, EK585 = 6
        $flights = [
            // Provider A — 4 flights
            $this->makeFlightDTO('AA101', 320.00, 'provider_a', '2026-07-01T08:00:00'),
            $this->makeFlightDTO('AA205', 280.00, 'provider_a', '2026-07-01T22:10:00'),
            $this->makeFlightDTO('BS220', 310.00, 'provider_a', '2026-07-01T09:15:00'),
            $this->makeFlightDTO('EK585', 410.00, 'provider_a', '2026-07-01T03:45:00'),
            // Provider B — 3 flights (BS220 and EK585 duplicate A)
            $this->makeFlightDTO('BS220', 295.00, 'provider_b', '2026-07-01T09:15:00'),
            $this->makeFlightDTO('BS118', 265.00, 'provider_b', '2026-07-01T14:30:00'),
            $this->makeFlightDTO('EK585', 399.00, 'provider_b', '2026-07-01T03:45:00'),
            // Provider C — 3 flights (AA101 and EK585 duplicate A/B)
            $this->makeFlightDTO('AA101', 335.00, 'provider_c', '2026-07-01T08:00:00'),
            $this->makeFlightDTO('CJ300', 270.00, 'provider_c', '2026-07-01T02:00:00'),
            $this->makeFlightDTO('EK585', 405.00, 'provider_c', '2026-07-01T03:45:00'),
        ];

        $result = $this->service->deduplicate($flights);

        // 10 raw → 6 unique. 4 removed: EK585×3→1, AA101×2→1, BS220×2→1
        $this->assertCount(6, $result);
    }

    public function test_ek585_keeps_lowest_price_from_provider_b(): void
    {
        // EK585 appears in all 3 providers: A=$410, B=$399, C=$405.
        // Provider B has the lowest price so it must win.
        $flights = [
            $this->makeFlightDTO('EK585', 410.00, 'provider_a', '2026-07-01T03:45:00'),
            $this->makeFlightDTO('EK585', 399.00, 'provider_b', '2026-07-01T03:45:00'),
            $this->makeFlightDTO('EK585', 405.00, 'provider_c', '2026-07-01T03:45:00'),
        ];

        $result = $this->service->deduplicate($flights);

        $this->assertCount(1, $result);
        $this->assertSame(399.00, $result[0]->price);
        // 'source' confirms the winning entry came from the correct provider
        $this->assertSame('provider_b', $result[0]->source);
    }

    public function test_aa101_keeps_lowest_price_from_provider_a(): void
    {
        // AA101 appears in Provider A ($320) and Provider C ($335).
        // Provider A has the lower price so it must win.
        $flights = [
            $this->makeFlightDTO('AA101', 320.00, 'provider_a', '2026-07-01T08:00:00'),
            $this->makeFlightDTO('AA101', 335.00, 'provider_c', '2026-07-01T08:00:00'),
        ];

        $result = $this->service->deduplicate($flights);

        $this->assertCount(1, $result);
        $this->assertSame(320.00, $result[0]->price);
        $this->assertSame('provider_a', $result[0]->source);
    }

    public function test_bs220_keeps_lowest_price_from_provider_b(): void
    {
        // BS220 appears in Provider A ($310) and Provider B ($295).
        // Provider B has the lower price so it must win.
        // Note: BS118 is a completely different flight (different number + departure time).
        $flights = [
            $this->makeFlightDTO('BS220', 310.00, 'provider_a', '2026-07-01T09:15:00'),
            $this->makeFlightDTO('BS220', 295.00, 'provider_b', '2026-07-01T09:15:00'),
        ];

        $result = $this->service->deduplicate($flights);

        $this->assertCount(1, $result);
        $this->assertSame(295.00, $result[0]->price);
        $this->assertSame('provider_b', $result[0]->source);
    }

    public function test_stable_id_is_deterministic(): void
    {
        // The same flight must always produce the same SHA-256 ID regardless of
        // how many times dedup runs. This is critical — the ID is stored in booking
        // records and must not change across cache refreshes or repeated searches.
        $flightA = $this->makeFlightDTO('EK585', 399.00, 'provider_b', '2026-07-01T03:45:00');
        $flightB = $this->makeFlightDTO('EK585', 399.00, 'provider_b', '2026-07-01T03:45:00');

        $result1 = $this->service->deduplicate([$flightA]);
        $result2 = $this->service->deduplicate([$flightB]);

        $this->assertSame($result1[0]->id, $result2[0]->id);
        $this->assertNotEmpty($result1[0]->id);
    }

    public function test_same_airline_different_departure_times_are_not_duplicates(): void
    {
        // The dedup key is flightNumber|departureUTC — both parts must match for a collision.
        // BS220 at 09:15 and BS118 at 14:30 have different numbers AND different times,
        // so they must be treated as two separate flights, not duplicates.
        $flights = [
            $this->makeFlightDTO('BS220', 310.00, 'provider_a', '2026-07-01T09:15:00'),
            $this->makeFlightDTO('BS118', 265.00, 'provider_b', '2026-07-01T14:30:00'),
        ];

        $result = $this->service->deduplicate($flights);

        $this->assertCount(2, $result);
    }
}
