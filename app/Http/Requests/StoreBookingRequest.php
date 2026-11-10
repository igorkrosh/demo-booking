<?php

namespace App\Http\Requests;

use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

class StoreBookingRequest extends FormRequest
{
    private ?Room $resolvedRoom = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Укажите переговорную.',
            'room_id.integer' => 'Переговорная указана неверно.',
            'room_id.exists' => 'Переговорная не найдена.',
            'title.required' => 'Укажите название встречи.',
            'title.string' => 'Название встречи должно быть строкой.',
            'title.max' => 'Название встречи не должно быть длиннее 255 символов.',
            'starts_at.required' => 'Укажите время начала.',
            'starts_at.date' => 'Время начала должно быть датой в формате ISO 8601.',
            'ends_at.required' => 'Укажите время окончания.',
            'ends_at.date' => 'Время окончания должно быть датой в формате ISO 8601.',
            'ends_at.after' => 'Время окончания должно быть позже времени начала.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                try {
                    $startsAt = $this->startsAt();
                    $endsAt = $this->endsAt();
                } catch (Throwable) {
                    $validator->errors()->add('starts_at', 'Не удалось разобрать время начала.');

                    return;
                }

                $durationMinutes = (int) $startsAt->diffInMinutes($endsAt);

                if ($durationMinutes < 15 || $durationMinutes > 240) {
                    $validator->errors()->add('ends_at', 'Встреча должна длиться от 15 минут до 4 часов.');
                }

                $timezone = $this->room()->office->timezone;
                $localStart = $startsAt->setTimezone($timezone);
                $localEnd = $endsAt->setTimezone($timezone);

                if ($localStart->minute % 15 !== 0 || $localStart->second !== 0 || $localStart->micro !== 0) {
                    $validator->errors()->add('starts_at', 'Время начала должно быть кратно 15 минутам.');
                }

                if ($localEnd->minute % 15 !== 0 || $localEnd->second !== 0 || $localEnd->micro !== 0) {
                    $validator->errors()->add('ends_at', 'Время окончания должно быть кратно 15 минутам.');
                }

                $workStart = $localStart->startOfDay()->setTime(9, 0);
                $workEnd = $localStart->startOfDay()->setTime(20, 0);

                if ($localStart->lessThan($workStart)) {
                    $validator->errors()->add('starts_at', 'Бронь должна начинаться не раньше 09:00 по местному времени офиса.');
                }

                if ($localEnd->greaterThan($workEnd)) {
                    $validator->errors()->add('ends_at', 'Бронь должна заканчиваться не позже 20:00 по местному времени офиса.');
                }
            },
        ];
    }

    public function room(): Room
    {
        return $this->resolvedRoom ??= Room::with('office')->findOrFail($this->integer('room_id'));
    }

    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('starts_at'))->utc();
    }

    public function endsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->input('ends_at'))->utc();
    }
}
