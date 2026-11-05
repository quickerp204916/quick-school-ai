<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Modules\Platform\Exceptions\PlatformRuleException;
use Modules\Platform\Models\PlatformAdmin;

abstract class Controller
{
    protected function admin(): PlatformAdmin
    {
        return auth('platform')->user();
    }

    /** Run an action; on a business-rule failure go back with the message. */
    protected function attempt(callable $action, string $success, ?string $redirectTo = null): RedirectResponse
    {
        try {
            $result = $action();
        } catch (PlatformRuleException $e) {
            return back()->withInput()->withErrors(['action' => $e->getMessage()]);
        }

        $redirect = $redirectTo ? redirect($redirectTo) : (is_string($result) ? redirect($result) : back());

        return $redirect->with('status', $success);
    }
}
