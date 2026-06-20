<?php

namespace App\Services;

use App\Models\ScheduleConflict;
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

    public function applySuggestion(ScheduleConflict $conflict, array $data): bool
    {
        $meeting = $conflict->meeting;

        if (! $meeting) {
            return false;
        }

        $day = (int) ($data['day_of_week'] ?? 0);
        $start = (int) ($data['start_period'] ?? 0);
        $end = (int) ($data['end_period'] ?? 0);

        if (! in_array($day, self::ALLOWED_DAYS, true) || $start < 1 || $end < $start || $end > self::MAX_PERIOD_PER_DAY) {
            return false;
        }

        if (! $this->isSlotAvailable($meeting, $day, $start, $end)) {
            return false;
        }

        $meeting->update([
            'day_of_week' => $day,
            'start_period' => $start,
            'end_period' => $end,
        ]);

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

    public function isSlotAvailable(SectionMeeting $meeting, int $day, int $start, int $end): bool
    {
        $meeting->loadMissing('section.lecturers');
        $lecturerIds = $meeting->section?->lecturers?->pluck('id')->all() ?? [];

        if (! $this->areLecturersAvailableAt($meeting->section?->lecturers ?? collect(), $day, $start, $end)) {
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

            if ($this->isSameCheckableRoom($meeting, $other)) {
                return false;
            }
        }

        return true;
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
        $text = $this->normalize(implode(' ', array_filter([
            $meeting->room?->name,
            $meeting->room?->type,
            $meeting->room?->campus,
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
