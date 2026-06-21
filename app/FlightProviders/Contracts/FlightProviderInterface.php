<?php

namespace App\FlightProviders\Contracts;

use App\DTOs\FlightDTO;

interface FlightProviderInterface
{
    /**
     * Fetch flights from this provider.
     *
     * @return FlightDTO[]
     */
    public function fetch(string $from, string $to, string $date): array;

    public function getName(): string;
}
