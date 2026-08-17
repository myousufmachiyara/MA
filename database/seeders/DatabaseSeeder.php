<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\HeadOfAccounts;
use App\Models\SubHeadOfAccounts;
use App\Models\ChartOfAccounts;
use App\Models\Location;
use App\Models\SystemAccountMapping;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\MeasurementUnit;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now    = now();
        $userId = 1;

        // ── 1. Users & Roles ─────────────────────────────────────────
        $admin = User::firstOrCreate(
            ['username' => 'mubashir'],
            ['name' => 'Mubashir', 'email' => null, 'password' => Hash::make('12345678')]
        );
        $superAdmin = Role::firstOrCreate(['name' => 'superadmin']);
        $admin->assignRole($superAdmin);

        $yousuf = User::firstOrCreate(
            ['username' => 'yousuf'],
            ['name' => 'Yousuf', 'email' => null, 'password' => Hash::make('12345678')]
        );
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $yousuf->assignRole($adminRole);

        // ── 2. Permissions — every module, in build sequence ──────────
        $modules = [
            // User Management
            'user_roles', 'role_permissions', 'users', 'mobile_users',

            // Accounts
            'coa', 'shoa', 'account_mappings',

            // Products
            'products', 'product_categories', 'product_subcategories', 'attributes',

            // Stock
            'locations', 'stock_transfer', 'stock_adjustments', 'stock_movements',

            // Purchase
            'purchase_orders', 'purchase_invoices', 'purchase_return',

            // Sale
            'sale_orders', 'dispatch_trips', 'sale_invoices', 'settlements',
            'sale_return', 'sale_adjustment_notes',

            // Party ledgers / advances
            'advance_payments',

            // Vouchers
            'vouchers',
        ];

        $actions = ['index', 'create', 'edit', 'delete', 'print'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate(['name' => "$module.$action"]);
            }
        }

        // ── 3. Report permissions ──────────────────────────────────────
        $reports = ['inventory', 'purchase', 'sales', 'accounts'];
        foreach ($reports as $report) {
            Permission::firstOrCreate(['name' => "reports.$report"]);
        }

        $superAdmin->syncPermissions(Permission::all());
        $adminRole->syncPermissions(Permission::all());

        // ── 4. Chart of Accounts structure ─────────────────────────────
        HeadOfAccounts::insert([
            ['id' => 1, 'name' => 'Assets',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Liabilities', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Equity',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Revenue',     'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Expenses',    'created_at' => $now, 'updated_at' => $now],
        ]);

        SubHeadOfAccounts::insert([
            ['id' =>  1, 'hoa_id' => 1, 'name' => 'Cash',                'created_at' => $now, 'updated_at' => $now],
            ['id' =>  2, 'hoa_id' => 1, 'name' => 'Bank',                'created_at' => $now, 'updated_at' => $now],
            ['id' =>  3, 'hoa_id' => 1, 'name' => 'Accounts Receivable', 'created_at' => $now, 'updated_at' => $now],
            ['id' =>  4, 'hoa_id' => 1, 'name' => 'Inventory',           'created_at' => $now, 'updated_at' => $now],
            ['id' =>  5, 'hoa_id' => 2, 'name' => 'Accounts Payable',    'created_at' => $now, 'updated_at' => $now],
            ['id' =>  6, 'hoa_id' => 2, 'name' => 'Loans Payable',       'created_at' => $now, 'updated_at' => $now],
            ['id' =>  7, 'hoa_id' => 3, 'name' => 'Owner Capital',       'created_at' => $now, 'updated_at' => $now],
            ['id' =>  8, 'hoa_id' => 4, 'name' => 'Sales',               'created_at' => $now, 'updated_at' => $now],
            ['id' =>  9, 'hoa_id' => 4, 'name' => 'Other Income',        'created_at' => $now, 'updated_at' => $now],
            ['id' => 10, 'hoa_id' => 5, 'name' => 'Cost of Goods Sold',  'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'hoa_id' => 5, 'name' => 'Salaries',            'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'hoa_id' => 5, 'name' => 'Rent',                'created_at' => $now, 'updated_at' => $now],
            ['id' => 13, 'hoa_id' => 5, 'name' => 'Utilities',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 14, 'hoa_id' => 5, 'name' => 'Other Expenses',      'created_at' => $now, 'updated_at' => $now],
        ]);

        $coaData = [
            ['id' =>  1, 'account_code' => '101001', 'shoa_id' =>  1, 'name' => 'Cash in Hand',          'account_type' => 'cash'],
            ['id' =>  2, 'account_code' => '102001', 'shoa_id' =>  2, 'name' => 'Main Bank Account',     'account_type' => 'bank'],
            ['id' =>  3, 'account_code' => '104001', 'shoa_id' =>  4, 'name' => 'Stock in Hand',         'account_type' => 'inventory'],
            ['id' =>  4, 'account_code' => '201001', 'shoa_id' =>  5, 'name' => 'Accounts Payable',      'account_type' => 'liability'],
            ['id' =>  5, 'account_code' => '202001', 'shoa_id' =>  6, 'name' => 'Loan Payable',          'account_type' => 'liability'],
            ['id' =>  6, 'account_code' => '301001', 'shoa_id' =>  7, 'name' => 'Owner Capital',         'account_type' => 'equity'],
            ['id' =>  7, 'account_code' => '302001', 'shoa_id' =>  7, 'name' => 'Owner Drawings',        'account_type' => 'equity'],
            ['id' =>  8, 'account_code' => '303001', 'shoa_id' =>  7, 'name' => 'Retained Earnings',     'account_type' => 'equity'],
            ['id' =>  9, 'account_code' => '401001', 'shoa_id' =>  8, 'name' => 'Sales Revenue',         'account_type' => 'revenue'],
            ['id' => 10, 'account_code' => '402001', 'shoa_id' =>  9, 'name' => 'Other Income',          'account_type' => 'revenue'],
            ['id' => 11, 'account_code' => '501001', 'shoa_id' => 10, 'name' => 'Cost of Goods Sold',    'account_type' => 'cogs'],
            ['id' => 12, 'account_code' => '502001', 'shoa_id' => 11, 'name' => 'Salaries Expense',      'account_type' => 'expenses'],
            ['id' => 13, 'account_code' => '503001', 'shoa_id' => 12, 'name' => 'Rent Expense',          'account_type' => 'expenses'],
            ['id' => 14, 'account_code' => '504001', 'shoa_id' => 13, 'name' => 'Utilities Expense',     'account_type' => 'expenses'],
            ['id' => 15, 'account_code' => '505001', 'shoa_id' => 14, 'name' => 'Miscellaneous Expense', 'account_type' => 'expenses'],
            ['id' => 16, 'account_code' => '203001', 'shoa_id' =>  5, 'name' => 'GST Payable (Output Tax)', 'account_type' => 'liability'],
            ['id' => 17, 'account_code' => '105001', 'shoa_id' =>  4, 'name' => 'WHT Receivable',           'account_type' => 'receivable'],
        ];

        foreach ($coaData as $data) {
            ChartOfAccounts::create(array_merge($data, [
                'receivables' => 0, 'payables' => 0, 'credit_limit' => 0,
                'opening_date' => now(), 'is_active' => true, 'is_reviewed' => true,
                'created_by' => $userId, 'updated_by' => $userId,
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }

        // ── 5. Measurement Units ────────────────────────────────────────
        MeasurementUnit::insert([
            ['id' => 1, 'name' => 'Kilogram', 'shortcode' => 'kg'],
            ['id' => 2, 'name' => 'Meter',    'shortcode' => 'm'],
            ['id' => 3, 'name' => 'Pieces',   'shortcode' => 'pcs'],
            ['id' => 4, 'name' => 'Bag',      'shortcode' => 'bag'],
            ['id' => 5, 'name' => 'Bundle',   'shortcode' => 'bundle'],
        ]);

        // ── 6. Attributes (product variations) ──────────────────────────
        $sizeAttribute = Attribute::firstOrCreate(
            ['name' => 'Size'],
            ['slug' => Str::slug('Size')]
        );
        foreach (['300ml', '500ml', '1000ml', '1500ml', '2250ml', '250ml'] as $value) {
            AttributeValue::firstOrCreate([
                'attribute_id' => $sizeAttribute->id,
                'value'        => $value,
            ]);
        }

        // ── 7. Default Location (StockService fallback) ─────────────────
        Location::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name' => 'Main Warehouse', 'is_default' => true, 'is_active' => true,
                'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
            ]
        );

        // ── 8. System Account Mapping (points at the COA rows above) ────
        $roles = [
            ['key' => 'inventory',          'label' => 'Inventory / Stock in Hand',       'description' => 'Asset account moved on every Purchase, Sale, Stock Adjustment, and Return.', 'default_code' => '104001'],
            ['key' => 'sales_revenue',      'label' => 'Sales Revenue',                   'description' => 'Credited when a Sale Invoice is generated.', 'default_code' => '401001'],
            ['key' => 'cogs',               'label' => 'Cost of Goods Sold',              'description' => 'Debited at time of sale to record item cost.', 'default_code' => '501001'],
            ['key' => 'gst_payable',        'label' => 'GST Payable (Output Tax)',        'description' => 'Liability credited when GST is charged on a sale.', 'default_code' => '203001'],
            ['key' => 'wht_receivable',     'label' => 'WHT Receivable',                  'description' => 'Asset debited when tax is withheld by a customer at settlement.', 'default_code' => '105001'],
            ['key' => 'cash',               'label' => 'Cash in Hand',                    'description' => 'Used when cash is physically cleared to the office.', 'default_code' => '101001'],
            ['key' => 'other_income',       'label' => 'Other Income',                    'description' => 'Credited for unexplained stock gains during Stock Adjustment.', 'default_code' => '402001'],
            ['key' => 'write_off_expense',  'label' => 'Miscellaneous / Write-off Expense', 'description' => 'Debited for stock loss, damage, or theft.', 'default_code' => '505001'],
        ];

        foreach ($roles as $role) {
            $account = ChartOfAccounts::where('account_code', $role['default_code'])->first();
            SystemAccountMapping::firstOrCreate(
                ['key' => $role['key']],
                ['label' => $role['label'], 'description' => $role['description'], 'account_id' => $account->id ?? null]
            );
        }
    }
}