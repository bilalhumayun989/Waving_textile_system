<?php

return [
    'modules' => [
        'dashboard' => 'Overview',
        'customers' => 'Customers',
        'invoices' => 'Invoices',
        'receipts' => 'Receive money',
        'cashbook' => 'Cash & bank',
        'employees' => 'Employees',
        'attendance' => 'Attendance',
        'payrolls' => 'Payroll',
        'expenses' => 'Expenses',
        'fixed-expenses' => 'Fixed expenses',
        'gate-passes' => 'Gate passes',
        'reports' => 'Reports',
        'ledgers' => 'Ledgers',
        'settings' => 'Workspace settings',
    ],
    'super_admin_names' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SUPER_ADMIN_NAMES', ''))
    ), fn (string $name): bool => $name !== '')),
];
