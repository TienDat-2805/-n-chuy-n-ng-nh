<?php

namespace App\Services;

use App\Models\Room;
use App\Models\ScheduleConflict;
use App\Models\Section;
use App\Models\SectionMeeting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TimetableSchedulerService
{
    private const DAYS = [2, 3, 4, 5, 6, 7];
    private const SESSIONS = ['morning', 'afternoon'];
    private const SESSION_RANGES = [
        'morning' => [1, 6],
        'afternoon' => [7, 12],
    ];
    private const DEFAULT_DURATION = 3;

    public function generate(): array
    {
        $sections = $this->sectionsForScheduling();
        $rooms = $this->schedulableRooms();

        if ($rooms->isEmpty()) {
            return [
                'total' => $sections->count(),
                'scheduled' => 0,
                'unscheduled' => $sections->count(),
                'reasons' => ['Chưa có phòng học hợp lệ để xếp lịch.'],
            ];
        }

        ScheduleConflict::query()->delete();
        SectionMeeting::query()->delete();

        $roomBusy = [];
        $lecturerBusy = [];
        $roomLoads = [];
        $dayLoads = [];
        $slotLoads = [];
        $periodLoads = [];
        $scheduled = 0;
        $reasons = [];

        DB::transaction(function () use ($sections, $rooms, &$roomBusy, &$lecturerBusy, &$roomLoads, &$dayLoads, &$slotLoads, &$periodLoads, &$scheduled, &$reasons) {
            foreach ($sections as $section) {
                $lecturers = $section->lecturers->values();

                if ($lecturers->isEmpty()) {
                    $reasons[] = "{$section->section_code}: chưa có giảng viên.";
                    continue;
                }

                $duration = $this->durationFor($section);
                $candidateSlots = $this->candidateSlots($lecturers);
                $placed = false;

                if (empty($candidateSlots)) {
                    $reasons[] = "{$section->section_code}: giảng viên không có ngày/buổi phù hợp.";
                    continue;
                }

                $placement = $this->bestPlacement(
                    $section,
                    $lecturers,
                    $rooms,
                    $candidateSlots,
                    $duration,
                    $roomBusy,
                    $lecturerBusy,
                    $roomLoads,
                    $dayLoads,
                    $slotLoads,
                    $periodLoads
                );

                if ($placement) {
                    SectionMeeting::create([
                        'section_id' => $section->id,
                        'room_id' => $placement['room']->id,
                        'day_of_week' => $placement['day'],
                        'start_period' => $placement['start_period'],
                        'end_period' => $placement['end_period'],
                        'note' => 'Tự xếp theo phòng trống và ràng buộc giảng viên',
                    ]);

                    $this->markBusy($roomBusy, 'room:' . $placement['room']->id, $placement['day'], $placement['start_period'], $placement['end_period']);

                    foreach ($lecturers as $lecturer) {
                        $this->markBusy($lecturerBusy, 'lecturer:' . $lecturer->id, $placement['day'], $placement['start_period'], $placement['end_period']);
                    }

                    $slotKey = $placement['day'] . '_' . $placement['session'];
                    $periodKey = $placement['day'] . '_' . $placement['start_period'];

                    $roomLoads[$placement['room']->id] = ($roomLoads[$placement['room']->id] ?? 0) + 1;
                    $dayLoads[$placement['day']] = ($dayLoads[$placement['day']] ?? 0) + 1;
                    $slotLoads[$slotKey] = ($slotLoads[$slotKey] ?? 0) + 1;
                    $periodLoads[$periodKey] = ($periodLoads[$periodKey] ?? 0) + 1;

                    $scheduled++;
                    $placed = true;
                }

                if (! $placed) {
                    $reasons[] = $this->unscheduledReason($section, $rooms);
                }
            }
        });

        return [
            'total' => $sections->count(),
            'scheduled' => $scheduled,
            'unscheduled' => max(0, $sections->count() - $scheduled),
            'reasons' => array_slice($reasons, 0, 10),
        ];
    }

    private function sectionsForScheduling(): Collection
    {
        return Section::query()
            ->with(['subject', 'lecturers'])
            ->get()
            ->sort(function (Section $a, Section $b) {
                return $this->comparePriority($this->sectionPriority($a), $this->sectionPriority($b));
            })
            ->values();
    }

    private function sectionPriority(Section $section): array
    {
        $lecturers = $section->lecturers;
        $lecturerCount = $lecturers->count();

        if ($lecturerCount === 0) {
            return [1, 99, 0, 0, $section->section_code];
        }

        $candidateCount = count($this->candidateSlots($lecturers));
        $limitedCount = $lecturers->where('availability_mode', 'limited')->count();

        return [
            $candidateCount === 0 ? 1 : 0,
            $candidateCount > 0 ? $candidateCount : 99,
            -$limitedCount,
            -$lecturerCount,
            $section->section_code,
        ];
    }

    private function schedulableRooms(): Collection
    {
        return Room::query()
            ->whereIn('type', ['room', 'lab'])
            ->orderBy('name')
            ->get();
    }

    private function durationFor(Section $section): int
    {
        $credits = (int) ($section->subject?->credits ?? 0);

        if ($credits >= 1 && $credits <= 6) {
            return $credits;
        }

        return self::DEFAULT_DURATION;
    }

    private function candidateSlots(Collection $lecturers): array
    {
        $slots = null;

        foreach ($lecturers as $lecturer) {
            $lecturerSlots = $this->lecturerCandidateSlots($lecturer);

            if ($lecturer->availability_mode === 'limited' && empty($lecturerSlots)) {
                return [];
            }

            $slots = $slots === null
                ? $lecturerSlots
                : array_values(array_intersect($slots, $lecturerSlots));
        }

        return $this->sortSlots($slots ?? []);
    }

    private function lecturerCandidateSlots($lecturer): array
    {
        if ($lecturer->availability_mode === 'limited') {
            return $this->validSlots($lecturer->available_slots ?? []);
        }

        return $this->allSlots();
    }

    private function validSlots(array $slots): array
    {
        $valid = array_flip($this->allSlots());

        return array_values(array_filter(array_unique($slots), fn ($slot) => isset($valid[$slot])));
    }

    private function allSlots(): array
    {
        $slots = [];

        foreach (self::DAYS as $day) {
            foreach (self::SESSIONS as $session) {
                $slots[] = "{$day}_{$session}";
            }
        }

        return $slots;
    }

    private function sortSlots(array $slots): array
    {
        $order = array_flip($this->allSlots());

        usort($slots, fn ($a, $b) => ($order[$a] ?? 999) <=> ($order[$b] ?? 999));

        return $slots;
    }

    private function startPeriods(string $session, int $duration): array
    {
        [$start, $end] = self::SESSION_RANGES[$session] ?? self::SESSION_RANGES['morning'];
        $starts = [];

        for ($period = $start; $period + $duration - 1 <= $end; $period += $duration) {
            $starts[] = $period;
        }

        return $starts;
    }

    private function bestPlacement(
        Section $section,
        Collection $lecturers,
        Collection $rooms,
        array $candidateSlots,
        int $duration,
        array $roomBusy,
        array $lecturerBusy,
        array $roomLoads,
        array $dayLoads,
        array $slotLoads,
        array $periodLoads
    ): ?array {
        $availableRooms = $this->orderedRoomsForSection($section, $rooms, $roomLoads);
        $candidates = [];

        if ($availableRooms->isEmpty()) {
            return null;
        }

        foreach ($candidateSlots as $slot) {
            [$day, $session] = explode('_', $slot);
            $day = (int) $day;

            foreach ($this->startPeriods($session, $duration) as $startPeriod) {
                $endPeriod = $startPeriod + $duration - 1;

                if (! $this->areLecturersFree($lecturerBusy, $lecturers, $day, $startPeriod, $endPeriod)) {
                    continue;
                }

                foreach ($availableRooms as $room) {
                    if (! $this->isRoomFree($roomBusy, $room->id, $day, $startPeriod, $endPeriod)) {
                        continue;
                    }

                    $candidates[] = [
                        'score' => $this->placementScore(
                            $section,
                            $room,
                            $day,
                            $session,
                            $startPeriod,
                            $dayLoads,
                            $slotLoads,
                            $periodLoads,
                            $roomLoads
                        ),
                        'room' => $room,
                        'day' => $day,
                        'session' => $session,
                        'start_period' => $startPeriod,
                        'end_period' => $endPeriod,
                    ];
                }
            }
        }

        usort($candidates, function (array $a, array $b) {
            return $this->comparePriority([
                $a['score'],
                $a['day'],
                $a['session'] === 'afternoon' ? 1 : 0,
                $a['start_period'],
                $a['room']->name,
            ], [
                $b['score'],
                $b['day'],
                $b['session'] === 'afternoon' ? 1 : 0,
                $b['start_period'],
                $b['room']->name,
            ]);
        });

        return $candidates[0] ?? null;
    }

    private function placementScore(
        Section $section,
        Room $room,
        int $day,
        string $session,
        int $startPeriod,
        array $dayLoads,
        array $slotLoads,
        array $periodLoads,
        array $roomLoads
    ): int {
        $slotKey = "{$day}_{$session}";
        $periodKey = "{$day}_{$startPeriod}";

        return (($dayLoads[$day] ?? 0) * 1000)
            + (($slotLoads[$slotKey] ?? 0) * 260)
            + (($periodLoads[$periodKey] ?? 0) * 80)
            + (($roomLoads[$room->id] ?? 0) * 14)
            + ($this->roomTypePenalty($room, $this->preferredRoomType($section)) * 40)
            + $this->dayPenalty($day)
            + $this->startPeriodPenalty($startPeriod);
    }

    private function dayPenalty(int $day): int
    {
        return match ($day) {
            2 => 0,
            3 => 4,
            4 => 8,
            5 => 12,
            6 => 16,
            7 => 3000,
            default => 5000,
        };
    }

    private function startPeriodPenalty(int $startPeriod): int
    {
        return match ($startPeriod) {
            1, 7 => 0,
            4, 10 => 25,
            default => 45,
        };
    }

    private function orderedRoomsForSection(Section $section, Collection $rooms, array $roomLoads): Collection
    {
        $preferredType = $this->preferredRoomType($section);

        return $rooms
            ->filter(fn (Room $room) => $this->isRoomAllowedForSection($section, $room))
            ->sort(function (Room $a, Room $b) use ($preferredType, $roomLoads) {
                return $this->comparePriority([
                    $this->roomTypePenalty($a, $preferredType),
                    $roomLoads[$a->id] ?? 0,
                    $a->name,
                ], [
                    $this->roomTypePenalty($b, $preferredType),
                    $roomLoads[$b->id] ?? 0,
                    $b->name,
                ]);
            })
            ->values();
    }

    private function comparePriority(array $left, array $right): int
    {
        $length = max(count($left), count($right));

        for ($index = 0; $index < $length; $index++) {
            $comparison = ($left[$index] ?? null) <=> ($right[$index] ?? null);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function preferredRoomType(Section $section): string
    {
        $text = $this->normalize(implode(' ', array_filter([
            $section->teaching_mode,
            $section->support_request,
            $section->note,
            $section->subject?->name,
            $section->subject?->subject_code,
        ])));

        if (
            (int) ($section->subject?->practice_credits ?? 0) > 0
            || str_contains($text, 'lab')
            || str_contains($text, 'thuc hanh')
            || str_contains($text, 'thi nghiem')
        ) {
            return 'lab';
        }

        return 'room';
    }

    private function isRoomAllowedForSection(Section $section, Room $room): bool
    {
        $isPhysicalEducation = $this->isPhysicalEducationSection($section);
        $isSportRoom = $this->isSportRoom($room);

        return $isPhysicalEducation ? $isSportRoom : ! $isSportRoom;
    }

    private function isPhysicalEducationSection(Section $section): bool
    {
        $text = $this->normalize(implode(' ', array_filter([
            $section->section_code,
            $section->teaching_mode,
            $section->support_request,
            $section->note,
            $section->subject?->subject_code,
            $section->subject?->name,
        ])));

        return str_contains($text, 'giao duc the chat')
            || str_contains($text, 'the chat')
            || str_contains($text, 'the thao')
            || str_contains($text, 'physical education')
            || str_contains($text, 'sport')
            || str_contains($text, 'gdtc')
            || preg_match('/\bpec\b/', $text) === 1;
    }

    private function isSportRoom(Room $room): bool
    {
        $text = $this->normalize(implode(' ', array_filter([
            $room->name,
            $room->type,
            $room->campus,
        ])));

        return str_contains($text, 'san')
            || str_contains($text, 'svd')
            || str_contains($text, 'the chat')
            || str_contains($text, 'the thao')
            || str_contains($text, 'stadium')
            || str_contains($text, 'gym');
    }

    private function roomTypePenalty(Room $room, string $preferredType): int
    {
        if ($room->type === $preferredType) {
            return 0;
        }

        return $preferredType === 'room' && $room->type === 'lab' ? 1 : 2;
    }

    private function unscheduledReason(Section $section, Collection $rooms): string
    {
        if ($this->isPhysicalEducationSection($section) && $rooms->filter(fn (Room $room) => $this->isSportRoom($room))->isEmpty()) {
            return "{$section->section_code}: môn thể chất nhưng chưa có điểm học thể chất hợp lệ.";
        }

        return "{$section->section_code}: không tìm được phòng trống trong khung giảng viên có thể dạy.";
    }

    private function isRoomFree(array $busy, int $roomId, int $day, int $start, int $end): bool
    {
        return $this->isFree($busy, 'room:' . $roomId, $day, $start, $end);
    }

    private function areLecturersFree(array $busy, Collection $lecturers, int $day, int $start, int $end): bool
    {
        foreach ($lecturers as $lecturer) {
            if (! $this->isFree($busy, 'lecturer:' . $lecturer->id, $day, $start, $end)) {
                return false;
            }
        }

        return true;
    }

    private function isFree(array $busy, string $key, int $day, int $start, int $end): bool
    {
        foreach ($busy[$key][$day] ?? [] as $range) {
            if ($start <= $range['end'] && $end >= $range['start']) {
                return false;
            }
        }

        return true;
    }

    private function markBusy(array &$busy, string $key, int $day, int $start, int $end): void
    {
        $busy[$key][$day][] = [
            'start' => $start,
            'end' => $end,
        ];
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a',
            'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a',
            'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e',
            'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o',
            'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o',
            'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u',
            'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
            'đ' => 'd',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
