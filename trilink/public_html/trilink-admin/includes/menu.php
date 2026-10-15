<?php
// ============================================================
// Sidebar menu definition — single source of truth for nav +
// permission keys. `super_only` groups are hidden unless the
// logged-in admin's role is super_admin.
// ============================================================
return [
    ['type' => 'single', 'key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'fa-gauge-high', 'url' => 'dashboard.php'],

    ['type' => 'group', 'label' => 'Administrator', 'icon' => 'fa-user-tie', 'items' => [
        ['key' => 'users',        'label' => 'Users (Passengers)', 'icon' => 'fa-users',                 'url' => 'modules/users/index.php'],
        ['key' => 'drivers',      'label' => 'Drivers',            'icon' => 'fa-motorcycle',             'url' => 'modules/drivers/index.php'],
        ['key' => 'agents',       'label' => 'Agents',             'icon' => 'fa-user-tag',               'url' => 'modules/agents/index.php'],
        ['key' => 'merchants',    'label' => 'Merchants',          'icon' => 'fa-shop',                   'url' => 'modules/merchants/index.php'],
        ['key' => 'trips',        'label' => 'Trips',              'icon' => 'fa-route',                  'url' => 'modules/trips/index.php'],
        ['key' => 'deliveries',   'label' => 'Deliveries',         'icon' => 'fa-box',                    'url' => 'modules/deliveries/index.php'],
        ['key' => 'orders',       'label' => 'Merchant Orders',    'icon' => 'fa-cart-shopping',          'url' => 'modules/orders/index.php'],
        ['key' => 'transactions', 'label' => 'Transactions',       'icon' => 'fa-money-bill-transfer',    'url' => 'modules/transactions/index.php'],
        ['key' => 'topups',       'label' => 'Wallet Top-Ups',     'icon' => 'fa-qrcode',                 'url' => 'modules/topups/index.php'],
        ['key' => 'payouts',      'label' => 'Payouts',            'icon' => 'fa-hand-holding-dollar',    'url' => 'modules/payouts/index.php'],
        ['key' => 'promos',       'label' => 'Promotions',         'icon' => 'fa-tags',                   'url' => 'modules/promos/index.php'],
        ['key' => 'banners',      'label' => 'Banners (Home Ads)', 'icon' => 'fa-images',                 'url' => 'modules/banners/index.php'],
        ['key' => 'support',      'label' => 'Support Tickets',    'icon' => 'fa-headset',                'url' => 'modules/support/index.php'],
        ['key' => 'fare',         'label' => 'Fare Matrix',        'icon' => 'fa-calculator',              'url' => 'modules/fare/index.php'],
        ['key' => 'settings',     'label' => 'App Settings',       'icon' => 'fa-gear',                   'url' => 'modules/settings/index.php'],
    ]],

    ['type' => 'group', 'label' => 'CRM', 'icon' => 'fa-address-card', 'items' => [
        ['key' => 'crm_leads',      'label' => 'Leads',      'icon' => 'fa-user-plus',      'url' => 'crm/leads/index.php'],
        ['key' => 'crm_contacts',   'label' => 'Contacts',   'icon' => 'fa-address-book',   'url' => 'crm/contacts/index.php'],
        ['key' => 'crm_accounts',   'label' => 'Accounts',   'icon' => 'fa-building',       'url' => 'crm/accounts/index.php'],
        ['key' => 'crm_deals',      'label' => 'Deals',      'icon' => 'fa-handshake',      'url' => 'crm/deals/index.php'],
        ['key' => 'crm_activities', 'label' => 'Activities', 'icon' => 'fa-phone-volume',   'url' => 'crm/activities/index.php'],
        ['key' => 'crm_tasks',      'label' => 'Tasks',      'icon' => 'fa-list-check',     'url' => 'crm/tasks/index.php'],
        ['key' => 'crm_campaigns',  'label' => 'Campaigns',  'icon' => 'fa-bullhorn',       'url' => 'crm/campaigns/index.php'],
        ['key' => 'crm_reports',    'label' => 'Reports',    'icon' => 'fa-chart-pie',      'url' => 'crm/reports/index.php'],
    ]],

    ['type' => 'group', 'label' => 'ERP / SAP', 'icon' => 'fa-industry', 'items' => [
        ['key' => 'erp_financial',   'label' => 'Financial Management', 'icon' => 'fa-sack-dollar',            'url' => 'erp/financial/index.php'],
        ['key' => 'erp_sales',       'label' => 'Sales Management',     'icon' => 'fa-chart-line',             'url' => 'erp/sales/index.php'],
        ['key' => 'erp_purchasing',  'label' => 'Purchasing',           'icon' => 'fa-cart-flatbed',           'url' => 'erp/purchasing/index.php'],
        ['key' => 'erp_inventory',   'label' => 'Inventory Management', 'icon' => 'fa-boxes-stacked',          'url' => 'erp/inventory/index.php'],
        ['key' => 'erp_hr',          'label' => 'Human Resources',      'icon' => 'fa-id-badge',               'url' => 'erp/hr/index.php'],
        ['key' => 'erp_payroll',     'label' => 'Payroll',              'icon' => 'fa-money-check-dollar',     'url' => 'erp/payroll/index.php'],
        ['key' => 'erp_projects',    'label' => 'Projects',             'icon' => 'fa-diagram-project',        'url' => 'erp/projects/index.php'],
        ['key' => 'erp_assets',      'label' => 'Assets Management',    'icon' => 'fa-warehouse',              'url' => 'erp/assets/index.php'],
        ['key' => 'erp_maintenance', 'label' => 'Fleet Maintenance',    'icon' => 'fa-screwdriver-wrench',     'url' => 'erp/maintenance/index.php'],
        ['key' => 'erp_reports',     'label' => 'Reports & Analytics',  'icon' => 'fa-chart-column',           'url' => 'erp/reports/index.php'],
    ]],

    ['type' => 'group', 'label' => 'Super Admin', 'icon' => 'fa-shield-halved', 'super_only' => true, 'items' => [
        ['key' => 'sa_admins',   'label' => 'Admins & Roles',   'icon' => 'fa-user-shield',        'url' => 'superadmin/admins/index.php'],
        ['key' => 'sa_logs',     'label' => 'Activity Logs',    'icon' => 'fa-clock-rotate-left',  'url' => 'superadmin/logs/index.php'],
        ['key' => 'sa_health',   'label' => 'System Health',    'icon' => 'fa-heart-pulse',        'url' => 'superadmin/health/index.php'],
        ['key' => 'sa_settings','label' => 'System Settings',   'icon' => 'fa-sliders',            'url' => 'superadmin/settings/index.php'],
    ]],
];
