<?php

namespace App\FlightProviders;

use App\DTOs\FlightDTO;
use App\FlightProviders\Contracts\FlightProviderInterface;
use Illuminate\Support\Facades\Log;

class ProviderManager
{
    /**
     * @param FlightProviderInterface[] $providers
     */
    public function __construct(private readonly array $providers) {}

    public function totalProviders(): int
    {
        return count($this->providers);
    }

    /**
     * @return array{flights: FlightDTO[], failed: string[]}
     */
    public function fetchAll(string $from, string $to, string $date): array
    {
        $flights = [];
        $failed  = [];

        foreach ($this->providers as $provider) {
            try {
                $results = $provider->fetch($from, $to, $date);
                array_push($flights, ...$results);
            } catch (\Throwable $e) {
                $failed[] = $provider->getName();
                Log::warning("Provider {$provider->getName()} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'flights' => $flights,
            'failed'  => $failed,
        ];
    }
}
