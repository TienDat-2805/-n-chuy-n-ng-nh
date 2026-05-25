<?php

namespace App\Http\Controllers;

use App\Models\Section;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function index(Request $request)
    {
        $keyword = $request->query('keyword');
        $programId = $request->query('program_id');
        $cohortId = $request->query('cohort_id');

        $sections = Section::query()
            ->with(['subject', 'program', 'cohort', 'lecturers', 'meetings.room'])
            ->when($keyword, function ($query) use ($keyword) {
                $query->where(function ($q) use ($keyword) {
                    $q->where('section_code', 'like', "%{$keyword}%")
                        ->orWhereHas('subject', function ($subjectQuery) use ($keyword) {
                            $subjectQuery->where('subject_code', 'like', "%{$keyword}%")
                                ->orWhere('name', 'like', "%{$keyword}%");
                        })
                        ->orWhereHas('lecturers', function ($lecturerQuery) use ($keyword) {
                            $lecturerQuery->where('name', 'like', "%{$keyword}%");
                        });
                });
            })
            ->when($programId, function ($query) use ($programId) {
                $query->where('program_id', $programId);
            })
            ->when($cohortId, function ($query) use ($cohortId) {
                $query->where('cohort_id', $cohortId);
            })
            ->orderBy('section_code')
            ->paginate(20)
            ->withQueryString();

        return view('sections.index', [
            'sections' => $sections,
            'keyword' => $keyword,
        ]);
    }

    public function show(Section $section)
    {
        $section->load([
            'subject',
            'program',
            'cohort',
            'lecturers',
            'instructors.lecturer',
            'meetings.room',
        ]);

        return view('sections.show', [
            'section' => $section,
        ]);
    }
}