<?php

namespace App\Services;

use App\Models\Room;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class DefaultRoomService
{
    private const INPUT_FILES = [
        '../input_mau/lich-toan-truong-14-05-2026-205805_EF0011_LichToanTruong.xls',
        '../input_mau/lich-toan-truong-14-05-2026-205950_EF0011_LichToanTruong.xls',
    ];

    public function seed(): int
    {
        $createdOrFound = 0;
        $canonicalNames = $this->roomNames();

        foreach ($canonicalNames as $roomName) {
            $type = $this->guessType($roomName);
            $campus = $this->guessCampus($roomName);

            $room = Room::firstOrNew(['name' => $roomName]);
            $room->fill([
                'type' => $type,
                'campus' => $campus,
            ]);
            $room->save();

            $createdOrFound++;
        }

        $this->cleanupOldSampleRooms($canonicalNames);

        return $createdOrFound;
    }

    public function roomNames(): array
    {
        $rooms = [];

        foreach (self::INPUT_FILES as $relativePath) {
            $path = base_path($relativePath);

            if (! is_file($path)) {
                continue;
            }

            try {
                $spreadsheet = IOFactory::load($path);
                $sheet = $spreadsheet->getSheetByName('DanhSachLichToanTruong')
                    ?? $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);

                foreach ($rows as $index => $row) {
                    if ($index < 11) {
                        continue;
                    }

                    foreach ($this->splitRoomNames($row['K'] ?? '') as $roomName) {
                        if ($roomName !== '' && $this->guessType($roomName) !== 'online') {
                            $rooms[$roomName] = true;
                        }
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        $names = array_keys($rooms);
        natcasesort($names);

        return array_values($names);
    }

    private function splitRoomNames($value): array
    {
        $value = $this->cleanText($value);

        if ($value === '') {
            return [];
        }

        $parts = preg_split('/,\s*(?=(?:Phòng|Phong|P\d{2,}|Sân|SVĐ)\b)/u', $value) ?: [];

        return array_values(array_filter(array_map(
            fn ($part) => $this->normalizeRoomName($part),
            $parts
        )));
    }

    private function normalizeRoomName(string $roomName): string
    {
        $roomName = $this->cleanText($roomName);
        $roomName = preg_replace('/\b[Pp]hong thuc hanh\b/u', 'Phòng thực hành', $roomName);
        $roomName = preg_replace('/\b[Pp]hong\b/u', 'Phòng', $roomName);
        $roomName = preg_replace('/\s+/', ' ', $roomName);

        return trim((string) $roomName);
    }

    private function cleanupOldSampleRooms(array $canonicalNames): void
    {
        $canonicalLookup = array_flip($canonicalNames);

        Room::query()
            ->whereIn('type', ['room', 'lab'])
            ->whereDoesntHave('meetings')
            ->get()
            ->each(function (Room $room) use ($canonicalLookup) {
                $normalizedName = $this->normalizeRoomName($room->name);
                $isCombinedRoom = preg_match('/,\s*(?:Phòng|Phong|P\d{2,}|Sân|SVĐ)\b/u', $room->name) === 1;

                if ($isCombinedRoom) {
                    $room->delete();
                    return;
                }

                if ($normalizedName !== $room->name && isset($canonicalLookup[$normalizedName])) {
                    if (Room::query()->where('name', $normalizedName)->whereKeyNot($room->id)->exists()) {
                        $room->delete();
                        return;
                    }

                    $room->update([
                        'name' => $normalizedName,
                        'campus' => $this->guessCampus($normalizedName) ?: $room->campus,
                    ]);
                }
            });
    }

    private function cleanText($value): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[ \t]+/', ' ', $value);

        return trim((string) $value);
    }

    private function guessType(string $roomName): string
    {
        $normalized = $this->normalize($roomName);

        if (
            str_contains($normalized, 'online')
            || str_contains($normalized, 'zoom')
            || str_contains($normalized, 'lms')
            || str_contains($normalized, 'teams')
            || str_contains($normalized, 'elearning')
            || str_contains($normalized, 'truc tuyen')
        ) {
            return 'online';
        }

        if (str_contains($normalized, 'lab') || str_contains($normalized, 'thuc hanh')) {
            return 'lab';
        }

        return 'room';
    }

    private function guessCampus(string $roomName): ?string
    {
        $lower = mb_strtolower($roomName, 'UTF-8');
        $normalized = $this->normalize($roomName);

        if (
            str_contains($lower, 'hòa lạc')
            || str_contains($normalized, 'hoa lac')
            || str_contains($normalized, 'ht1')
        ) {
            return 'Hòa Lạc';
        }

        if (
            str_contains($lower, 'trịnh văn bô')
            || str_contains($normalized, 'trinh van bo')
            || str_contains($normalized, 'tvb')
            || str_contains($normalized, 'phan tay nhac')
        ) {
            return 'Trịnh Văn Bô';
        }

        if (
            str_contains($lower, 'xuân thủy')
            || str_contains($normalized, 'xuan thuy')
            || str_contains($normalized, 'dai hoc ngoai ngu')
            || str_contains($normalized, 'dhnn')
            || str_contains($normalized, 'svd')
            || preg_match('/\bg7\b/i', $roomName)
        ) {
            return '144 Xuân Thủy';
        }

        return null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower($this->cleanText($value), 'UTF-8');
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

        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
