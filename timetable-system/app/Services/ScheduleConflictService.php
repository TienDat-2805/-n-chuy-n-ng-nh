<?php

namespace App\Services;

use App\Models\ScheduleConflict;
use App\Models\Room;
use App\Models\SectionMeeting;
use Illuminate\Support\Collection;

class ScheduleConflictService
{
    private const MAX_PERIOD_PER_DAY = 12;
    private const ALLOWED_DAYS = [2, 3, 4, 5, 6, 7];
    private const MAX_STORED_CONFLICTS = 300;

    private ?Collection $availabilityMeetings = null;

    public function detect(int $limit = self::MAX_STORED_CONFLICTS): int
    {
        return $this->detectOptimized($limit);

        ScheduleConflict::query()->delete();
        $this->availabilityMeetings = null;

        $meetings = SectionMeeting::query()
            ->with(['section.subject', 'section.lecturers', 'room'])
            ->whereHas('section.lecturers')
            ->whereNotNull('day_of_week')
            ->whereNotNull('start_period')
            ->whereNotNull('end_period')
            ->get();

        $created = 0;
        $validMeetings = collect();

        foreach ($meetings as $meeting) {
            if (! $this->isAllowedMeeting($meeting)) {
                $created += $this->createConflict(
                    'invalid_time_conflict',
                    $meeting,
                    null,
                    'Lịch học nằm ngoài khung cho phép. Hệ thống chỉ xếp từ Thứ 2 đến Thứ 7, tiết 1-12.'
                );
                continue;
            }

            $validMeetings->push($meeting);
        }

        for ($i = 0; $i < $validMeetings->count(); $i++) {
            for ($j = $i + 1; $j < $validMeetings->count(); $j++) {
                $a = $validMeetings[$i];
                $b = $validMeetings[$j];

                if (! $this->isSameTime($a, $b)) {
                    continue;
                }

                $type = null;
                $messages = [];

                if ($this->isSameSection($a, $b)) {
                    $type = 'section_conflict';
                    $messages[] = 'Một lớp học phần có nhiều lịch bị trùng thời gian.';
                }

                if ($this->hasSameLecturer($a, $b)) {
                    $type ??= 'lecturer_conflict';
                    $messages[] = 'Một giảng viên bị phân công dạy nhiều lớp cùng thời điểm.';
                }

                if ($this->isSameCheckableRoom($a, $b)) {
                    $type ??= 'room_conflict';
                    $messages[] = 'Một phòng học thật bị xếp cho nhiều lớp cùng thời điểm.';
                }

                if (! $messages) {
                    continue;
                }

                if (count($messages) > 1) {
                    $type = 'mixed_conflict';
                }

                $created += $this->createConflict($type, $a, $b, implode(' ', $messages));
            }
        }

        return $created;
    }

