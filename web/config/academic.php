<?php

/*
| Quick School AI academic ERP settings.
| One configurable structure serves schools, colleges and coaching institutes:
|   Programme  →  Level  →  Section (or batch)  →  Subjects
| Only the words on screen and the defaults created at onboarding differ.
*/

return [

    // How institutions are addressed: 'subdomain' ({code}.quickschool.app) or 'path' (/i/{code}, for local development).
    'routing' => env('TENANT_ROUTING', 'subdomain'),

    'structures' => [
        'school' => [
            'label' => 'K-12 school',
            'terms' => ['program' => 'Curriculum', 'programs' => 'Curricula', 'level' => 'Class', 'levels' => 'Classes',
                'section' => 'Section', 'sections' => 'Sections', 'term' => 'Term', 'subject' => 'Subject', 'subjects' => 'Subjects'],
            'grading' => 'cbse',
        ],
        'college' => [
            'label' => 'College',
            'terms' => ['program' => 'Programme', 'programs' => 'Programmes', 'level' => 'Semester', 'levels' => 'Semesters',
                'section' => 'Section', 'sections' => 'Sections', 'term' => 'Semester', 'subject' => 'Course', 'subjects' => 'Courses'],
            'grading' => 'ten_point',
        ],
        'coaching' => [
            'label' => 'Coaching institute',
            'terms' => ['program' => 'Course', 'programs' => 'Courses', 'level' => 'Level', 'levels' => 'Levels',
                'section' => 'Batch', 'sections' => 'Batches', 'term' => 'Module', 'subject' => 'Subject', 'subjects' => 'Subjects'],
            'grading' => 'percentage',
        ],
    ],

    // Tenant type (Super Admin) → academic structure.
    'type_structure' => ['school' => 'school', 'college' => 'college', 'coaching' => 'coaching', 'training' => 'coaching'],

    // Grading schemes created at onboarding. Bands: [grade, minimum percent, grade point, remark].
    'grading_presets' => [
        'cbse' => ['name' => 'CBSE 8-point grades', 'type' => 'grades', 'pass_percent' => 33, 'bands' => [
            ['A1', 91, 10, 'Outstanding'], ['A2', 81, 9, 'Excellent'], ['B1', 71, 8, 'Very good'], ['B2', 61, 7, 'Good'],
            ['C1', 51, 6, 'Above average'], ['C2', 41, 5, 'Average'], ['D', 33, 4, 'Pass'], ['E', 0, 0, 'Needs improvement'],
        ]],
        'ten_point' => ['name' => '10-point grade scale', 'type' => 'gpa', 'pass_percent' => 40, 'bands' => [
            ['O', 90, 10, 'Outstanding'], ['A+', 80, 9, 'Excellent'], ['A', 70, 8, 'Very good'], ['B+', 60, 7, 'Good'],
            ['B', 55, 6, 'Above average'], ['C', 50, 5, 'Average'], ['P', 40, 4, 'Pass'], ['F', 0, 0, 'Fail'],
        ]],
        'percentage' => ['name' => 'Percentage with divisions', 'type' => 'percentage', 'pass_percent' => 35, 'bands' => [
            ['Distinction', 75, null, 'Distinction'], ['First', 60, null, 'First division'], ['Second', 45, null, 'Second division'],
            ['Pass', 35, null, 'Pass'], ['Fail', 0, null, 'Fail'],
        ]],
    ],

    // Classes created at onboarding for a K-12 school.
    'school_levels' => ['Nursery', 'LKG', 'UKG', 'Class 1', 'Class 2', 'Class 3', 'Class 4', 'Class 5', 'Class 6', 'Class 7', 'Class 8', 'Class 9', 'Class 10', 'Class 11', 'Class 12'],
    'school_subjects' => [
        ['ENG', 'English', 'core'], ['HIN', 'Hindi', 'core'], ['MAT', 'Mathematics', 'core'], ['SCI', 'Science', 'core'],
        ['SST', 'Social Science', 'core'], ['COM', 'Computer Science', 'elective'], ['SAN', 'Sanskrit', 'elective'],
        ['ART', 'Art Education', 'co_scholastic'], ['PHE', 'Health and Physical Education', 'co_scholastic'],
    ],

    'subject_types' => ['core' => 'Core', 'elective' => 'Elective', 'lab' => 'Lab or practical', 'co_scholastic' => 'Co-scholastic (graded only)'],

    /*
    | Roles inside an institution, and what each may do.
    | Teachers are further limited to the sections and subjects they teach.
    */
    'roles' => [
        'owner' => 'Owner', 'admin' => 'Administrator', 'front_office' => 'Front office', 'teacher' => 'Teacher',
        'accountant' => 'Accountant', 'hr' => 'HR manager', 'parent' => 'Parent', 'student' => 'Student',
    ],
    'permissions' => [
        'setup.manage' => ['owner', 'admin'],
        'admissions.manage' => ['owner', 'admin', 'front_office'],
        'students.view' => ['owner', 'admin', 'front_office', 'teacher', 'accountant'],
        'students.manage' => ['owner', 'admin', 'front_office'],
        'attendance.mark' => ['owner', 'admin', 'teacher'],
        'attendance.view' => ['owner', 'admin', 'front_office', 'teacher'],
        'timetable.manage' => ['owner', 'admin'],
        'timetable.view' => ['owner', 'admin', 'front_office', 'teacher'],
        'exams.manage' => ['owner', 'admin'],
        'marks.enter' => ['owner', 'admin', 'teacher'],
        'reportcards.publish' => ['owner', 'admin'],
        'homework.manage' => ['owner', 'admin', 'teacher'],
        'certificates.issue' => ['owner', 'admin', 'front_office'],
        'promotion.run' => ['owner', 'admin'],
        'reports.view' => ['owner', 'admin', 'front_office', 'teacher', 'accountant'],
        // Accounting
        'accounts.view' => ['owner', 'admin', 'accountant'],
        'accounts.post' => ['owner', 'admin', 'accountant'],
        'accounts.approve' => ['owner', 'admin'],
        'accounts.configure' => ['owner', 'admin'],
        'fees.view' => ['owner', 'admin', 'accountant', 'front_office'],
        'fees.collect' => ['owner', 'admin', 'accountant', 'front_office'],
        'fees.configure' => ['owner', 'admin', 'accountant'],
        'fees.approve' => ['owner', 'admin'],      // concessions, waivers, refunds, bounced cheques
        'hr.view' => ['owner', 'admin', 'hr', 'accountant'],
        'hr.manage' => ['owner', 'admin', 'hr'],   // employees, documents, attendance, leave approval, shifts
        'payroll.process' => ['owner', 'admin', 'hr', 'accountant'],
        'payroll.approve' => ['owner', 'admin'],   // approving payroll and full and final settlements posts them to the books
        'payroll.configure' => ['owner', 'admin', 'hr'],   // salary components, structures and statutory rules
        'ai.use' => ['owner', 'admin', 'front_office', 'teacher', 'accountant', 'hr', 'parent'],
        'ai.approve' => ['owner', 'admin', 'accountant', 'hr'],   // sees AI proposals; executing one still needs the target permission
        'ai.configure' => ['owner', 'admin'],      // prompts, models, knowledge base
    ],
    // Roles that see every section, not just their own.
    'all_sections_roles' => ['owner', 'admin', 'front_office', 'accountant'],

    'admission' => [
        'stages' => ['enquiry', 'applied', 'under_review', 'waitlisted', 'offered', 'admitted', 'rejected', 'withdrawn'],
        'transitions' => [
            'enquiry' => ['applied', 'rejected', 'withdrawn'],
            'applied' => ['under_review', 'offered', 'waitlisted', 'rejected', 'withdrawn'],
            'under_review' => ['offered', 'waitlisted', 'rejected', 'withdrawn'],
            'waitlisted' => ['offered', 'rejected', 'withdrawn'],
            'offered' => ['admitted', 'rejected', 'withdrawn'],
            'admitted' => [], 'rejected' => [], 'withdrawn' => [],
        ],
        'sources' => ['walk_in' => 'Walk-in', 'phone' => 'Phone call', 'website' => 'Website', 'referral' => 'Referral', 'social' => 'Social media', 'event' => 'School event'],
        'documents' => [
            'birth_certificate' => 'Birth certificate', 'photo' => 'Passport-size photo', 'address_proof' => 'Address proof',
            'previous_report' => 'Previous report card', 'transfer_certificate' => 'Transfer certificate (from previous school)', 'id_proof' => 'Student ID (Aadhaar or APAAR)',
        ],
        'default_required' => ['birth_certificate', 'photo', 'address_proof'],
    ],

    // Uploaded documents and photos, stored privately under tenants/{tenant_id}/ on this disk.
    'uploads' => [
        'disk' => env('ACADEMIC_UPLOADS_DISK', 'local'),
        'document_mimes' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
        'document_max_kb' => 5120,
        'photo_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
        'photo_max_kb' => 2048,
        'photo_min_px' => 150,
    ],

    'attendance' => [
        'statuses' => ['present' => 'Present', 'absent' => 'Absent', 'late' => 'Late', 'half_day' => 'Half day', 'leave' => 'On leave'],
        // Weight towards the attendance percentage.
        'weights' => ['present' => 1.0, 'late' => 1.0, 'half_day' => 0.5, 'absent' => 0.0, 'leave' => 0.0],
    ],

    'exam_types' => ['unit_test' => 'Unit test', 'periodic' => 'Periodic test', 'term' => 'Term exam', 'final' => 'Final exam',
        'internal' => 'Internal assessment', 'practical' => 'Practical', 'mock' => 'Mock test'],

    'certificate_types' => [
        'bonafide' => ['label' => 'Bonafide certificate', 'series' => 'BON'],
        'character' => ['label' => 'Character certificate', 'series' => 'CHR'],
        'transfer' => ['label' => 'Transfer certificate', 'series' => 'TC'],
        'study' => ['label' => 'Study certificate', 'series' => 'STU'],
        'course_completion' => ['label' => 'Course completion certificate', 'series' => 'CC'],
        'custom' => ['label' => 'Other certificate', 'series' => 'CER'],
    ],

    'categories' => ['general' => 'General', 'obc' => 'OBC', 'sc' => 'SC', 'st' => 'ST', 'ews' => 'EWS'],
    'blood_groups' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'],
    'relations' => ['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian', 'grandparent' => 'Grandparent', 'sibling' => 'Sibling', 'other' => 'Other'],
];
