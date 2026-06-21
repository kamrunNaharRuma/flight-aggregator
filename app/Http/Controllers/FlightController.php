<?php

namespace App\Http\Controllers;

use App\Http\Requests\FlightSearchRequest;
use App\Services\FlightSearchService;
use Illuminate\Http\JsonResponse;

class FlightController extends Controller
{
    public function __construct(private readonly FlightSearchService $searchService) {}

    public function search(FlightSearchRequest $request): JsonResponse
    {
        $result = $this->searchService->search(
            from: $request->string('from')->upper()->value(),
            to: $request->string('to')->upper()->value(),
            date: $request->string('date')->value(),
            passengers: (int) $request->input('passengers', 1),
            filters: $request->input('filter', []),
            sortBy: $request->input('sort', 'price'),
            sortOrder: $request->input('order', 'asc'),
        );

        return response()->json($result);
    }
}
