<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProviderCController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'results' => [
                [
                    'iata'        => 'AA',
                    'route'       => ['src' => 'DAC', 'dst' => 'DXB'],
                    'times'       => ['dep' => 1782892800, 'arr' => 1782909000],
                    'layovers'    => 0,
                    'total_price' => 335,
                    'currency'    => 'USD',
                    'code'        => 'AA101',
                ],
                [
                    'iata'        => 'CJ',
                    'route'       => ['src' => 'DAC', 'dst' => 'DXB'],
                    'times'       => ['dep' => 1782885600, 'arr' => 1782903600],
                    'layovers'    => 2,
                    'total_price' => 270,
                    'currency'    => 'USD',
                    'code'        => 'CJ300',
                ],
                [
                    'iata'        => 'EK',
                    'route'       => ['src' => 'DAC', 'dst' => 'DXB'],
                    'times'       => ['dep' => 1782877500, 'arr' => 1782888600],
                    'layovers'    => 0,
                    'total_price' => 405,
                    'currency'    => 'USD',
                    'code'        => 'EK585',
                ],
            ],
        ]);
    }
}
