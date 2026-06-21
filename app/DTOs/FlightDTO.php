<?php

namespace App\DTOs;

use Carbon\Carbon;

class FlightDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $flightNumber,
        public readonly string $airline,
        public readonly string $from,
        public readonly string $to,
        public readonly Carbon $departureAt,
        public readonly Carbon $arrivalAt,
        public readonly int $stops,
        public readonly float $price,
        public readonly string $currency,
        public readonly string $source,
    ) {}

    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'flight_number' => $this->flightNumber,
            'airline'       => $this->airline,
            'from'          => $this->from,
            'to'            => $this->to,
            'departure_at'  => $this->departureAt->toIso8601String(),
            'arrival_at'    => $this->arrivalAt->toIso8601String(),
            'duration_min'  => (int) $this->departureAt->diffInMinutes($this->arrivalAt),
            'stops'         => $this->stops,
            'price'         => $this->price,
            'currency'      => $this->currency,
            'source'        => $this->source,
        ];
    }
}
