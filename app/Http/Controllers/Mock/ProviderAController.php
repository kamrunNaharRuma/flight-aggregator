<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProviderAController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'flights' => [
                [
                    'carrier'   => 'AA',
                    'from'      => 'DAC',
                    'to'        => 'DXB',
                    'depart'    => '2026-07-01T08:00:00',
                    'arrive'    => '2026-07-01T12:30:00',
                    'stops'     => 0,
                    'fare_usd'  => 320.00,
                    'flight_no' => 'AA101',
                ],
                [
                    'carrier'   => 'AA',
                    'from'      => 'DAC',
                    'to'        => 'DXB',
                    'depart'    => '2026-07-01T22:10:00',
                    'arrive'    => '2026-07-02T02:40:00',
                    'stops'     => 0,
                    'fare_usd'  => 280.00,
                    'flight_no' => 'AA205',
                ],
                [
                    'carrier'   => 'BS',
                    'from'      => 'DAC',
                    'to'        => 'DXB',
                    'depart'    => '2026-07-01T09:15:00',
                    'arrive'    => '2026-07-01T15:00:00',
                    'stops'     => 1,
                    'fare_usd'  => 310.00,
                    'flight_no' => 'BS220',
                ],
                [
                    'carrier'   => 'EK',
                    'from'      => 'DAC',
                    'to'        => 'DXB',
                    'depart'    => '2026-07-01T03:45:00',
                    'arrive'    => '2026-07-01T06:50:00',
                    'stops'     => 0,
                    'fare_usd'  => 410.00,
                    'flight_no' => 'EK585',
                ],
            ],
        ]);
    }
}
