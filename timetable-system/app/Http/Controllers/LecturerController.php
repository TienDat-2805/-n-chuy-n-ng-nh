<?php

namespace App\Http\Controllers;

use App\Models\Lecturer;
use App\Models\SectionInstructor;
use App\Models\SectionMeeting;
use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        'morning' => 'Sáng',
        'afternoon' => 'Chiều',
    ];

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('keyword', ''));

        $lecturers = Lecturer::query()
            ->withCount(['sections', 'meetings'])
            ->with([
                'sections' => function ($query) {
                    $query
                        ->with(['subject', 'meetings.room'])
                        ->orderBy('section_code');
                },
                'meetings' => function ($query) {
                    $query
                        ->with(['section.subject', 'room'])
                        ->orderBy('day_of_week')
                        ->orderBy('start_period');
                },
            ])
            ->when($keyword !== '', function ($query) use ($keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query
                        ->where('name', 'like', "%{$keyword}%")
                        ->orWhere('email', 'like', "%{$keyword}%")
                        ->orWhereHas('sections.subject', function ($subjectQuery) use ($keyword) {
                            $subjectQuery
                                ->where('subject_code', 'like', "%{$keyword}%")
                                ->orWhere('name', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('sections', function ($sectionQuery) use ($keyword) {
                            $sectionQuery->where('section_code', 'like', "%{$keyword}%");
                        });
                });
            })
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return view('lecturers.index', [
            'lecturers' => $lecturers,
            'keyword' => $keyword,
            'days' => self::DAYS,
            'sessions' => self::SESSIONS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $availability = $this->availabilityPayload($data);
        $name = $this->cleanName($data['name']);
        $email = $this->cleanEmail($data['email'] ?? null);

        $lecturer = Lecturer::query()
            ->where('name', $name)
            ->when($email, fn ($query) => $query->orWhere('email', $email))
            ->first();

        if ($lecturer) {
            $lecturer->update([
                'email' => $email ?: $lecturer->email,
                'phone' => $data['phone'] ?? $lecturer->phone,
                'availability_mode' => $availability['availability_mode'],
                'available_slots' => $availability['available_slots'],
            ]);
        } else {
            Lecturer::create([
                'name' => $name,
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'availability_mode' => $availability['availability_mode'],
                'available_slots' => $availability['available_slots'],
            ]);
        }

        return redirect()
            ->route('lecturers.index')
            ->with('success', $lecturer ? 'Đã cập nhật giảng viên đã có trong danh sách.' : 'Đã thêm giảng viên.');
    }

    public function update(Request $request, Lecturer $lecturer)
    {
        $data = $this->validatedData($request, $lecturer);
        $availability = $this->availabilityPayload($data);

        $lecturer->update([
            'name' => $this->cleanName($data['name']),
            'email' => $this->cleanEmail($data['email'] ?? null),
            'phone' => $data['phone'] ?? null,
            'availability_mode' => $availability['availability_mode'],
            'available_slots' => $availability['available_slots'],
        ]);

        return redirect()
            ->route('lecturers.index', $request->only('keyword', 'page'))
            ->with('success', 'Đã cập nhật giảng viên.');
    }

    public function destroy(Lecturer $lecturer)
    {
        DB::transaction(function () use ($lecturer) {
            SectionInstructor::query()
                ->where('lecturer_id', $lecturer->id)
                ->delete();

            SectionMeeting::query()
                ->where('lecturer_id', $lecturer->id)
                ->update(['lecturer_id' => null]);

            $lecturer->delete();
        });

        return redirect()
            ->route('lecturers.index')
            ->with('success', 'Đã xóa giảng viên.');
    }

    public function detachSubject(Request $request, Lecturer $lecturer, Subject $subject)
    {
        $sectionIds = $subject->sections()->pluck('id');

        DB::transaction(function () use ($lecturer, $sectionIds) {
            SectionInstructor::query()
                ->where('lecturer_id', $lecturer->id)
                ->whereIn('section_id', $sectionIds)
                ->delete();

            SectionMeeting::query()
                ->where('lecturer_id', $lecturer->id)
                ->whereIn('section_id', $sectionIds)
                ->update(['lecturer_id' => null]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Đã hủy đăng ký môn học.',
            ]);
        }

        return redirect()
            ->route('lecturers.index', $request->only('keyword', 'page'))
            ->with('success', 'Đã hủy đăng ký môn học.');
    }

    private function validatedData(Request $request, ?Lecturer $lecturer = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('lecturers', 'email')->ignore($lecturer?->id)->whereNotNull('email'),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'availability_mode' => ['nullable', 'in:unrestricted,limited'],
            'available_slots' => ['nullable', 'array'],
            'available_slots.*' => ['string'],
        ]);
    }

    private function availabilityPayload(array $data): array
    {
        $mode = $data['availability_mode'] ?? 'unrestricted';
        $slots = $mode === 'limited' ? $this->normalizeSlots($data['available_slots'] ?? []) : [];

        return [
            'availability_mode' => $mode,
            'available_slots' => $slots,
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

    private function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }

    private function cleanEmail(?string $email): ?string
    {
        return filled($email) ? mb_strtolower(trim((string) $email), 'UTF-8') : null;
    }
}
