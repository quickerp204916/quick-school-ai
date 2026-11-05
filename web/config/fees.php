<?php

/*
| Fee management defaults. Amounts are integer paise.
*/
return [
    // [code, name, kind, ledger code, refundable]. Seeded for each new institution; edit them under Fees → Setup.
    'heads' => [
        ['TUITION', 'Tuition fee', 'recurring', 'FEE-TUITION', false],
        ['ADMISSION', 'Admission fee', 'one_time', 'FEE-ADMISSION', false],
        ['ANNUAL', 'Annual charges', 'recurring', 'FEE-TUITION', false],
        ['TRANSPORT', 'Transport fee', 'optional', 'FEE-TRANSPORT', false],
        ['CAUTION', 'Caution money (refundable)', 'deposit', 'CAUTION', true],
    ],

    // How many instalments each frequency makes in a session, counted from the session's first month.
    'periods' => ['monthly' => 12, 'quarterly' => 4, 'half_yearly' => 2, 'annual' => 1, 'one_time' => 1],

    // Invoices are issued this many days before they fall due (and posted to the books on that date).
    'issue_days_before_due' => 10,

    // Gateways. Credentials are stored per institution (encrypted) under Fees → Settings.
    'razorpay' => ['api' => 'https://api.razorpay.com/v1'],
    'cashfree' => [
        'api' => ['sandbox' => 'https://sandbox.cashfree.com/pg', 'production' => 'https://api.cashfree.com/pg'],
        'version' => '2023-08-01',
        'webhook_tolerance' => 600,   // seconds: older signed webhooks are refused (replay protection)
    ],
];
