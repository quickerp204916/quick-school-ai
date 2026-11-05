<?php

namespace Modules\Academic;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Academic\Models\Guardian;
use Modules\Academic\Models\ReportCard;
use Modules\Academic\Models\SectionSubject;
use Modules\Academic\Models\StaffMember;
use Modules\Academic\Models\TimetableEntry;
use Modules\Academic\Support\CurrentMember;
use Modules\Academic\Support\Institution;
use Modules\Identity\Models\User;
use Illuminate\Support\Facades\Route;

class AcademicServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentMember::class);
        $this->mergeConfigFrom(base_path('config/academic.php'), 'academic');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('modules/Academic/resources/views'), 'academic');

        // academic.<permission> gates, checked against the user's role in the current institution.
        foreach (array_keys(config('academic.permissions')) as $permission) {
            Gate::define("academic.{$permission}", fn ($user) => $user instanceof User && app(CurrentMember::class)->can($permission));
        }

        // Route parameter names that differ from the model names.
        Route::model('staff', StaffMember::class);
        Route::model('row', SectionSubject::class);
        Route::model('entry', TimetableEntry::class);
        Route::model('card', ReportCard::class);
        Route::model('scheme', \Modules\Academic\Models\GradingScheme::class);
        Route::model('template', \Modules\Academic\Models\CertificateTemplate::class);
        Route::model('level', \Modules\Academic\Models\GradeLevel::class);
        Route::model('period', \Modules\Academic\Models\BellPeriod::class);
        Route::model('term', \Modules\Academic\Models\AcademicTerm::class);
        Route::model('session', \Modules\Institution\Models\AcademicSession::class);
        Route::model('admission', \Modules\Academic\Models\AdmissionApplication::class);
        Route::model('paper', \Modules\Academic\Models\ExamPaper::class);
        Route::model('guardian', Guardian::class);
        Route::model('document', \Modules\Academic\Models\StudentDocument::class);

        RateLimiter::for('tenant-login', fn (Request $r) => Limit::perMinute(10)->by(strtolower((string) $r->input('email')).'|'.$r->ip()));
        RateLimiter::for('tenant-api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->getAuthIdentifier() ?? $r->ip()));

        view()->composer('academic::*', function ($view) {
            if (\App\Core\Tenancy\TenantContext::check()) {
                $view->with('t', fn (string $key) => Institution::term($key));
            }
        });
    }
}
