<?php

namespace App\FlightProviders\Adapters;

use App\DTOs\FlightDTO;
use App\FlightProviders\Contracts\FlightProviderInterface;
use App\Http\Controllers\Mock\ProviderBController;
use Carbon\Carbon;

class ProviderBAdapter implements FlightProviderInterface
{
    public function fetch(string $from, string $to, string $date): array
    {
        $data = app(ProviderBController::class)()->getData(true);

        return array_map(
            fn(array $flight) => new FlightDTO(
                id: '',
                flightNumber: $flight['number'],
                airline: $flight['airline_code'],
                from: $flight['origin'],
                to: $flight['destination'],
                departureAt: Carbon::parse($flight['departure_time']),
                arrivalAt: Carbon::parse($flight['arrival_time']),
                stops: $flight['segments'],
                price: (float) $flight['price']['amount'],
                currency: $flight['price']['currency'],
                source: $this->getName(),
            ),
            $data['data'] ?? []
        );
    }

    public function getName(): string
    {
        return 'provider_b';
    }
}
