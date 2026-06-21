<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProviderBController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                [
                    'airline_code'   => 'BS',
                    'origin'         => 'DAC',
                    'destination'    => 'DXB',
                    'departure_time' => '2026-07-01 09:15',
                    'arrival_time'   => '2026-07-01 15:00',
                    'segments'       => 1,
                    'price'          => ['amount' => 295, 'currency' => 'USD'],
                    'number'         => 'BS220',
                ],
                [
                    'airline_code'   => 'BS',
                    'origin'         => 'DAC',
                    'destination'    => 'DXB',
                    'departure_time' => '2026-07-01 14:30',
                    'arrival_time'   => '2026-07-01 19:20',
                    'segments'       => 1,
                    'price'          => ['amount' => 265, 'currency' => 'USD'],
                    'number'         => 'BS118',
                ],
                [
                    'airline_code'   => 'EK',
                    'origin'         => 'DAC',
                    'destination'    => 'DXB',
                    'departure_time' => '2026-07-01 03:45',
                    'arrival_time'   => '2026-07-01 06:50',
                    'segments'       => 0,
                    'price'          => ['amount' => 399, 'currency' => 'USD'],
                    'number'         => 'EK585',
                ],
            ],
        ]);
    }
}
