<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    private const TYPES = [
        'room' => 'Phòng học',
        'lab' => 'Phòng lab',
    ];

    private const CAMPUSES = [
        'Hòa Lạc' => 'Hòa Lạc',
        'Trịnh Văn Bô' => 'Trịnh Văn Bô',
        '144 Xuân Thủy' => '144 Xuân Thủy',
    ];

    public function index(Request $request)
    {
        $keyword = $request->query('keyword');
        $usage = $request->query('usage', 'all');
        $selectedCampus = $request->query('campus', array_key_first(self::CAMPUSES));

        if (! isset(self::CAMPUSES[$selectedCampus])) {
            $selectedCampus = array_key_first(self::CAMPUSES);
        }

        $baseQuery = Room::query()->whereIn('type', array_keys(self::TYPES));
        $campusBaseQuery = (clone $baseQuery)->where('campus', $selectedCampus);

        $rooms = (clone $campusBaseQuery)
            ->with([
                'meetings' => function ($query) {
                    $query
                        ->with(['section.subject', 'section.lecturers'])
                        ->orderBy('day_of_week')
                        ->orderBy('start_period');
                },
            ])
            ->withCount('meetings')
            ->when($keyword, fn ($query) => $query->where('name', 'like', "%{$keyword}%"))
            ->when($usage === 'used', fn ($query) => $query->has('meetings'))
            ->when($usage === 'unused', fn ($query) => $query->doesntHave('meetings'))
            ->orderBy('name')
            ->get();

        return view('rooms.index', [
            'rooms' => $rooms,
            'campuses' => self::CAMPUSES,
            'keyword' => $keyword,
            'selectedUsage' => $usage,
            'selectedCampus' => $selectedCampus,
            'selectedCampusLabel' => self::CAMPUSES[$selectedCampus],
            'campusOptions' => $this->campusOptions($baseQuery),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:rooms,name'],
            'campus' => ['required', Rule::in(array_keys(self::CAMPUSES))],
        ]);

        Room::create([
            'name' => $data['name'],
            'type' => 'room',
            'campus' => $data['campus'],
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Đã thêm phòng học.',
                'campus' => $data['campus'],
            ]);
        }

        return redirect()
            ->route('rooms.index', ['campus' => $data['campus']])
            ->with('success', 'Đã thêm phòng học.');
    }

    public function update(Request $request, Room $room)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('rooms', 'name')->ignore($room->id)],
            'campus' => ['required', Rule::in(array_keys(self::CAMPUSES))],
        ]);

        $room->update($data);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Đã cập nhật phòng học.',
                'campus' => $data['campus'],
            ]);
        }

        return redirect()
            ->route('rooms.index', ['campus' => $data['campus']])
            ->with('success', 'Đã cập nhật phòng học.');
    }

    public function destroy(Request $request, Room $room)
    {
        if ($room->meetings()->exists()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Không thể xóa phòng đang có lịch học.',
                ], 422);
            }

            return redirect()
                ->route('rooms.index', ['campus' => $room->campus])
                ->with('error', 'Không thể xóa phòng đang có lịch học.');
        }

        $campus = $room->campus;
        $room->delete();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'Đã xóa phòng học.',
                'campus' => $campus,
            ]);
        }

        return redirect()
            ->route('rooms.index', ['campus' => $campus])
            ->with('success', 'Đã xóa phòng học.');
    }

    private function campusOptions($baseQuery)
    {
        return collect(self::CAMPUSES)
            ->map(function (string $label, string $value) use ($baseQuery) {
                return [
                    'value' => $value,
                    'label' => $label,
                    'count' => (clone $baseQuery)->where('campus', $value)->count(),
                ];
            })
            ->values();
    }
}
