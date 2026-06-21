<?php

namespace App\FlightProviders\Adapters;

use App\DTOs\FlightDTO;
use App\FlightProviders\Contracts\FlightProviderInterface;
use App\Http\Controllers\Mock\ProviderCController;
use Carbon\Carbon;

class ProviderCAdapter implements FlightProviderInterface
{
    public function fetch(string $from, string $to, string $date): array
    {
        $data = app(ProviderCController::class)()->getData(true);

        return array_map(
            fn(array $flight) => new FlightDTO(
                id: '',
                flightNumber: $flight['code'],
                airline: $flight['iata'],
                from: $flight['route']['src'],
                to: $flight['route']['dst'],
                departureAt: Carbon::createFromTimestamp($flight['times']['dep']),
                arrivalAt: Carbon::createFromTimestamp($flight['times']['arr']),
                stops: $flight['layovers'],
                price: (float) $flight['total_price'],
                currency: $flight['currency'],
                source: $this->getName(),
            ),
            $data['results'] ?? []
        );
    }

    public function getName(): string
    {
        return 'provider_c';
    }
}