    private function detectOptimized(int $limit): int
    {
        ScheduleConflict::query()->delete();
        $this->availabilityMeetings = null;

        $meetings = SectionMeeting::query()
            ->with(['section.subject', 'section.lecturers', 'room'])
            ->whereHas('section.lecturers')
            ->whereNotNull('day_of_week')
            ->whereNotNull('start_period')
            ->whereNotNull('end_period')
            ->get();

        $created = 0;
        $buckets = [];
        $validMeetings = collect();

        foreach ($meetings as $meeting) {
            if (! $this->isAllowedMeeting($meeting)) {
                $created += $this->createConflict(
                    'invalid_time_conflict',
                    $meeting,
                    null,
                    'Lịch học nằm ngoài khung cho phép. Hệ thống chỉ xếp từ Thứ 2 đến Thứ 7, tiết 1-12.'
                );

                if ($created >= $limit) {
                    return $created;
                }

                continue;
            }

            $availabilityMessages = $this->lecturerAvailabilityMessages($meeting);

            if ($availabilityMessages) {
                $created += $this->createConflict(
                    'lecturer_availability_conflict',
                    $meeting,
                    null,
                    implode(' ', $availabilityMessages)
                );

                if ($created >= $limit) {
                    return $created;
                }
            }

            $roomAssignmentMessage = $this->roomAssignmentMessage($meeting);

            if ($roomAssignmentMessage) {
                $created += $this->createConflict(
                    'room_assignment_conflict',
                    $meeting,
                    null,
                    $roomAssignmentMessage
                );

                if ($created >= $limit) {
                    return $created;
                }
            }

            for ($period = (int) $meeting->start_period; $period <= (int) $meeting->end_period; $period++) {
                $buckets[(int) $meeting->day_of_week][$period][] = $meeting;
            }

            $validMeetings->push($meeting);
        }

        if ($this->detectLecturerCampusConflicts($validMeetings, $limit, $created)) {
            return $created;
        }

        $checkedPairs = [];

        foreach ($buckets as $dayBuckets) {
            foreach ($dayBuckets as $bucketMeetings) {
                $count = count($bucketMeetings);

                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = $bucketMeetings[$i];
                        $b = $bucketMeetings[$j];
                        $pairKey = $a->id < $b->id ? "{$a->id}:{$b->id}" : "{$b->id}:{$a->id}";

                        if (isset($checkedPairs[$pairKey]) || ! $this->isSameTime($a, $b)) {
                            continue;
                        }

                        $checkedPairs[$pairKey] = true;
                        $type = null;
                        $messages = [];

                        if ($this->isSameSection($a, $b)) {
                            $type = 'section_conflict';
                            $messages[] = 'Một lớp học phần có nhiều lịch bị trùng thời gian.';
                        }

                        if ($this->hasSameLecturer($a, $b)) {
                            $type ??= 'lecturer_conflict';
                            $messages[] = 'Một giảng viên bị phân công dạy nhiều lớp cùng thời điểm.';
                        }

                        if ($this->isSameCheckableRoom($a, $b)) {
                            $type ??= 'room_conflict';
                            $messages[] = 'Một phòng học thật bị xếp cho nhiều lớp cùng thời điểm.';
                        }

                        if (! $messages) {
                            continue;
                        }

                        if (count($messages) > 1) {
                            $type = 'mixed_conflict';
                        }

                        $created += $this->createConflict($type, $a, $b, implode(' ', $messages));

                        if ($created >= $limit) {
                            return $created;
                        }
                    }
                }
            }
        }

        return $created;
    }

    private function detectLecturerCampusConflicts(Collection $meetings, int $limit, int &$created): bool
    {
        $groups = [];

        foreach ($meetings as $meeting) {
            $meeting->loadMissing(['section.lecturers', 'room']);
            $campus = $this->campusForMeeting($meeting);

            if (! $campus) {
                continue;
            }

            $day = (int) $meeting->day_of_week;

            foreach ($meeting->section?->lecturers ?? collect() as $lecturer) {
                $groups[$lecturer->id][$day][$campus] ??= [
                    'lecturer' => $lecturer,
                    'meeting' => $meeting,
                ];
            }
        }

        foreach ($groups as $dayGroups) {
            foreach ($dayGroups as $day => $campusGroups) {
                if (count($campusGroups) < 2) {
                    continue;
                }

                $campuses = array_keys($campusGroups);

                for ($i = 0; $i < count($campuses) - 1; $i++) {
                    for ($j = $i + 1; $j < count($campuses); $j++) {
                        $first = $campusGroups[$campuses[$i]];
                        $second = $campusGroups[$campuses[$j]];
                        $lecturerName = $first['lecturer']->name ?? 'Giảng viên';

                        $created += $this->createConflict(
                            'lecturer_campus_conflict',
                            $first['meeting'],
                            $second['meeting'],
                            "Giảng viên {$lecturerName} dạy nhiều cơ sở trong cùng {$this->dayLabel((int) $day)}: {$campuses[$i]} và {$campuses[$j]}. Nên ưu tiên một giảng viên chỉ dạy tại một cơ sở trong một ngày để giảm di chuyển."
                        );

                        if ($created >= $limit) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    public function suggestions(ScheduleConflict $conflict, int $limit = 5): array
    {
        $meeting = $conflict->meeting;

        if (! $meeting || ! $meeting->start_period || ! $meeting->end_period) {
            return [];
        }

        $meeting->loadMissing(['section.lecturers', 'room']);

        $duration = $meeting->end_period - $meeting->start_period + 1;
        if ($duration <= 0 || $duration > self::MAX_PERIOD_PER_DAY) {
            return [];
        }

        $candidates = [];

        foreach (self::ALLOWED_DAYS as $day) {
            foreach (range(1, self::MAX_PERIOD_PER_DAY - $duration + 1) as $start) {
                $end = $start + $duration - 1;

                if (
                    $day === (int) $meeting->day_of_week
                    && $start === (int) $meeting->start_period
                    && $this->isAllowedMeeting($meeting)
                ) {
                    continue;
                }

                if (! $this->isSlotAvailable($meeting, $day, $start, $end)) {
                    continue;
                }

                $score = $this->scoreCandidate($meeting, $day, $start);

                $candidates[] = [
                    'day_of_week' => $day,
                    'start_period' => $start,
                    'end_period' => $end,
                    'room_id' => $meeting->room_id,
                    'room_name' => $meeting->room?->name ?? 'Chưa có địa điểm',
                    'score' => $score,
                    'label' => $this->dayLabel($day) . ", tiết {$start}-{$end}",
                ];
            }
        }

        usort($candidates, fn ($a, $b) => $a['score'] <=> $b['score']);

        return array_slice($candidates, 0, $limit);
    }

    public function adjustmentSuggestions(ScheduleConflict $conflict, int $limit = 4): array
    {
        $meeting = $conflict->meeting;

        return $meeting ? $this->adjustmentSuggestionsForMeeting($conflict, $meeting, $limit) : [];
    }

    public function adjustmentSuggestionsForMeeting(ScheduleConflict $conflict, SectionMeeting $meeting, int $limit = 4): array
    {
        $other = $this->otherConflictMeeting($conflict, $meeting);

        if (! $meeting || ! $meeting->start_period || ! $meeting->end_period) {
            return [];
        }

        $meeting->loadMissing(['section.subject', 'section.lecturers', 'room']);
        $other?->loadMissing(['section.lecturers', 'room']);

        $duration = (int) $meeting->end_period - (int) $meeting->start_period + 1;
        if ($duration <= 0 || $duration > self::MAX_PERIOD_PER_DAY) {
            return [];
        }

        $suggestions = collect()
            ->merge($this->changeDaySuggestions($meeting, $duration))
            ->merge($this->sameCampusRoomSuggestions($conflict, $meeting, $other, $duration))
            ->unique(fn (array $item) => implode(':', [
                $item['day_of_week'],
                $item['start_period'],
                $item['end_period'],
                $item['room_id'] ?? 0,
            ]))
            ->sortBy('score')
            ->values()
            ->take($limit)
            ->all();

        return array_values($suggestions);
    }

    private function changeDaySuggestions(SectionMeeting $meeting, int $duration): array
    {
        $roomIds = $this->candidateRoomIdsForMeeting($meeting);
        $suggestions = [];
        $starts = collect([(int) $meeting->start_period])
            ->merge(range(1, self::MAX_PERIOD_PER_DAY - $duration + 1))
            ->unique()
            ->values();

        foreach (self::ALLOWED_DAYS as $day) {
            if ($day === (int) $meeting->day_of_week) {
                continue;
            }

            foreach ($starts as $start) {
                $end = $start + $duration - 1;

                foreach ($roomIds as $roomId) {
                    if (! $this->isSlotAvailable($meeting, $day, $start, $end, $roomId)) {
                        continue;
                    }

                    $room = Room::query()->find($roomId);
                    $samePeriodBonus = $start === (int) $meeting->start_period ? 0 : 12;

                    $suggestions[] = [
                        'kind' => 'change_day',
                        'title' => 'Chuyển sang ngày khác',
                        'label' => $this->dayLabel($day) . ", tiết {$start}-{$end}",
                        'hint' => $room ? "Phòng {$room->name}" : 'Giữ phòng hiện tại',
                        'day_of_week' => $day,
                        'start_period' => $start,
                        'end_period' => $end,
                        'room_id' => $roomId,
                        'room_name' => $room?->name,
                        'score' => $this->scoreCandidate($meeting, $day, $start) + $samePeriodBonus,
                    ];

                    if (count($suggestions) >= 8) {
                        return $suggestions;
                    }
                }
            }
        }

        return $suggestions;
    }

    private function sameCampusRoomSuggestions(ScheduleConflict $conflict, SectionMeeting $meeting, ?SectionMeeting $other, int $duration): array
    {
        $day = (int) $meeting->day_of_week;
        $start = (int) $meeting->start_period;
        $end = $start + $duration - 1;
        $targetCampus = $this->targetCampusForRoomSuggestion($conflict, $meeting, $other);

        if (! $targetCampus) {
            return [];
        }

        $preferredRoomIds = $this->preferredRoomIdsForDay($meeting, $targetCampus);
        $rooms = Room::query()
            ->whereIn('type', ['room', 'lab'])
            ->where('campus', $targetCampus)
            ->orderBy('name')
            ->get()
            ->filter(fn (Room $room) => $this->isRoomAllowedForMeeting($meeting, $room))
            ->sort(function (Room $a, Room $b) use ($preferredRoomIds) {
                $aPreferred = array_search($a->id, $preferredRoomIds, true);
                $bPreferred = array_search($b->id, $preferredRoomIds, true);

                return [
                    $aPreferred === false ? 1 : 0,
                    $aPreferred === false ? 999 : $aPreferred,
                    $a->name,
                ] <=> [
                    $bPreferred === false ? 1 : 0,
                    $bPreferred === false ? 999 : $bPreferred,
                    $b->name,
                ];
            })
            ->values();

        $suggestions = [];

        foreach ($rooms as $room) {
            if ((int) $room->id === (int) $meeting->room_id) {
                continue;
            }

            if (! $this->isSlotAvailable($meeting, $day, $start, $end, $room->id)) {
                continue;
            }

            $isPreferred = in_array($room->id, $preferredRoomIds, true);
            $title = $conflict->type === 'lecturer_campus_conflict'
                ? 'Chuyển về cùng cơ sở'
                : 'Đổi phòng cùng cơ sở';

            $suggestions[] = [
                'kind' => 'same_campus_room',
                'title' => $title,
                'label' => "{$this->dayLabel($day)}, tiết {$start}-{$end}",
                'hint' => $isPreferred
                    ? "Ưu tiên phòng đã dùng trước đó: {$room->name}"
                    : "Phòng trống tại {$targetCampus}: {$room->name}",
                'day_of_week' => $day,
                'start_period' => $start,
                'end_period' => $end,
                'room_id' => $room->id,
                'room_name' => $room->name,
                'score' => $isPreferred ? 0 : 18,
            ];

            if (count($suggestions) >= 5) {
                break;
            }
        }

        return $suggestions;
    }

    public function applySuggestion(ScheduleConflict $conflict, array $data): bool
    {
        $meeting = $conflict->meeting;

        if (! $meeting) {
            return false;
        }

        $day = (int) ($data['day_of_week'] ?? 0);
        $start = (int) ($data['start_period'] ?? 0);
        $end = (int) ($data['end_period'] ?? 0);
        $roomId = isset($data['room_id']) && $data['room_id'] !== null && $data['room_id'] !== ''
            ? (int) $data['room_id']
            : $meeting->room_id;

        if (! in_array($day, self::ALLOWED_DAYS, true) || $start < 1 || $end < $start || $end > self::MAX_PERIOD_PER_DAY) {
            return false;
        }

        if (! $this->isSlotAvailable($meeting, $day, $start, $end, $roomId)) {
            return false;
        }

        $meeting->update([
            'day_of_week' => $day,
            'start_period' => $start,
            'end_period' => $end,
            'room_id' => $roomId,
        ]);

        $this->availabilityMeetings = null;
        $this->detect();

        return true;
    }

    public function applyMeetingSuggestion(SectionMeeting $meeting, array $data): bool
    {
        $day = (int) ($data['day_of_week'] ?? 0);
        $start = (int) ($data['start_period'] ?? 0);
        $end = (int) ($data['end_period'] ?? 0);
        $roomId = isset($data['room_id']) && $data['room_id'] !== null && $data['room_id'] !== ''
            ? (int) $data['room_id']
            : $meeting->room_id;

        if (! in_array($day, self::ALLOWED_DAYS, true) || $start < 1 || $end < $start || $end > self::MAX_PERIOD_PER_DAY) {
            return false;
        }

        if (! $this->isSlotAvailable($meeting, $day, $start, $end, $roomId)) {
            return false;
        }

        $meeting->update([
            'day_of_week' => $day,
            'start_period' => $start,
            'end_period' => $end,
            'room_id' => $roomId,
        ]);

        $this->availabilityMeetings = null;
        $this->detect();

        return true;
    }

    public function autoSchedule(int $maxSteps = 80): array
    {
        $maxSteps = min($maxSteps, 20);
        $applied = 0;
        $stuck = false;

        for ($step = 0; $step < $maxSteps; $step++) {
            $remaining = $this->detect();

            if ($remaining === 0) {
                return [
                    'applied' => $applied,
                    'remaining' => 0,
                    'stuck' => false,
                ];
            }

            $conflict = ScheduleConflict::query()
                ->with(['meeting.section.lecturers', 'meeting.room'])
                ->latest()
                ->limit(80)
                ->get()
                ->first(function (ScheduleConflict $item) {
                    return count($this->suggestions($item, 1)) > 0;
                });

            if (! $conflict) {
                $stuck = true;
                break;
            }

            $suggestion = $this->suggestions($conflict, 1)[0] ?? null;
            if (! $suggestion || ! $this->applySuggestion($conflict, $suggestion)) {
                $stuck = true;
                break;
            }

            $applied++;
        }

        return [
            'applied' => $applied,
            'remaining' => $this->detect(),
            'stuck' => $stuck,
        ];
    }

    public function isSlotAvailable(SectionMeeting $meeting, int $day, int $start, int $end, ?int $roomId = null): bool
    {
        $meeting->loadMissing(['section.lecturers', 'room']);
        $lecturerIds = $meeting->section?->lecturers?->pluck('id')->all() ?? [];
        $candidateRoomId = $roomId ?: $meeting->room_id;

        if (! $this->areLecturersAvailableAt($meeting->section?->lecturers ?? collect(), $day, $start, $end)) {
            return false;
        }

        if ($candidateRoomId && ! $this->isCandidateRoomAllowed($meeting, $candidateRoomId)) {
            return false;
        }

        if (! $this->respectsLecturerCampusDay($meeting, $day, $candidateRoomId)) {
            return false;
        }

        $overlappingMeetings = $this->availabilityMeetings()
            ->filter(function (SectionMeeting $other) use ($meeting, $day, $start, $end) {
                return $other->id !== $meeting->id
                    && (int) $other->day_of_week === $day
                    && (int) $other->start_period <= $end
                    && (int) $other->end_period >= $start;
            });

        foreach ($overlappingMeetings as $other) {
            if ($other->section_id === $meeting->section_id) {
                return false;
            }

            $otherLecturerIds = $other->section?->lecturers?->pluck('id')->all() ?? [];
            if ($lecturerIds && array_intersect($lecturerIds, $otherLecturerIds)) {
                return false;
            }

            if ($candidateRoomId && $this->isSameCandidateRoom($candidateRoomId, $other)) {
                return false;
            }
        }

        return true;
    }

    private function candidateRoomIdsForMeeting(SectionMeeting $meeting): array
    {
        $meeting->loadMissing('room');
        $campus = $meeting->room?->campus;
        $roomIds = collect([$meeting->room_id])->filter()->values();

        if ($campus) {
            $sameCampusRooms = Room::query()
                ->whereIn('type', ['room', 'lab'])
                ->where('campus', $campus)
                ->orderBy('name')
                ->get()
                ->filter(fn (Room $room) => $this->isRoomAllowedForMeeting($meeting, $room))
                ->pluck('id');

            $roomIds = $roomIds->merge($sameCampusRooms);
        }

        return $roomIds->unique()->values()->all();
    }

    private function targetCampusForRoomSuggestion(ScheduleConflict $conflict, SectionMeeting $meeting, ?SectionMeeting $other): ?string
    {
        if ($conflict->type === 'lecturer_campus_conflict' && $other?->room?->campus) {
            return $other->room->campus;
        }

        return $meeting?->room?->campus;
    }

    private function otherConflictMeeting(ScheduleConflict $conflict, SectionMeeting $meeting): ?SectionMeeting
    {
        $conflict->loadMissing(['meeting', 'conflictMeeting']);

        if ($conflict->meeting && (int) $conflict->meeting->id !== (int) $meeting->id) {
            return $conflict->meeting;
        }

        if ($conflict->conflictMeeting && (int) $conflict->conflictMeeting->id !== (int) $meeting->id) {
            return $conflict->conflictMeeting;
        }

        return null;
    }

    private function preferredRoomIdsForDay(SectionMeeting $meeting, string $targetCampus): array
    {
        $meeting->loadMissing('section.lecturers');
        $lecturerIds = $meeting->section?->lecturers?->pluck('id')->all() ?? [];

        if (! $lecturerIds) {
            return [];
        }

        return $this->availabilityMeetings()
            ->filter(function (SectionMeeting $other) use ($meeting, $lecturerIds, $targetCampus) {
                $otherLecturerIds = $other->section?->lecturers?->pluck('id')->all() ?? [];

                return $other->id !== $meeting->id
                    && (int) $other->day_of_week === (int) $meeting->day_of_week
                    && (int) $other->end_period < (int) $meeting->start_period
                    && $other->room
                    && $other->room->campus === $targetCampus
                    && array_intersect($lecturerIds, $otherLecturerIds);
            })
            ->sortByDesc('end_period')
            ->pluck('room_id')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function isCandidateRoomAllowed(SectionMeeting $meeting, int $roomId): bool
    {
        $room = Room::query()->find($roomId);

        return $room ? $this->isRoomAllowedForMeeting($meeting, $room) : false;
    }

    private function isRoomAllowedForMeeting(SectionMeeting $meeting, Room $room): bool
    {
        if (! in_array($room->type, ['room', 'lab'], true)) {
            return false;
        }

        $isPhysicalEducation = $this->isPhysicalEducationMeeting($meeting);
        $isSportRoom = $this->isSportRoomText($room->name, $room->type, $room->campus);

        return $isPhysicalEducation ? $isSportRoom : ! $isSportRoom;
    }

    private function respectsLecturerCampusDay(SectionMeeting $meeting, int $day, ?int $roomId): bool
    {
        if (! $roomId) {
            return true;
        }

        $room = Room::query()->find($roomId);
        $candidateCampus = trim((string) $room?->campus);

        if ($candidateCampus === '') {
            return true;
        }

        $meeting->loadMissing('section.lecturers');
        $lecturerIds = $meeting->section?->lecturers?->pluck('id')->all() ?? [];

        if (! $lecturerIds) {
            return true;
        }

        foreach ($this->availabilityMeetings() as $other) {
            if ($other->id === $meeting->id || (int) $other->day_of_week !== $day || ! $other->room?->campus) {
                continue;
            }

            $otherLecturerIds = $other->section?->lecturers?->pluck('id')->all() ?? [];
            if (! array_intersect($lecturerIds, $otherLecturerIds)) {
                continue;
            }

            if ($other->room->campus !== $candidateCampus) {
                return false;
            }
        }

        return true;
    }

    private function isSameCandidateRoom(int $candidateRoomId, SectionMeeting $other): bool
    {
        $other->loadMissing('room');

        if (! $other->room_id || ! $other->room || ! in_array($other->room->type, ['room', 'lab'], true)) {
            return false;
        }

        return (int) $other->room_id === $candidateRoomId;
    }

    private function lecturerAvailabilityMessages(SectionMeeting $meeting): array
    {
        $meeting->loadMissing('section.lecturers');
        $messages = [];

        foreach ($meeting->section?->lecturers ?? collect() as $lecturer) {
            if ($this->isLecturerAvailableAt($lecturer, (int) $meeting->day_of_week, (int) $meeting->start_period, (int) $meeting->end_period)) {
                continue;
            }

            $messages[] = "Giảng viên {$lecturer->name} bị xếp ngoài ngày/buổi có thể dạy.";
        }

        return $messages;
    }

    private function roomAssignmentMessage(SectionMeeting $meeting): ?string
    {
        $meeting->loadMissing(['section.subject', 'room']);

        if (! $meeting->room) {
            return null;
        }

        $isPhysicalEducation = $this->isPhysicalEducationMeeting($meeting);
        $isSportRoom = $this->isSportRoom($meeting);

        if ($isPhysicalEducation && ! $isSportRoom) {
            return 'Môn thể chất phải được xếp tại điểm học thể chất.';
        }

        if (! $isPhysicalEducation && $isSportRoom) {
            return 'Chỉ môn thể chất được xếp tại điểm học thể chất.';
        }

        return null;
    }

    private function isPhysicalEducationMeeting(SectionMeeting $meeting): bool
    {
        $text = $this->normalize(implode(' ', array_filter([
            $meeting->section?->section_code,
            $meeting->section?->teaching_mode,
            $meeting->section?->support_request,
            $meeting->section?->note,
            $meeting->section?->subject?->subject_code,
            $meeting->section?->subject?->name,
        ])));

        return str_contains($text, 'giao duc the chat')
            || str_contains($text, 'the chat')
            || str_contains($text, 'the thao')
            || str_contains($text, 'physical education')
            || str_contains($text, 'sport')
            || str_contains($text, 'gdtc')
            || preg_match('/\bpec\b/', $text) === 1;
    }

    private function isSportRoom(SectionMeeting $meeting): bool
    {
        return $this->isSportRoomText(
            $meeting->room?->name,
            $meeting->room?->type,
            $meeting->room?->campus
        );
    }

    private function isSportRoomText(?string $name, ?string $type, ?string $campus): bool
    {
        $text = $this->normalize(implode(' ', array_filter([
            $name,
            $type,
            $campus,
        ])));

        return str_contains($text, 'san')
            || str_contains($text, 'svd')
            || str_contains($text, 'the chat')
            || str_contains($text, 'the thao')
            || str_contains($text, 'stadium')
            || str_contains($text, 'gym');
    }

    private function areLecturersAvailableAt(Collection $lecturers, int $day, int $start, int $end): bool
    {
        foreach ($lecturers as $lecturer) {
            if (! $this->isLecturerAvailableAt($lecturer, $day, $start, $end)) {
                return false;
            }
        }

        return true;
    }

    private function isLecturerAvailableAt($lecturer, int $day, int $start, int $end): bool
    {
        if (($lecturer->availability_mode ?? 'unrestricted') !== 'limited') {
            return true;
        }

        $session = $this->sessionForRange($start, $end);

        if (! $session) {
            return false;
        }

        return in_array("{$day}_{$session}", $lecturer->available_slots ?? [], true);
    }

    private function sessionForRange(int $start, int $end): ?string
    {
        if ($start >= 1 && $end <= 6) {
            return 'morning';
        }

        if ($start >= 7 && $end <= self::MAX_PERIOD_PER_DAY) {
            return 'afternoon';
        }

        return null;
    }

    private function availabilityMeetings(): Collection
    {
        if ($this->availabilityMeetings === null) {
            $this->availabilityMeetings = SectionMeeting::query()
                ->with(['section.lecturers', 'room'])
                ->whereHas('section.lecturers')
                ->whereNotNull('day_of_week')
                ->whereNotNull('start_period')
                ->whereNotNull('end_period')
                ->get()
                ->filter(fn (SectionMeeting $meeting) => $this->isAllowedMeeting($meeting))
                ->values();
        }

        return $this->availabilityMeetings;
    }

    private function isAllowedMeeting(SectionMeeting $meeting): bool
    {
        return in_array((int) $meeting->day_of_week, self::ALLOWED_DAYS, true)
            && (int) $meeting->start_period >= 1
            && (int) $meeting->end_period >= (int) $meeting->start_period
            && (int) $meeting->end_period <= self::MAX_PERIOD_PER_DAY;
    }

    private function scoreCandidate(SectionMeeting $meeting, int $day, int $start): int
    {
        $currentDay = in_array((int) $meeting->day_of_week, self::ALLOWED_DAYS, true)
            ? (int) $meeting->day_of_week
            : 6;

        $score = abs($day - $currentDay) * 12;
        $score += abs($start - (int) $meeting->start_period);

        if ($day === 7) {
            $score += 8;
        }

        if ($start === 1 || $start >= 10) {
            $score += 3;
        }

        return $score;
    }

    private function dayLabel(int $day): string
    {
        return $day === 7 ? 'Thứ 7' : "Thứ {$day}";
    }

    private function isSameTime(SectionMeeting $a, SectionMeeting $b): bool
    {
        if ($a->day_of_week !== $b->day_of_week) {
            return false;
        }

        return $a->start_period <= $b->end_period
            && $b->start_period <= $a->end_period;
    }

    private function isSameSection(SectionMeeting $a, SectionMeeting $b): bool
    {
        return $a->section_id === $b->section_id;
    }

    private function hasSameLecturer(SectionMeeting $a, SectionMeeting $b): bool
    {
        $lecturerIdsA = $a->section->lecturers->pluck('id')->toArray();
        $lecturerIdsB = $b->section->lecturers->pluck('id')->toArray();

        return count(array_intersect($lecturerIdsA, $lecturerIdsB)) > 0;
    }

    private function isSameCheckableRoom(SectionMeeting $a, SectionMeeting $b): bool
    {
        if (! $this->hasCheckableRoom($a) || ! $this->hasCheckableRoom($b)) {
            return false;
        }

        return (int) $a->room_id === (int) $b->room_id;
    }

    private function hasCheckableRoom(SectionMeeting $meeting): bool
    {
        $meeting->loadMissing(['section', 'room']);

        if (! $meeting->room_id || ! $meeting->room || $this->isOnlineMeeting($meeting)) {
            return false;
        }

        return in_array($meeting->room->type, ['room', 'lab'], true);
    }

    private function campusForMeeting(SectionMeeting $meeting): ?string
    {
        if (! $this->hasCheckableRoom($meeting)) {
            return null;
        }

        $campus = trim((string) $meeting->room?->campus);

        return $campus !== '' ? $campus : null;
    }

    private function isOnlineMeeting(SectionMeeting $meeting): bool
    {
        $meeting->loadMissing(['section', 'room']);

        $text = mb_strtolower(trim(implode(' ', array_filter([
            $meeting->section?->teaching_mode,
            $meeting->room?->name,
            $meeting->room?->type,
        ]))), 'UTF-8');

        return str_contains($text, 'online')
            || str_contains($text, 'zoom')
            || str_contains($text, 'lms')
            || str_contains($text, 'trực tuyến');
    }

    private function createConflict(
        string $type,
        SectionMeeting $a,
        ?SectionMeeting $b,
        string $message
    ): int {
        ScheduleConflict::create([
            'type' => $type,
            'section_meeting_id' => $a->id,
            'conflict_section_meeting_id' => $b?->id,
            'message' => $message,
        ]);

        return 1;
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
