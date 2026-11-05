<?php

namespace Modules\Academic\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Modules\Academic\Support\CurrentMember;
use Modules\Institution\Models\AcademicSession;

abstract class Controller
{
    protected function member(): CurrentMember
    {
        return app(CurrentMember::class);
    }

    protected function done(string $message, ?string $to = null): RedirectResponse
    {
        return ($to ? redirect($to) : back())->with('status', $message);
    }

    /** Session chosen in the filter, or the current one. */
    protected function session(?string $id = null): AcademicSession
    {
        $id ??= request('session');

        return ($id ? AcademicSession::find($id) : null) ?? AcademicSession::current() ?? AcademicSession::orderByDesc('starts_on')->firstOrFail();
    }
}
