<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request): JsonResponse
    {
        $room = $request->room();
        $startsAt = $request->startsAt();
        $endsAt = $request->endsAt();
        $user = $request->user();
        $title = trim($request->string('title')->toString());

        $booking = DB::transaction(function () use ($room, $startsAt, $endsAt, $user, $title): Booking {
            Room::whereKey($room->id)->lockForUpdate()->firstOrFail();

            $isOccupied = Booking::query()
                ->whereBelongsTo($room)
                ->overlapping($startsAt, $endsAt)
                ->exists();

            if ($isOccupied) {
                throw ValidationException::withMessages([
                    'starts_at' => 'Комната занята в это время.',
                ]);
            }

            return $user->bookings()->create([
                'room_id' => $room->id,
                'title' => $title,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
        });

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, Booking $booking): Response
    {
        abort_unless(
            $booking->user_id === $request->user()->id,
            403,
            'Отменить бронь может только её автор.',
        );

        $booking->delete();

        Log::channel('bookings')->info('Booking cancelled', [
            'booking_id' => $booking->id,
            'user_id' => $request->user()->id,
        ]);

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
