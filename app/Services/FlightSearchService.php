<?php

namespace App\Services;

use App\DTOs\FlightDTO;
use App\FlightProviders\ProviderManager;
use Illuminate\Support\Facades\Cache;

class FlightSearchService
{
    public function __construct(
        private readonly ProviderManager $providerManager,
        private readonly DeduplicationService $deduplicationService,
    ) {}

    public function search(
        string $from,
        string $to,
        string $date,
        int $passengers = 1,
        array $filters = [],
        string $sortBy = 'price',
        string $sortOrder = 'asc',
    ): array {
        $cacheKey  = "flights:{$from}:{$to}:{$date}";
        $cached    = Cache::get($cacheKey);
        $wasCached = $cached !== null;

        if (!$wasCached) {
            /** @var array{flights: \App\DTOs\FlightDTO[], failed: string[]} $result */
            $result       = $this->providerManager->fetchAll($from, $to, $date);
            /** @var string[] $failed */
            $failed       = $result['failed'];
            $failedCount  = count($failed);
            $deduplicated = $this->deduplicationService->deduplicate($result['flights']);

            $providersQueried   = $this->providerManager->totalProviders();
            $providersSucceeded = $providersQueried - $failedCount;
            $cached = [
                'meta' => [
                    'providers_queried'    => $providersQueried,
                    'providers_succeeded'  => $providersSucceeded,
                    'providers_failed'     => $failed,
                    'total_unique_flights' => count($deduplicated),
                ],
                'flights' => array_map(fn(FlightDTO $f) => $f->toArray(), $deduplicated),
            ];

            Cache::put($cacheKey, $cached, 300);

            // Register this cache key in a driver-agnostic index so BookingService
            // can scan for a flight by ID without relying on Redis KEYS command.
            $index = Cache::get('flights:index', []);
            if (!in_array($cacheKey, $index)) {
                $index[] = $cacheKey;
                Cache::put('flights:index', $index, 300);
            }
        }

        $flights = $this->applyFilters($cached['flights'], $filters);
        $flights = $this->applySort($flights, $sortBy, $sortOrder);
        $flights = $this->applyPassengerPricing($flights, $passengers);

        return [
            'meta'    => array_merge($cached['meta'], [
                'cached'         => $wasCached,
                'passengers'     => $passengers,
                'filtered_count' => count($flights),
            ]),
            'flights' => $flights,
        ];
    }

    private function applyFilters(array $flights, array $filters): array
    {
        if (isset($filters['max_price'])) {
            $flights = array_filter($flights, fn(array $f) => $f['price'] <= (float) $filters['max_price']);
        }

        if (isset($filters['stops'])) {
            $flights = array_filter($flights, fn(array $f) => $f['stops'] === (int) $filters['stops']);
        }

        if (isset($filters['airline'])) {
            $flights = array_filter($flights, fn(array $f) => $f['airline'] === $filters['airline']);
        }

        return array_values($flights);
    }

    private function applyPassengerPricing(array $flights, int $passengers): array
    {
        return array_map(function (array $flight) use ($passengers) {
            return array_merge($flight, [
                'passengers'  => $passengers,
                'total_price' => round($flight['price'] * $passengers, 2),
            ]);
        }, $flights);
    }

    private function applySort(array $flights, string $sortBy, string $sortOrder): array
    {
        $field = match ($sortBy) {
            'duration'  => 'duration_min',
            'departure' => 'departure_at',
            default     => 'price',
        };

        return collect($flights)
            ->sortBy($field, descending: $sortOrder === 'desc')
            ->values()
            ->all();
    }
}
