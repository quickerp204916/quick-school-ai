<?php

/*
| Quick School AI double-entry accounting.
| All amounts are integer paise. Default chart of accounts for an Indian school, college or institute.
| Tax rates and TDS sections below are starting values: confirm them with your chartered accountant.
*/

return [

    'voucher_types' => [
        'payment' => ['label' => 'Payment voucher', 'prefix' => 'PV'],
        'receipt' => ['label' => 'Receipt voucher', 'prefix' => 'RV'],
        'contra' => ['label' => 'Contra voucher', 'prefix' => 'CV'],
        'journal' => ['label' => 'Journal voucher', 'prefix' => 'JV'],
        'sales' => ['label' => 'Sales invoice', 'prefix' => 'SV'],
        'purchase' => ['label' => 'Purchase invoice', 'prefix' => 'PU'],
        'credit_note' => ['label' => 'Credit note', 'prefix' => 'CN'],
        'debit_note' => ['label' => 'Debit note', 'prefix' => 'DN'],
        'expense' => ['label' => 'Expense', 'prefix' => 'EX'],
        'opening' => ['label' => 'Opening balances', 'prefix' => 'OB'],
        'reversal' => ['label' => 'Reversal', 'prefix' => 'RE'],
    ],
    // Types a person enters directly. The others come from invoices, expenses, opening balances or other modules.
    'manual_types' => ['payment', 'receipt', 'contra', 'journal'],

    // Vouchers at or above this amount (paise) need a second person's approval. 0 = always, null = never.
    'approval_thresholds' => [
        'payment' => 2500000, 'receipt' => null, 'contra' => null, 'journal' => 0,
        'sales' => null, 'purchase' => 5000000, 'expense' => 1000000, 'credit_note' => 0, 'debit_note' => 0,
    ],

    'invoice_series' => ['sales' => 'INV', 'purchase' => 'PUR', 'credit_note' => 'CRN', 'debit_note' => 'DBN', 'expense' => 'EXP'],

    'natures' => ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Capital and reserves', 'income' => 'Income', 'expense' => 'Expense'],
    'ledger_types' => ['general' => 'General', 'cash' => 'Cash', 'bank' => 'Bank', 'customer' => 'Customer', 'vendor' => 'Vendor', 'student' => 'Student',
        'employee' => 'Employee', 'tax' => 'Tax', 'gateway' => 'Payment gateway'],

    // [code, name, nature, parent code, cash flow class]
    'groups' => [
        ['A', 'Assets', 'asset', null, null],
        ['A1', 'Current assets', 'asset', 'A', 'operating'],
        ['A11', 'Cash in hand', 'asset', 'A1', 'cash'],
        ['A12', 'Bank accounts', 'asset', 'A1', 'cash'],
        ['A13', 'Fees receivable', 'asset', 'A1', 'operating'],
        ['A14', 'Sundry debtors', 'asset', 'A1', 'operating'],
        ['A15', 'Taxes recoverable', 'asset', 'A1', 'operating'],
        ['A16', 'Advances and deposits paid', 'asset', 'A1', 'operating'],
        ['A17', 'Payment gateway clearing', 'asset', 'A1', 'operating'],
        ['A2', 'Fixed assets', 'asset', 'A', 'investing'],
        ['A21', 'Accumulated depreciation', 'asset', 'A2', 'investing'],
        ['L', 'Liabilities', 'liability', null, null],
        ['L1', 'Current liabilities', 'liability', 'L', 'operating'],
        ['L11', 'Sundry creditors', 'liability', 'L1', 'operating'],
        ['L12', 'Duties and taxes', 'liability', 'L1', 'operating'],
        ['L13', 'Payroll liabilities', 'liability', 'L1', 'operating'],
        ['L14', 'Fees received in advance', 'liability', 'L1', 'operating'],
        ['L15', 'Deposits received', 'liability', 'L1', 'operating'],
        ['L2', 'Loans', 'liability', 'L', 'financing'],
        ['E', 'Capital and reserves', 'equity', null, 'financing'],
        ['E1', 'Capital or corpus fund', 'equity', 'E', 'financing'],
        ['E2', 'Reserves and surplus', 'equity', 'E', 'financing'],
        ['I', 'Income', 'income', null, 'operating'],
        ['I1', 'Fee income', 'income', 'I', 'operating'],
        ['I2', 'Other income', 'income', 'I', 'operating'],
        ['X', 'Expenses', 'expense', null, 'operating'],
        ['X1', 'Staff costs', 'expense', 'X', 'operating'],
        ['X2', 'Administrative expenses', 'expense', 'X', 'operating'],
        ['X3', 'Academic expenses', 'expense', 'X', 'operating'],
        ['X4', 'Finance costs', 'expense', 'X', 'operating'],
        ['X5', 'Depreciation', 'expense', 'X', 'operating'],
    ],

    // [code, name, group code, type, system key]. System ledgers receive automatic postings and cannot be deleted.
    'ledgers' => [
        ['CASH', 'Cash in hand', 'A11', 'cash', 'cash'],
        ['BANK', 'Main bank account', 'A12', 'bank', 'bank_main'],
        ['FEE-REC', 'Fees receivable', 'A13', 'general', 'fee_receivable'],
        ['GW-RAZORPAY', 'Razorpay clearing', 'A17', 'gateway', 'gateway_razorpay'],
        ['GW-CASHFREE', 'Cashfree clearing', 'A17', 'gateway', 'gateway_cashfree'],
        ['GST-IN-CGST', 'Input CGST', 'A15', 'tax', 'gst_input_cgst'],
        ['GST-IN-SGST', 'Input SGST', 'A15', 'tax', 'gst_input_sgst'],
        ['GST-IN-IGST', 'Input IGST', 'A15', 'tax', 'gst_input_igst'],
        ['TDS-REC', 'TDS receivable', 'A15', 'tax', 'tds_receivable'],
        ['STAFF-ADV', 'Staff advances', 'A16', 'general', 'staff_advance'],
        ['FA-FURNITURE', 'Furniture and fixtures', 'A2', 'general', null],
        ['FA-COMPUTERS', 'Computers and IT equipment', 'A2', 'general', null],
        ['FA-VEHICLES', 'Vehicles (school buses)', 'A2', 'general', null],
        ['ACC-DEP', 'Accumulated depreciation', 'A21', 'general', 'accumulated_depreciation'],
        ['GST-OUT-CGST', 'Output CGST', 'L12', 'tax', 'gst_output_cgst'],
        ['GST-OUT-SGST', 'Output SGST', 'L12', 'tax', 'gst_output_sgst'],
        ['GST-OUT-IGST', 'Output IGST', 'L12', 'tax', 'gst_output_igst'],
        ['TDS-PAY', 'TDS payable', 'L12', 'tax', 'tds_payable'],
        ['SAL-PAY', 'Salaries payable', 'L13', 'general', 'salary_payable'],
        ['PF-PAY', 'PF payable', 'L13', 'tax', 'pf_payable'],
        ['ESI-PAY', 'ESI payable', 'L13', 'tax', 'esi_payable'],
        ['PT-PAY', 'Professional tax payable', 'L13', 'tax', 'pt_payable'],
        ['FEE-ADV', 'Fees received in advance', 'L14', 'general', 'fee_advance'],
        ['FEE-REFUND', 'Fee refunds payable', 'L14', 'general', 'fee_refund_payable'],
        ['CAUTION', 'Caution money deposits', 'L15', 'general', 'caution_deposit'],
        ['CAPITAL', 'Corpus fund', 'E1', 'general', 'capital'],
        ['SURPLUS', 'Surplus brought forward', 'E2', 'general', 'retained_surplus'],
        ['OPENING-DIFF', 'Opening balance difference', 'E2', 'general', 'opening_difference'],
        ['FEE-TUITION', 'Tuition fee', 'I1', 'general', 'fee_tuition'],
        ['FEE-ADMISSION', 'Admission fee', 'I1', 'general', 'fee_admission'],
        ['FEE-TRANSPORT', 'Transport fee', 'I1', 'general', 'fee_transport'],
        ['FEE-LATE', 'Late fee', 'I2', 'general', 'fee_late'],
        ['INTEREST', 'Interest income', 'I2', 'general', 'interest_income'],
        ['MISC-INCOME', 'Other income', 'I2', 'general', null],
        ['NOTICE-RECOVERY', 'Notice pay recovered', 'I2', 'general', 'notice_recovery'],
        ['SALARY', 'Salaries and wages', 'X1', 'general', 'salary_expense'],
        ['OVERTIME', 'Overtime wages', 'X1', 'general', 'overtime_expense'],
        ['GRATUITY', 'Gratuity', 'X1', 'general', 'gratuity_expense'],
        ['LEAVE-ENCASH', 'Leave encashment', 'X1', 'general', 'leave_encashment'],
        ['PF-EMPLOYER', 'Employer PF contribution', 'X1', 'general', 'pf_employer'],
        ['ESI-EMPLOYER', 'Employer ESI contribution', 'X1', 'general', 'esi_employer'],
        ['RENT', 'Rent', 'X2', 'general', null],
        ['ELECTRICITY', 'Electricity and water', 'X2', 'general', null],
        ['REPAIRS', 'Repairs and maintenance', 'X2', 'general', null],
        ['ROUND-OFF', 'Rounding off', 'X2', 'general', 'round_off'],
        ['STATIONERY', 'Printing and stationery', 'X3', 'general', null],
        ['CONCESSION', 'Fee concessions and scholarships', 'X3', 'general', 'fee_concession'],
        ['BANK-CHARGES', 'Bank charges', 'X4', 'general', 'bank_charges'],
        ['GW-CHARGES', 'Payment gateway charges', 'X4', 'general', 'gateway_charges'],
        ['DEPRECIATION', 'Depreciation', 'X5', 'general', 'depreciation'],
    ],

    // [name, rate in basis points]
    'gst_rates' => [['GST exempt', 0], ['GST 5%', 500], ['GST 12%', 1200], ['GST 18%', 1800], ['GST 28%', 2800]],

    // [code, name, rate bp, rate without PAN bp, single bill threshold ₹, annual threshold ₹]
    'tds_sections' => [
        ['194C', 'Contractors (individual or HUF)', 100, 2000, 30000, 100000],
        ['194C-O', 'Contractors (company or firm)', 200, 2000, 30000, 100000],
        ['194J', 'Professional or technical fees', 1000, 2000, 0, 50000],
        ['194I', 'Rent of land or building', 1000, 2000, 50000, 0],
        ['194H', 'Commission or brokerage', 200, 2000, 0, 20000],
    ],

    'cost_centres' => [['ADMIN', 'Administration'], ['ACADEMIC', 'Academics'], ['TRANSPORT', 'Transport'], ['HOSTEL', 'Hostel'], ['SPORTS', 'Sports and activities']],
];
