<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingTest extends TestCase
{
    // Each test runs inside a database transaction that is rolled back afterwards,
    // so bookings created in one test never bleed into another.
    use RefreshDatabase;

    // ─── Shared helpers ────────────────────────────────────────────────────────

    private function mockAllProviders(): void
    {
        // Same three-provider fixture used in FlightSearchTest.
        // We need the cache to be populated so BookingService::resolveFlightFromCache()
        // can find the flight_id we pass when creating a booking.
        Http::fake([
            '*/mock/providers/a' => Http::response([
                'flights' => [
                    ['carrier' => 'AA', 'from' => 'DAC', 'to' => 'DXB', 'depart' => '2026-07-01T08:00:00', 'arrive' => '2026-07-01T12:30:00', 'stops' => 0, 'fare_usd' => 320.00, 'flight_no' => 'AA101'],
                    ['carrier' => 'EK', 'from' => 'DAC', 'to' => 'DXB', 'depart' => '2026-07-01T03:45:00', 'arrive' => '2026-07-01T06:50:00', 'stops' => 0, 'fare_usd' => 410.00, 'flight_no' => 'EK585'],
                ],
            ], 200),
            '*/mock/providers/b' => Http::response([
                'data' => [
                    ['airline_code' => 'EK', 'origin' => 'DAC', 'destination' => 'DXB', 'departure_time' => '2026-07-01 03:45', 'arrival_time' => '2026-07-01 06:50', 'segments' => 0, 'price' => ['amount' => 399, 'currency' => 'USD'], 'number' => 'EK585'],
                    ['airline_code' => 'BS', 'origin' => 'DAC', 'destination' => 'DXB', 'departure_time' => '2026-07-01 14:30', 'arrival_time' => '2026-07-01 19:20', 'segments' => 1, 'price' => ['amount' => 265, 'currency' => 'USD'], 'number' => 'BS118'],
                ],
            ], 200),
            '*/mock/providers/c' => Http::response([
                'results' => [
                    ['iata' => 'AA', 'route' => ['src' => 'DAC', 'dst' => 'DXB'], 'times' => ['dep' => 1782892800, 'arr' => 1782909000], 'layovers' => 0, 'total_price' => 335, 'currency' => 'USD', 'code' => 'AA101'],
                ],
            ], 200),
        ]);
    }

    private function seedCacheAndGetFlightId(): string
    {
        // Trigger the search endpoint to populate the Redis cache.
        // We then extract a real flight_id from the response to use in booking tests.
        Cache::flush();
        $this->mockAllProviders();

        $search = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1');
        $search->assertOk();

        // Return the cheapest flight's id so the booking tests have a valid, cache-backed id.
        return $search->json('flights.0.id');
    }

    // ─── Tests ─────────────────────────────────────────────────────────────────

    public function test_booking_can_be_created_and_returns_201(): void
    {
        $flightId = $this->seedCacheAndGetFlightId();

        $response = $this->postJson('/api/bookings', [
            'flight_id'  => $flightId,
            'passengers' => [
                ['first_name' => 'Jane', 'last_name' => 'Doe', 'type' => 'adult', 'document_number' => 'A1234567'],
            ],
        ]);

        // HTTP 201 Created is the correct status for a successfully persisted booking.
        $response->assertCreated();

        // The booking reference must follow the BK-XXXXXX format.
        $this->assertMatchesRegularExpression('/^BK-[A-Z0-9]{6}$/', $response->json('reference'));

        // The booking must carry the flight_id we submitted.
        $response->assertJsonPath('flight_id', $flightId);

        // Status starts as confirmed.
        $response->assertJsonPath('status', 'confirmed');
    }

    public function test_total_price_is_multiplied_by_passenger_count(): void
    {
        // EK585 is priced at $399 (cheapest from Provider B after dedup).
        // Booking 2 passengers must yield total_price = 399 * 2 = 798.
        Cache::flush();
        $this->mockAllProviders();

        $search   = $this->getJson('/api/flights/search?from=DAC&to=DXB&date=2026-07-01&passengers=1');
        $flights  = $search->json('flights');
        $ek585    = collect($flights)->firstWhere('flight_number', 'EK585');

        $response = $this->postJson('/api/bookings', [
            'flight_id'  => $ek585['id'],
            'passengers' => [
                ['first_name' => 'Alice', 'last_name' => 'Smith', 'type' => 'adult',  'document_number' => 'B1111111'],
                ['first_name' => 'Bob',   'last_name' => 'Smith', 'type' => 'child',  'document_number' => 'B2222222'],
            ],
        ]);

        $response->assertCreated();
        // 399 × 2 passengers = 798
        $this->assertEquals(798, $response->json('total_price'));
    }

    public function test_booking_can_be_retrieved_by_reference(): void
    {
        $flightId = $this->seedCacheAndGetFlightId();

        // Create a booking first, then fetch it back using the reference number.
        $created   = $this->postJson('/api/bookings', [
            'flight_id'  => $flightId,
            'passengers' => [
                ['first_name' => 'Tom', 'last_name' => 'Brown', 'type' => 'adult', 'document_number' => 'C9876543'],
            ],
        ]);

        $created->assertCreated();
        $reference = $created->json('reference');

        // GET /api/bookings/{reference} must return the same booking.
        $fetched = $this->getJson("/api/bookings/{$reference}");

        $fetched->assertOk();
        $fetched->assertJsonPath('reference', $reference);
        $fetched->assertJsonPath('flight_id', $flightId);
        $fetched->assertJsonPath('status', 'confirmed');
    }

    public function test_booking_with_unknown_flight_id_returns_422(): void
    {
        // If the flight_id is not found in the Redis cache (because the user never searched),
        // the service must reject the booking with a clear error message.
        Cache::flush();

        $response = $this->postJson('/api/bookings', [
            'flight_id'  => 'non-existent-flight-id',
            'passengers' => [
                ['first_name' => 'Eve', 'last_name' => 'Test', 'type' => 'adult', 'document_number' => 'X0000000'],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('message', fn($msg) => str_contains($msg, 'not found in cache'));
    }

    public function test_unknown_reference_returns_404(): void
    {
        // Laravel's firstOrFail() throws ModelNotFoundException which is auto-converted to 404.
        $response = $this->getJson('/api/bookings/BK-DOESNOTEXIST');

        $response->assertNotFound();
    }

    public function test_booking_validation_rejects_missing_passengers(): void
    {
        // CreateBookingRequest must block the request before it reaches the service layer.
        $response = $this->postJson('/api/bookings', [
            'flight_id' => 'some-flight-id',
            // passengers key is intentionally missing
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['passengers']);
    }
}
