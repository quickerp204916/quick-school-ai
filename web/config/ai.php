<?php

/*
| AI Intelligence Engine. The agents run in the Python service (ai-service/, FastAPI + LangGraph); this app owns
| prompts, conversations, usage, credits, the permission-checked tools and the human approval of proposals.
*/
return [
    'service_url' => env('QSA_AI_SERVICE_URL', 'http://127.0.0.1:8090'),
    'service_key' => env('QSA_AI_SERVICE_KEY'),          // shared secret; also set on the AI service
    'session_minutes' => 10,                            // how long the service may call tools for one chat turn
    'history_turns' => 12,

    'providers' => [
        'openai' => ['label' => 'OpenAI', 'models' => ['gpt-4.1-mini', 'gpt-4.1', 'gpt-4o-mini', 'gpt-4o']],
        'gemini' => ['label' => 'Google Gemini', 'models' => ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.0-flash']],
    ],
    'default' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini'],

    // AI credits charged per 1,000 tokens (input and output together), by model. Unknown models use 'default'.
    'credits_per_1k' => ['gpt-4.1-mini' => 1, 'gpt-4o-mini' => 1, 'gemini-2.0-flash' => 1, 'gemini-2.5-flash' => 1, 'gpt-4.1' => 5, 'gpt-4o' => 5, 'gemini-2.5-pro' => 5, 'default' => 2],
    'extraction_credits' => 5,

    'agents' => [
        'school' => ['label' => 'School Assistant', 'description' => 'Students, attendance, fees and policies at a glance.', 'tools' => ['school.', 'students.', 'academic.', 'admission.funnel', 'fees.dues', 'fees.student_account']],
        'accounting' => ['label' => 'Accounting Assistant', 'description' => 'Reports, vouchers, outstanding bills and anomaly checks.', 'tools' => ['accounting.', 'fees.']],
        'payroll' => ['label' => 'Payroll Assistant', 'description' => 'Payroll runs, payslips, statutory deductions and leave.', 'tools' => ['payroll.']],
        'admission' => ['label' => 'Admission Assistant', 'description' => 'Enquiry funnel, follow-ups and new enquiries.', 'tools' => ['admission.', 'students.search']],
        'academic' => ['label' => 'Academic Assistant', 'description' => 'Attendance, homework and results for your sections.', 'tools' => ['academic.', 'students.']],
        'document' => ['label' => 'Document Processing Assistant', 'description' => 'Reads marksheets, certificates and vendor bills for review.', 'tools' => ['documents.', 'students.search']],
        'voice' => ['label' => 'Voice Calling Assistant', 'description' => 'Fee reminder and admission calls with parents.', 'tools' => ['voice.', 'fees.dues', 'fees.student_account', 'admission.follow_ups', 'admission.create_enquiry']],
    ],

    'languages' => ['en' => 'English', 'hi' => 'हिन्दी', 'mr' => 'मराठी', 'ta' => 'தமிழ்', 'te' => 'తెలుగు', 'kn' => 'ಕನ್ನಡ', 'bn' => 'বাংলা', 'gu' => 'ગુજરાતી', 'ml' => 'മലയാളം', 'pa' => 'ਪੰਜਾਬੀ'],
    'document_types' => ['marksheet' => 'Marksheet', 'transfer_certificate' => 'Transfer certificate', 'birth_certificate' => 'Birth certificate', 'vendor_invoice' => 'Vendor bill'],
];
