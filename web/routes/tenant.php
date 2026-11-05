<?php

use Illuminate\Support\Facades\Route;
use Modules\Academic\Http\Controllers as C;

/*
| Institution app: https://{code}.quickschool.app
| Every route runs inside the institution's tenant context (middleware "tenant").
*/
Route::middleware('guest:web')->group(function () {
    Route::get('login', [C\AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [C\AuthController::class, 'login'])->middleware('throttle:tenant-login')->name('login.attempt');
});
Route::get('set-password', [C\AuthController::class, 'showSetPassword'])->name('password.set');
Route::post('set-password', [C\AuthController::class, 'setPassword'])->middleware('throttle:tenant-login')->name('password.store');

// A fee receipt a parent opens from the app or an email: the link is signed and expires.
Route::get('fees/receipt/{feePayment}', [\Modules\Fees\Http\Controllers\PaymentController::class, 'receipt'])->middleware('signed')->name('fees.receipt');

Route::middleware(['auth:web', 'tenant.member:staff'])->group(function () {
    Route::post('logout', [C\AuthController::class, 'logout'])->name('logout');
    Route::get('/', C\DashboardController::class)->name('dashboard');

    // Setup: institution, branches, sessions, structure, grading, calendar, certificate templates
    Route::middleware('can:academic.setup.manage')->prefix('setup')->name('setup.')->group(function () {
        Route::get('/', [C\SetupController::class, 'index'])->name('index');
        Route::put('profile', [C\SetupController::class, 'updateProfile'])->name('profile');
        Route::post('branches', [C\SetupController::class, 'storeBranch'])->name('branches.store');
        Route::put('branches/{branch}', [C\SetupController::class, 'updateBranch'])->name('branches.update');
        Route::get('sessions', [C\SetupController::class, 'sessions'])->name('sessions');
        Route::post('sessions', [C\SetupController::class, 'storeSession'])->name('sessions.store');
        Route::put('sessions/{session}', [C\SetupController::class, 'updateSession'])->name('sessions.update');
        Route::post('sessions/{session}/current', [C\SetupController::class, 'makeCurrent'])->name('sessions.current');
        Route::post('sessions/{session}/close', [C\SetupController::class, 'closeSession'])->name('sessions.close');
        Route::post('sessions/{session}/terms', [C\SetupController::class, 'storeTerm'])->name('terms.store');
        Route::put('terms/{term}', [C\SetupController::class, 'updateTerm'])->name('terms.update');
        Route::delete('terms/{term}', [C\SetupController::class, 'destroyTerm'])->name('terms.destroy');
        Route::get('structure', [C\SetupController::class, 'structure'])->name('structure');
        Route::post('programs', [C\SetupController::class, 'storeProgram'])->name('programs.store');
        Route::put('programs/{program}', [C\SetupController::class, 'updateProgram'])->name('programs.update');
        Route::post('programs/{program}/levels', [C\SetupController::class, 'storeLevel'])->name('levels.store');
        Route::put('levels/{level}', [C\SetupController::class, 'updateLevel'])->name('levels.update');
        Route::put('levels/{level}/subjects', [C\SetupController::class, 'levelSubjects'])->name('levels.subjects');
        Route::post('subjects', [C\SetupController::class, 'storeSubject'])->name('subjects.store');
        Route::put('subjects/{subject}', [C\SetupController::class, 'updateSubject'])->name('subjects.update');
        Route::get('grading', [C\SetupController::class, 'grading'])->name('grading');
        Route::post('grading', [C\SetupController::class, 'storeScheme'])->name('grading.store');
        Route::put('grading/{scheme}', [C\SetupController::class, 'updateScheme'])->name('grading.update');
        Route::get('calendar', [C\SetupController::class, 'calendar'])->name('calendar');
        Route::post('periods', [C\SetupController::class, 'storePeriod'])->name('periods.store');
        Route::put('periods/{period}', [C\SetupController::class, 'updatePeriod'])->name('periods.update');
        Route::delete('periods/{period}', [C\SetupController::class, 'destroyPeriod'])->name('periods.destroy');
        Route::post('holidays', [C\SetupController::class, 'storeHoliday'])->name('holidays.store');
        Route::delete('holidays/{holiday}', [C\SetupController::class, 'destroyHoliday'])->name('holidays.destroy');
        Route::get('certificates', [C\SetupController::class, 'templates'])->name('templates');
        Route::post('certificates', [C\SetupController::class, 'storeTemplate'])->name('templates.store');
        Route::put('certificates/{template}', [C\SetupController::class, 'updateTemplate'])->name('templates.update');
    });

    Route::middleware('can:academic.setup.manage')->group(function () {
        Route::get('staff', [C\StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [C\StaffController::class, 'store'])->name('staff.store');
        Route::get('staff/{staff}/edit', [C\StaffController::class, 'edit'])->name('staff.edit');
        Route::put('staff/{staff}', [C\StaffController::class, 'update'])->name('staff.update');

        Route::get('sections/create', [C\SectionController::class, 'create'])->name('sections.create');
        Route::post('sections', [C\SectionController::class, 'store'])->name('sections.store');
        Route::get('sections/{section}/edit', [C\SectionController::class, 'edit'])->name('sections.edit');
        Route::put('sections/{section}', [C\SectionController::class, 'update'])->name('sections.update');
        Route::post('sections/{section}/subjects', [C\SectionController::class, 'storeSubject'])->name('sections.subjects.store');
        Route::delete('section-subjects/{row}', [C\SectionController::class, 'destroySubject'])->name('sections.subjects.destroy');
        Route::post('sections/{section}/roll-numbers', [C\SectionController::class, 'rollNumbers'])->name('sections.roll-numbers');
    });
    Route::middleware('can:academic.students.view')->group(function () {
        Route::get('sections', [C\SectionController::class, 'index'])->name('sections.index');
        Route::get('sections/{section}', [C\SectionController::class, 'show'])->name('sections.show');
    });

    // Admissions
    Route::middleware('can:academic.admissions.manage')->group(function () {
        Route::get('admissions', [C\AdmissionController::class, 'index'])->name('admissions.index');
        Route::get('admissions/create', [C\AdmissionController::class, 'create'])->name('admissions.create');
        Route::post('admissions', [C\AdmissionController::class, 'store'])->name('admissions.store');
        Route::get('admissions/{admission}', [C\AdmissionController::class, 'show'])->name('admissions.show');
        Route::get('admissions/{admission}/edit', [C\AdmissionController::class, 'edit'])->name('admissions.edit');
        Route::put('admissions/{admission}', [C\AdmissionController::class, 'update'])->name('admissions.update');
        Route::post('admissions/{admission}/stage', [C\AdmissionController::class, 'stage'])->name('admissions.stage');
        Route::post('admissions/{admission}/documents', [C\AdmissionController::class, 'documents'])->name('admissions.documents');
        Route::post('admissions/{admission}/admit', [C\AdmissionController::class, 'admit'])->name('admissions.admit');
        Route::post('admissions/{admission}/uploads', [C\DocumentController::class, 'uploadForApplication'])->name('admissions.uploads.store');
    });

    // Students and guardians
    Route::middleware('can:academic.students.manage')->group(function () {
        Route::get('students/create', [C\StudentController::class, 'create'])->name('students.create');
        Route::post('students', [C\StudentController::class, 'store'])->name('students.store');
        Route::get('students/{student}/edit', [C\StudentController::class, 'edit'])->name('students.edit');
        Route::put('students/{student}', [C\StudentController::class, 'update'])->name('students.update');
        Route::post('students/{student}/guardians', [C\StudentController::class, 'addGuardian'])->name('students.guardians.store');
        Route::post('students/{student}/guardians/{guardian}/primary', [C\StudentController::class, 'primaryGuardian'])->name('students.guardians.primary');
        Route::delete('students/{student}/guardians/{guardian}', [C\StudentController::class, 'removeGuardian'])->name('students.guardians.destroy');
        Route::put('guardians/{guardian}', [C\StudentController::class, 'updateGuardian'])->name('guardians.update');
        Route::post('guardians/{guardian}/invite', [C\StudentController::class, 'inviteGuardian'])->name('guardians.invite');
        Route::post('students/{student}/invite', [C\StudentController::class, 'inviteStudent'])->name('students.invite');
        Route::post('students/{student}/move', [C\StudentController::class, 'move'])->name('students.move');
        Route::post('students/{student}/leave', [C\StudentController::class, 'leave'])->name('students.leave');
        Route::put('enrollments/{enrollment}/roll', [C\StudentController::class, 'roll'])->name('enrollments.roll');
        Route::post('students/{student}/uploads', [C\DocumentController::class, 'uploadForStudent'])->name('students.uploads.store');
    });
    Route::middleware('can:academic.students.view')->group(function () {
        Route::get('students', [C\StudentController::class, 'index'])->name('students.index');
        Route::get('students/{student}', [C\StudentController::class, 'show'])->name('students.show');
        Route::get('guardians', [C\GuardianController::class, 'index'])->name('guardians.index');
        Route::get('guardians/{guardian}', [C\GuardianController::class, 'show'])->name('guardians.show');
    });

    // Supporting documents and photos (permission checked per file: the student's record or the application)
    Route::get('documents/{document}', [C\DocumentController::class, 'show'])->name('documents.show');
    Route::post('documents/{document}/verify', [C\DocumentController::class, 'verify'])->name('documents.verify');
    Route::delete('documents/{document}', [C\DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('students/{student}/photo', [C\DocumentController::class, 'photo'])->name('students.photo');

    // Attendance
    Route::get('attendance', [C\AttendanceController::class, 'index'])->middleware('can:academic.attendance.view')->name('attendance.index');
    Route::get('attendance/mark', [C\AttendanceController::class, 'sheet'])->middleware('can:academic.attendance.mark')->name('attendance.sheet');
    Route::post('attendance/mark', [C\AttendanceController::class, 'mark'])->middleware('can:academic.attendance.mark')->name('attendance.mark');
    Route::get('attendance/register', [C\AttendanceController::class, 'register'])->middleware('can:academic.attendance.view')->name('attendance.register');
    Route::get('attendance/low', [C\AttendanceController::class, 'low'])->middleware('can:academic.attendance.view')->name('attendance.low');

    // Timetable
    Route::get('timetable', [C\TimetableController::class, 'section'])->middleware('can:academic.timetable.view')->name('timetable.section');
    Route::get('timetable/teacher', [C\TimetableController::class, 'teacher'])->middleware('can:academic.timetable.view')->name('timetable.teacher');
    Route::post('timetable/{section}', [C\TimetableController::class, 'set'])->middleware('can:academic.timetable.manage')->name('timetable.set');
    Route::delete('timetable-entries/{entry}', [C\TimetableController::class, 'clear'])->middleware('can:academic.timetable.manage')->name('timetable.clear');

    // Exams and marks
    Route::middleware('can:academic.exams.manage')->group(function () {
        Route::get('exams/create', [C\ExamController::class, 'create'])->name('exams.create');
        Route::post('exams', [C\ExamController::class, 'store'])->name('exams.store');
        Route::get('exams/{exam}/edit', [C\ExamController::class, 'edit'])->name('exams.edit');
        Route::put('exams/{exam}', [C\ExamController::class, 'update'])->name('exams.update');
        Route::post('exams/{exam}/papers', [C\ExamController::class, 'addPapers'])->name('exams.papers.store');
        Route::put('papers/{paper}', [C\ExamController::class, 'updatePaper'])->name('papers.update');
        Route::delete('papers/{paper}', [C\ExamController::class, 'deletePaper'])->name('papers.destroy');
        Route::post('exams/{exam}/status', [C\ExamController::class, 'status'])->name('exams.status');
        Route::get('exams/{exam}', [C\ExamController::class, 'show'])->name('exams.show');
    });
    Route::middleware('can:academic.marks.enter')->group(function () {
        Route::get('exams', [C\ExamController::class, 'index'])->name('exams.index');
        Route::get('papers/{paper}/marks', [C\ExamController::class, 'marks'])->name('papers.marks');
        Route::post('papers/{paper}/marks', [C\ExamController::class, 'saveMarks'])->name('papers.marks.save');
        Route::get('exams/{exam}/analysis', [C\ExamController::class, 'analysis'])->name('exams.analysis');
    });

    // Report cards
    Route::get('report-cards', [C\ReportCardController::class, 'index'])->middleware('can:academic.reportcards.publish')->name('report-cards.index');
    Route::post('report-cards/{section}/publish', [C\ReportCardController::class, 'publish'])->middleware('can:academic.reportcards.publish')->name('report-cards.publish');
    Route::get('report-cards/list', [C\ReportCardController::class, 'list'])->middleware('can:academic.students.view')->name('report-cards.list');
    Route::get('report-cards/{card}', [C\ReportCardController::class, 'show'])->middleware('can:academic.students.view')->name('report-cards.show');

    // Homework
    Route::middleware('can:academic.homework.manage')->group(function () {
        Route::get('homework', [C\HomeworkController::class, 'index'])->name('homework.index');
        Route::get('homework/create', [C\HomeworkController::class, 'create'])->name('homework.create');
        Route::post('homework', [C\HomeworkController::class, 'store'])->name('homework.store');
        Route::get('homework/{homework}', [C\HomeworkController::class, 'show'])->name('homework.show');
        Route::get('homework/{homework}/edit', [C\HomeworkController::class, 'edit'])->name('homework.edit');
        Route::put('homework/{homework}', [C\HomeworkController::class, 'update'])->name('homework.update');
        Route::post('homework/{homework}/check', [C\HomeworkController::class, 'check'])->name('homework.check');
        Route::post('homework/{homework}/cancel', [C\HomeworkController::class, 'cancel'])->name('homework.cancel');
    });

    // Certificates
    Route::middleware('can:academic.certificates.issue')->group(function () {
        Route::get('certificates', [C\CertificateController::class, 'index'])->name('certificates.index');
        Route::get('certificates/create', [C\CertificateController::class, 'create'])->name('certificates.create');
        Route::post('certificates', [C\CertificateController::class, 'store'])->name('certificates.store');
        Route::get('certificates/{certificate}', [C\CertificateController::class, 'show'])->name('certificates.show');
        Route::post('certificates/{certificate}/cancel', [C\CertificateController::class, 'cancel'])->name('certificates.cancel');
    });

    // Promotion
    Route::get('promotion', [C\PromotionController::class, 'index'])->middleware('can:academic.promotion.run')->name('promotion.index');
    Route::post('promotion', [C\PromotionController::class, 'run'])->middleware('can:academic.promotion.run')->name('promotion.run');

    require __DIR__.'/tenant-accounting.php';
    require __DIR__.'/tenant-fees.php';
    require __DIR__.'/tenant-hr.php';
    require __DIR__.'/tenant-ai.php';

    // Reports
    Route::middleware('can:academic.reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [C\ReportController::class, 'index'])->name('index');
        Route::get('strength', [C\ReportController::class, 'strength'])->name('strength');
        Route::get('admission-register', [C\ReportController::class, 'admissionRegister'])->name('admission-register');
        Route::get('admissions', [C\ReportController::class, 'funnel'])->name('funnel');
        Route::get('homework', [C\ReportController::class, 'homework'])->name('homework');
    });
});
