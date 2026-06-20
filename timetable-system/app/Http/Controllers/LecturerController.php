<?php

namespace App\Http\Controllers;

use App\Models\Lecturer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LecturerController extends Controller
{
    private const DAYS = [
        2 => 'Thứ 2',
        3 => 'Thứ 3',
        4 => 'Thứ 4',
        5 => 'Thứ 5',
        6 => 'Thứ 6',
        7 => 'Thứ 7',
    ];

    private const SESSIONS = [
        'morning' => 'Sáng (tiết 1-6)',
        'afternoon' => 'Chiều (tiết 7-12)',
    ];

    public function index()
    {
        return view('lecturers.index', [
            'lecturers' => Lecturer::query()
                ->withCount('sections')
                ->orderBy('name')
                ->get(),
            'days' => self::DAYS,
            'sessions' => self::SESSIONS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'availability_mode' => ['nullable', 'in:unrestricted,limited'],
            'available_slots' => ['nullable', 'array'],
            'available_slots.*' => ['string'],
        ]);

        $availability = $this->availabilityPayload($data);

        Lecturer::firstOrCreate(
            [
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
            ],
            [
                'department' => $data['department'] ?? null,
                'phone' => $data['phone'] ?? null,
                'availability_mode' => $availability['availability_mode'],
                'available_slots' => $availability['available_slots'],
            ]
        );

        return redirect()
            ->route('lecturers.index')
            ->with('success', 'Đã thêm giảng viên.');
    }

    public function update(Request $request, Lecturer $lecturer)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('lecturers', 'email')->ignore($lecturer->id)->whereNotNull('email'),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'availability_mode' => ['nullable', 'in:unrestricted,limited'],
            'available_slots' => ['nullable', 'array'],
            'available_slots.*' => ['string'],
        ]);

        $lecturer->update(array_merge($data, $this->availabilityPayload($data)));

        return redirect()
            ->route('lecturers.index')
            ->with('success', 'Đã cập nhật giảng viên.');
    }

    public function destroy(Lecturer $lecturer)
    {
        if ($lecturer->sectionInstructors()->exists()) {
            return redirect()
                ->route('lecturers.index')
                ->with('error', 'Không thể xóa giảng viên đang được gắn với môn học.');
        }

        $lecturer->delete();

        return redirect()
            ->route('lecturers.index')
            ->with('success', 'Đã xóa giảng viên.');
    }

    private function availabilityPayload(array $data): array
    {
        $slots = $this->normalizeSlots($data['available_slots'] ?? []);
        $mode = $data['availability_mode'] ?? (empty($slots) ? 'unrestricted' : 'limited');

        return [
            'availability_mode' => $mode,
            'available_slots' => $mode === 'limited' ? $slots : [],
        ];
    }

    private function normalizeSlots(array $slots): array
    {
        $valid = [];

        foreach (self::DAYS as $day => $dayLabel) {
            foreach (array_keys(self::SESSIONS) as $session) {
                $valid[] = "{$day}_{$session}";
            }
        }

        $validLookup = array_flip($valid);

        return array_values(array_filter(array_unique($slots), fn ($slot) => isset($validLookup[$slot])));
    }
}
