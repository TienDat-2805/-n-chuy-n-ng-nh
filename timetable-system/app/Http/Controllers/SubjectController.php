<?php

namespace App\Http\Controllers;

use App\Models\ScheduleConflict;
use App\Models\Subject;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index(Request $request)
    {
        $keyword = $request->query('keyword');
        $conflicts = ScheduleConflict::query()
            ->with(['meeting.section', 'conflictMeeting.section'])
            ->latest()
            ->limit(500)
            ->get();

        $conflictSectionIds = $conflicts
            ->flatMap(fn ($conflict) => [
                $conflict->meeting?->section_id,
                $conflict->conflictMeeting?->section_id,
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $conflictMessagesBySection = [];
        foreach ($conflicts as $conflict) {
            foreach ([$conflict->meeting?->section_id, $conflict->conflictMeeting?->section_id] as $sectionId) {
                if (! $sectionId) {
                    continue;
                }

                $conflictMessagesBySection[$sectionId] ??= [];
                if (count($conflictMessagesBySection[$sectionId]) < 2) {
                    $conflictMessagesBySection[$sectionId][] = $conflict->message;
                }
            }
        }

        $subjects = Subject::query()
            ->withCount('sections')
            ->with(['sections' => function ($query) {
                $query->with(['lecturers', 'meetings.room'])
                    ->orderBy('section_code');
            }])
            ->when($keyword, function ($query) use ($keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('subject_code', 'like', "%{$keyword}%")
                        ->orWhere('name', 'like', "%{$keyword}%")
                        ->orWhereHas('sections', function ($sectionQuery) use ($keyword) {
                            $sectionQuery->where('section_code', 'like', "%{$keyword}%");
                        });
                });
            })
            ->orderBy('subject_code')
            ->paginate(15)
            ->withQueryString();

        return view('subjects.index', [
            'subjects' => $subjects,
            'keyword' => $keyword,
            'conflictSectionIds' => $conflictSectionIds,
            'conflictMessagesBySection' => $conflictMessagesBySection,
        ]);
    }
}
