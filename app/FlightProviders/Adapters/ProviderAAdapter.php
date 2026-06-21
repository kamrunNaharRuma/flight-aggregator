<?php

namespace App\FlightProviders\Adapters;

use App\DTOs\FlightDTO;
use App\FlightProviders\Contracts\FlightProviderInterface;
use App\Http\Controllers\Mock\ProviderAController;
use Carbon\Carbon;

class ProviderAAdapter implements FlightProviderInterface
{
    public function fetch(string $from, string $to, string $date): array
    {
        $data = app(ProviderAController::class)()->getData(true);

        return array_map(
            fn(array $flight) => new FlightDTO(
                id: '',
                flightNumber: $flight['flight_no'],
                airline: $flight['carrier'],
                from: $flight['from'],
                to: $flight['to'],
                departureAt: Carbon::parse($flight['depart']),
                arrivalAt: Carbon::parse($flight['arrive']),
                stops: $flight['stops'],
                price: (float) $flight['fare_usd'],
                currency: 'USD',
                source: $this->getName(),
            ),
            $data['flights'] ?? []
        );
    }

    public function getName(): string
    {
        return 'provider_a';
    }
}
