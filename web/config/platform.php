<?php

/*
| Quick School AI platform settings (Super Admin module).
| Values marked SAMPLE are placeholders: confirm them before go-live.
*/

return [

    // Each institution gets <code>.<base_domain>, e.g. dps-noida.quickschool.app
    'base_domain' => env('PLATFORM_BASE_DOMAIN', 'quickschool.app'),

    // Institution codes that may never be used as subdomains.
    'reserved_codes' => ['www', 'api', 'app', 'admin', 'platform', 'mail', 'support', 'status', 'docs', 'billing', 'help', 'static', 'assets'],

    // Seller details printed on platform invoices to institutions.
    'seller' => [
        'name' => env('PLATFORM_SELLER_NAME', 'Quick ERP Technologies Pvt. Ltd.'),
        'gstin' => env('PLATFORM_SELLER_GSTIN', ''),              // SAMPLE: set your GSTIN
        'state_code' => env('PLATFORM_SELLER_STATE_CODE', '09'),  // SAMPLE: 09 = Uttar Pradesh
        'address' => env('PLATFORM_SELLER_ADDRESS', ''),
        'sac' => '997331',                                       // SAMPLE: confirm SAC with your CA
    ],

    'billing' => [
        'gst_rate_bp' => 1800,          // 18% in basis points. SAMPLE: confirm with your CA
        'invoice_series' => 'QSA',
        'due_days' => 7,                // invoice due this many days after issue
        'suspend_after_days' => 14,     // suspend this many days after the due date
    ],

    'support' => [
        'max_session_minutes' => 120,
        'default_session_minutes' => 30,
    ],

    'roles' => [
        'super_admin' => 'Super admin',
        'billing' => 'Billing',
        'support' => 'Support',
    ],

    'institution_types' => [
        'school' => 'K-12 school',
        'college' => 'College',
        'coaching' => 'Coaching institute',
        'training' => 'Training institute',
    ],

    /*
    | Feature catalogue. "bool" features are on or off. "limit" features are
    | numbers; null means unlimited. Plans set values; tenants can get overrides.
    */
    'features' => [
        'students_max' => ['type' => 'limit', 'label' => 'Active students', 'default' => 0],
        'branches_max' => ['type' => 'limit', 'label' => 'Branches', 'default' => 1],
        'staff_max' => ['type' => 'limit', 'label' => 'Staff accounts', 'default' => 0],
        'ai_credits_monthly' => ['type' => 'limit', 'label' => 'AI credits per month', 'default' => 0],
        'ai_assistant' => ['type' => 'bool', 'label' => 'AI assistant', 'default' => false],
        'whatsapp' => ['type' => 'bool', 'label' => 'WhatsApp messaging', 'default' => false],
        'transport' => ['type' => 'bool', 'label' => 'Transport module', 'default' => false],
        'library_inventory' => ['type' => 'bool', 'label' => 'Library and inventory', 'default' => false],
        'multi_branch' => ['type' => 'bool', 'label' => 'Multi-branch reports', 'default' => false],
        'custom_domain' => ['type' => 'bool', 'label' => 'Custom domain', 'default' => false],
        'api_access' => ['type' => 'bool', 'label' => 'API access', 'default' => false],
        'voice_agent' => ['type' => 'bool', 'label' => 'AI voice calling', 'default' => false],
    ],

];
