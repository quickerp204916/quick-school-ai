<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Platform\Models\Announcement;
use Modules\Platform\Models\Plan;
use Modules\Platform\Services\PlatformAudit;

class AnnouncementController extends Controller
{
    public function index(): View
    {
        return view('platform::announcements.index', ['announcements' => Announcement::with('plan')->latest('starts_at')->paginate(25)]);
    }

    public function create(): View
    {
        return view('platform::announcements.form', ['announcement' => new Announcement(['level' => 'info', 'audience' => 'all', 'starts_at' => now()]), 'plans' => Plan::orderBy('sort_order')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = Announcement::create($this->validated($request) + ['created_by' => $this->admin()->id]);
        PlatformAudit::record('announcement.created', $announcement, null, ['title' => $announcement->title]);

        return redirect()->route('platform.announcements.index')->with('status', 'Announcement saved.');
    }

    public function edit(Announcement $announcement): View
    {
        return view('platform::announcements.form', ['announcement' => $announcement, 'plans' => Plan::orderBy('sort_order')->get()]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($this->validated($request));
        PlatformAudit::record('announcement.updated', $announcement, null, ['title' => $announcement->title]);

        return redirect()->route('platform.announcements.index')->with('status', 'Announcement updated.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        PlatformAudit::record('announcement.deleted', $announcement, null, ['title' => $announcement->title]);
        $announcement->delete();

        return redirect()->route('platform.announcements.index')->with('status', 'Announcement deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:2000'],
            'level' => ['required', Rule::in(Announcement::LEVELS)],
            'audience' => ['required', Rule::in(['all', 'plan'])],
            'plan_id' => ['nullable', 'required_if:audience,plan', 'exists:plans,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);
        if ($data['audience'] === 'all') {
            $data['plan_id'] = null;
        }

        return $data;
    }
}
