<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_and_returns_201(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/bookings', $this->payload($room));

        $response->assertCreated()
            ->assertJsonPath('room_id', $room->id)
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02 07:00:00',
            'ends_at' => '2026-11-02 08:00:00',
        ]);
    }

    public function test_ignores_user_id_from_payload(): void
    {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($author);

        $this->postJson('/api/bookings', $this->payload($room, ['user_id' => $otherUser->id]))
            ->assertCreated()
            ->assertJsonPath('user_id', $author->id);

        $this->assertDatabaseHas('bookings', ['user_id' => $author->id]);
        $this->assertDatabaseMissing('bookings', ['user_id' => $otherUser->id]);
    }

    public function test_rejects_overlapping_booking_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Booking::factory()->for($room)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:30:00+03:00',
            'ends_at' => '2026-11-02T11:30:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at')
            ->assertJsonPath('errors.starts_at.0', 'Комната занята в это время.');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_booking_right_after_existing(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Booking::factory()->for($room)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T12:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_allows_booking_right_before_existing(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Booking::factory()->for($room)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_allows_same_time_in_another_room(): void
    {
        $user = User::factory()->create();
        $firstRoom = $this->moscowRoom();
        $secondRoom = $this->moscowRoom();
        Booking::factory()->for($firstRoom)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($secondRoom))->assertCreated();
    }

    public function test_accepts_minimum_15_minute_booking(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:15:00+03:00',
        ]))->assertCreated();
    }

    public function test_accepts_maximum_4_hour_booking(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T14:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_rejects_booking_shorter_than_15_minutes_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:10:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath('errors.ends_at.0', 'Встреча должна длиться от 15 минут до 4 часов.');
    }

    public function test_rejects_booking_longer_than_4_hours_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T14:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath('errors.ends_at.0', 'Встреча должна длиться от 15 минут до 4 часов.');
    }

    public function test_rejects_start_not_on_15_minute_grid_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:10:00+03:00',
            'ends_at' => '2026-11-02T11:10:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at')
            ->assertJsonPath('errors.starts_at.0', 'Время начала должно быть кратно 15 минутам.');
    }

    public function test_rejects_end_not_on_15_minute_grid_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:10:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath('errors.ends_at.0', 'Время окончания должно быть кратно 15 минутам.');
    }

    public function test_rejects_seconds_in_time_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:30+03:00',
            'ends_at' => '2026-11-02T11:00:30+03:00',
        ]))->assertUnprocessable();
    }

    public function test_accepts_booking_at_start_of_working_day(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T09:00:00+03:00',
            'ends_at' => '2026-11-02T09:15:00+03:00',
        ]))->assertCreated();
    }

    public function test_accepts_booking_ending_at_close_of_working_day(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T19:45:00+03:00',
            'ends_at' => '2026-11-02T20:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_rejects_booking_starting_before_working_hours_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T08:45:00+03:00',
            'ends_at' => '2026-11-02T09:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at')
            ->assertJsonPath('errors.starts_at.0', 'Бронь должна начинаться не раньше 09:00 по местному времени офиса.');
    }

    public function test_rejects_booking_ending_after_working_hours_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T19:45:00+03:00',
            'ends_at' => '2026-11-02T20:15:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath('errors.ends_at.0', 'Бронь должна заканчиваться не позже 20:00 по местному времени офиса.');
    }

    public function test_accepts_novosibirsk_booking_with_local_offset(): void
    {
        $user = User::factory()->create();
        $room = $this->novosibirskRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+07:00',
            'ends_at' => '2026-11-02T11:00:00+07:00',
        ]))->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 03:00:00',
            'ends_at' => '2026-11-02 04:00:00',
        ]);
    }

    public function test_accepts_novosibirsk_booking_with_utc_offset(): void
    {
        $user = User::factory()->create();
        $room = $this->novosibirskRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T03:00:00Z',
            'ends_at' => '2026-11-02T04:00:00Z',
        ]))->assertCreated();

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'starts_at' => '2026-11-02 03:00:00',
            'ends_at' => '2026-11-02 04:00:00',
        ]);
    }

    public function test_rejects_novosibirsk_booking_after_local_working_hours_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->novosibirskRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T13:30:00Z',
            'ends_at' => '2026-11-02T14:30:00Z',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_detects_overlap_when_same_instant_is_sent_with_different_offsets(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Booking::factory()->for($room)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T07:00:00Z',
            'ends_at' => '2026-11-02T08:00:00Z',
        ]))->assertUnprocessable();
    }

    public function test_does_not_conflict_for_different_instants_with_same_wall_clock(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Booking::factory()->for($room)->for($user)->between(
            '2026-11-02T10:00:00+03:00',
            '2026-11-02T11:00:00+03:00',
        )->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00Z',
            'ends_at' => '2026-11-02T11:00:00Z',
        ]))->assertCreated();
    }

    public function test_rejects_empty_payload_with_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
    }

    public function test_rejects_nonexistent_room_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, ['room_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('room_id')
            ->assertJsonPath('errors.room_id.0', 'Переговорная не найдена.');
    }

    public function test_rejects_invalid_date_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, ['starts_at' => 'not-a-date']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at')
            ->assertJsonPath('errors.starts_at.0', 'Время начала должно быть датой в формате ISO 8601.');
    }

    public function test_rejects_ends_at_equal_to_starts_at_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at')
            ->assertJsonPath('errors.ends_at.0', 'Время окончания должно быть позже времени начала.');
    }

    public function test_rejects_ends_at_before_starts_at_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2026-11-02T11:00:00+03:00',
            'ends_at' => '2026-11-02T10:00:00+03:00',
        ]))->assertUnprocessable();
    }

    public function test_rejects_too_long_title_with_422(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, ['title' => str_repeat('a', 256)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title')
            ->assertJsonPath('errors.title.0', 'Название встречи не должно быть длиннее 255 символов.');
    }

    public function test_allows_booking_in_the_past(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2020-01-15T10:00:00+03:00',
            'ends_at' => '2020-01-15T11:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_allows_booking_in_the_far_future(): void
    {
        $user = User::factory()->create();
        $room = $this->moscowRoom();
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->payload($room, [
            'starts_at' => '2030-01-15T10:00:00+03:00',
            'ends_at' => '2030-01-15T11:00:00+03:00',
        ]))->assertCreated();
    }

    public function test_returns_401_when_not_authenticated(): void
    {
        $room = $this->moscowRoom();

        $this->postJson('/api/bookings', $this->payload($room))->assertUnauthorized();
    }

    private function moscowRoom(): Room
    {
        return Room::factory()->for(Office::factory())->create();
    }

    private function novosibirskRoom(): Room
    {
        return Room::factory()->for(Office::factory()->novosibirsk())->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-02T10:00:00+03:00',
            'ends_at' => '2026-11-02T11:00:00+03:00',
        ], $overrides);
    }
}
