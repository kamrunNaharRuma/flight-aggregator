<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateBookingRequest;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function store(CreateBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->create(
                flightId:   $request->string('flight_id')->value(),
                passengers: $request->input('passengers'),
            );

            return response()->json($booking, 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(string $reference): JsonResponse
    {
        try {
            $booking = $this->bookingService->findByReference($reference);

            return response()->json($booking);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => "Booking [{$reference}] not found."], 404);
        }
    }
}
