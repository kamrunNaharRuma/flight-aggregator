<?php

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BookingService
{
    public function create(string $flightId, array $passengers): Booking
    {
        $flightData = $this->resolveFlightFromCache($flightId);

        $totalPrice = $flightData['price'] * count($passengers);

        return Booking::create([
                'reference'   => Booking::REFERENCE_PREFIX . strtoupper(Str::random(6)),
                'flight_id'   => $flightId,
                'flight_data' => $flightData,
                'passengers'  => $passengers,
                'total_price' => $totalPrice,
                'currency'    => $flightData['currency'],
                'status'      => Booking::STATUS_CONFIRMED,
            ]);
    }

    public function findByReference(string $reference): Booking
    {
        return Booking::where('reference', $reference)->firstOrFail();
    }

    private function resolveFlightFromCache(string $flightId): array
    {
        $keys = Cache::get('flights:index', []);

        foreach ($keys as $key) {
            $cached = Cache::get($key);

            if (!is_array($cached)) {
                continue;
            }

            foreach ($cached['flights'] ?? [] as $flight) {
                if ($flight['id'] === $flightId) {
                    return $flight;
                }
            }
        }

        throw new InvalidArgumentException("Flight ID [{$flightId}] not found in cache. Please search first.");
    }
}
