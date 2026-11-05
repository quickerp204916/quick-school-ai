<?php

/*
| HR and payroll defaults for a new institution. Amounts are integer paise; rates are basis points (1% = 100).
|
| Statutory values are starting points seeded as effective-dated rules. Each institution can add a new version
| with a later effective date (never edit history). Confirm every value with your chartered accountant or payroll
| consultant before the first live payroll: PF, ESI, professional tax and income tax change from time to time.
*/
return [
    'departments' => [['ADMIN', 'Administration'], ['ACAD', 'Academics'], ['ACCT', 'Accounts'], ['TRANS', 'Transport'], ['SUPPORT', 'Support staff']],
    'designations' => ['Principal', 'Vice principal', 'PGT', 'TGT', 'PRT', 'Accountant', 'Office assistant', 'Driver', 'Peon', 'Librarian', 'Lab assistant'],

    // [code, name, kind, taxable, counts for PF wages, counts for ESI wages, ledger system key]
    'components' => [
        ['BASIC', 'Basic pay', 'earning', true, true, true, 'salary_expense'],
        ['DA', 'Dearness allowance', 'earning', true, true, true, 'salary_expense'],
        ['HRA', 'House rent allowance', 'earning', true, false, true, 'salary_expense'],
        ['CONV', 'Conveyance allowance', 'earning', true, false, true, 'salary_expense'],
        ['SPECIAL', 'Special allowance', 'earning', true, false, true, 'salary_expense'],
        ['OT', 'Overtime', 'earning', true, false, true, 'overtime_expense'],
        ['BONUS', 'Bonus or arrears', 'earning', true, false, false, 'salary_expense'],
        ['PF', 'Provident fund (employee)', 'deduction', false, false, false, 'pf_payable'],
        ['ESI', 'ESI (employee)', 'deduction', false, false, false, 'esi_payable'],
        ['PT', 'Professional tax', 'deduction', false, false, false, 'pt_payable'],
        ['TDS', 'Income tax (TDS)', 'deduction', false, false, false, 'tds_payable'],
        ['ADVANCE', 'Salary advance recovery', 'deduction', false, false, false, 'staff_advance'],
        ['OTHER', 'Other deduction', 'deduction', false, false, false, 'other_recovery'],
    ],
    // Computed by the payroll engine from the statutory rules; never typed in a structure.
    'computed' => ['OT', 'PF', 'ESI', 'PT', 'TDS'],

    // [code, name, days a year, paid, carry forward up to, encashable at exit]
    'leave_types' => [
        ['CL', 'Casual leave', 12, true, 0, false],
        ['SL', 'Sick leave', 10, true, 20, false],
        ['EL', 'Earned leave', 15, true, 45, true],
        ['LWP', 'Leave without pay', 0, false, 0, false],
    ],

    'shift' => ['name' => 'General', 'starts_at' => '08:00', 'ends_at' => '15:30', 'break_minutes' => 30, 'grace_minutes' => 10, 'weekly_off' => [0]],

    // Effective-dated statutory rules seeded for every institution. [code => [effective from, config]]
    'statutory' => [
        'pf' => ['2014-09-01', ['employee_bp' => 1200, 'employer_bp' => 1200, 'eps_bp' => 833, 'wage_ceiling' => 1500000, 'restrict_to_ceiling' => true]],
        'esi' => ['2019-07-01', ['employee_bp' => 75, 'employer_bp' => 325, 'gross_ceiling' => 2100000]],
        // Professional tax slabs on monthly gross, by state. 'feb' = the amount charged in February instead (Maharashtra).
        'pt' => ['2023-04-01', ['states' => [
            '27' => ['name' => 'Maharashtra', 'slabs' => [[0, 750000, 0], [750001, 1000000, 17500], [1000001, null, 20000, 'feb' => 30000]]],
            '29' => ['name' => 'Karnataka', 'slabs' => [[0, 2499999, 0], [2500000, null, 20000]]],
            '19' => ['name' => 'West Bengal', 'slabs' => [[0, 1000000, 0], [1000001, 1500000, 11000], [1500001, 2500000, 13000], [2500001, 4000000, 15000], [4000001, null, 20000]]],
            '09' => ['name' => 'Uttar Pradesh', 'slabs' => []],
            '07' => ['name' => 'Delhi', 'slabs' => []],
        ]]],
        // Income tax on salary (Section 192). Annual slabs in rupees. New regime is the default from FY 2023-24.
        'tds' => ['2025-04-01', [
            'new' => ['standard_deduction' => 75000, 'rebate_limit' => 1200000, 'rebate_max' => 60000,
                'slabs' => [[400000, 0], [800000, 500], [1200000, 1000], [1600000, 1500], [2000000, 2000], [2400000, 2500], [null, 3000]]],
            'old' => ['standard_deduction' => 50000, 'rebate_limit' => 500000, 'rebate_max' => 12500, 'max_80c' => 150000,
                'slabs' => [[250000, 0], [500000, 500], [1000000, 2000], [null, 3000]]],
            'cess_bp' => 400,
        ]],
        'overtime' => ['2020-04-01', ['multiplier_pct' => 200, 'divisor_days' => 26, 'hours_per_day' => 8]],
        'gratuity' => ['2018-03-29', ['min_years' => 5, 'days_per_year' => 15, 'divisor_days' => 26, 'cap' => 2000000_00]],
    ],

    'documents' => ['offer_letter' => 'Offer letter', 'appointment' => 'Appointment letter', 'id_proof' => 'ID proof', 'pan' => 'PAN card', 'address' => 'Address proof',
        'qualification' => 'Qualification certificate', 'experience' => 'Experience letter', 'relieving' => 'Relieving letter', 'police' => 'Police verification', 'other' => 'Other'],
];
