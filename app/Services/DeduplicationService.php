<?php

namespace App\Services;

use App\DTOs\FlightDTO;

class DeduplicationService
{
    /**
     * @param  FlightDTO[]  $flights
     * @return FlightDTO[]
     */
    public function deduplicate(array $flights): array
    {
        $map = [];

        foreach ($flights as $flight) {
            $departureUtc = $flight->departureAt->utc()->toIso8601String();
            $key = $flight->flightNumber . '|' . $departureUtc;

            if (!isset($map[$key]) || $flight->price < $map[$key]->price) {
                $map[$key] = $flight;
            }
        }

        return array_values(array_map(function (FlightDTO $flight, string $key) {
            return new FlightDTO(
                id: substr(hash('sha256', $key), 0, 16),
                flightNumber: $flight->flightNumber,
                airline: $flight->airline,
                from: $flight->from,
                to: $flight->to,
                departureAt: $flight->departureAt,
                arrivalAt: $flight->arrivalAt,
                stops: $flight->stops,
                price: $flight->price,
                currency: $flight->currency,
                source: $flight->source,
            );
        }, $map, array_keys($map)));
    }
}
