<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_cancel_own_booking_and_returns_204(): void
    {
        $user = User::factory()->create();
        $booking = $this->bookingFor($user);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->assertDatabaseMissing('bookings', ['id' => $booking->id]);
    }

    public function test_forbids_cancelling_another_users_booking_with_403(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $booking = $this->bookingFor($owner);
        Sanctum::actingAs($otherUser);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertForbidden();

        $this->assertDatabaseHas('bookings', ['id' => $booking->id]);
    }

    public function test_returns_404_when_booking_does_not_exist(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/999999')->assertNotFound();
    }

    public function test_returns_404_for_non_numeric_booking_id(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/bookings/abc')->assertNotFound();
    }

    public function test_returns_401_when_not_authenticated(): void
    {
        $booking = $this->bookingFor(User::factory()->create());

        $this->deleteJson("/api/bookings/{$booking->id}")->assertUnauthorized();
    }

    public function test_allows_rebooking_after_cancellation(): void
    {
        $user = User::factory()->create();
        $room = Room::factory()->for(Office::factory())->create();
        $booking = Booking::factory()->for($room)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->deleteJson("/api/bookings/{$booking->id}")->assertNoContent();

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ])->assertCreated();
    }

    private function bookingFor(User $user): Booking
    {
        $room = Room::factory()->for(Office::factory())->create();

        return Booking::factory()->for($room)->for($user)->create();
    }
}
