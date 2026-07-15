<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\HeadOfAccounts;
use App\Models\SubHeadOfAccounts;
use App\Models\ChartOfAccounts;
use App\Models\Location;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\MeasurementUnit;
use App\Models\ProductCategory;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductSubcategory;
use App\Models\Product;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $now = now();
        $userId = 1; // ID for created_by / updated_by

        // 🔑 Create Super Admin User
        $admin = User::firstOrCreate(
            ['username' => 'mubashir'],
            [
                'name' => 'Mubashir',
                'email' => null,
                'password' => Hash::make('12345678'),
            ]
        );

        $superAdmin = Role::firstOrCreate(['name' => 'superadmin']);
        $admin->assignRole($superAdmin);

        // 🔑 Create Admin User — Yousuf
        $yousuf = User::firstOrCreate(
            ['username' => 'yousuf'],
            [
                'name'     => 'Yousuf',
                'email'    => null,
                'password' => Hash::make('12345678'),
            ]
        );

        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $yousuf->assignRole($adminRole);

        // 📌 Functional Modules (CRUD-style permissions)
        $modules = [
            // User Management
            'user_roles',
            'users',
            'mobile_users',

            // Accounts
            'coa',
            'shoa',

            // Products
            'products',
            'product_categories',
            'product_subcategories',
            'attributes',

            // Purchases
            'purchase_orders',
            'purchase_invoices',
            'purchase_return',

            // Sales
            'sale_orders',      // ← was missing — blocks Sale Order pages for everyone
            'dispatch_trips',   // ← was missing — blocks Dispatch Trip pages for everyone
            'sale_invoices',
            'settlements',      // ← was missing — blocks Settlement pages for everyone
            'sale_return',

            // Stock
            'stock_adjustments', // ← was missing — blocks Stock Adjustment pages for everyone
            'stock_movements',   // ← needed for the Stock In/Out viewer's permission check
            'locations',         // ← new, this request
            'stock_transfer',    // ← new, this request

            // Vouchers
            'vouchers',
        ];

        $actions = ['index', 'create', 'edit', 'delete', 'print'];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                Permission::firstOrCreate([
                    'name' => "$module.$action",
                ]);
            }
        }

        // 📊 Report permissions
        $reports = ['inventory', 'purchase', 'sales', 'accounts'];

        foreach ($reports as $report) {
            Permission::firstOrCreate([
                'name' => "reports.$report",
            ]);
        }

        // Assign ALL permissions to both Superadmin and Admin
        $superAdmin->syncPermissions(Permission::all());
        $adminRole->syncPermissions(Permission::all());

       // ─────────────────────────────────────────
        // HEADS OF ACCOUNTS  (5 standard heads)
        // ─────────────────────────────────────────
        HeadOfAccounts::insert([
            ['id' => 1, 'name' => 'Assets',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Liabilities', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Equity',      'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Revenue',     'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Expenses',    'created_at' => $now, 'updated_at' => $now],
        ]);

        // ─────────────────────────────────────────
        // SUB HEADS
        // Convention: hoa_id prefix tells which head
        // ─────────────────────────────────────────
        SubHeadOfAccounts::insert([
            // Assets (head 1)
            ['id' =>  1, 'hoa_id' => 1, 'name' => 'Cash',               'created_at' => $now, 'updated_at' => $now],
            ['id' =>  2, 'hoa_id' => 1, 'name' => 'Bank',               'created_at' => $now, 'updated_at' => $now],
            ['id' =>  3, 'hoa_id' => 1, 'name' => 'Accounts Receivable','created_at' => $now, 'updated_at' => $now],
            ['id' =>  4, 'hoa_id' => 1, 'name' => 'Inventory',          'created_at' => $now, 'updated_at' => $now],

            // Liabilities (head 2)
            ['id' =>  5, 'hoa_id' => 2, 'name' => 'Accounts Payable',   'created_at' => $now, 'updated_at' => $now],
            ['id' =>  6, 'hoa_id' => 2, 'name' => 'Loans Payable',      'created_at' => $now, 'updated_at' => $now],

            // Equity (head 3)
            ['id' =>  7, 'hoa_id' => 3, 'name' => 'Owner Capital',      'created_at' => $now, 'updated_at' => $now],

            // Revenue (head 4)
            ['id' =>  8, 'hoa_id' => 4, 'name' => 'Sales',              'created_at' => $now, 'updated_at' => $now],
            ['id' =>  9, 'hoa_id' => 4, 'name' => 'Other Income',       'created_at' => $now, 'updated_at' => $now],

            // Expenses (head 5)
            ['id' => 10, 'hoa_id' => 5, 'name' => 'Cost of Goods Sold', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'hoa_id' => 5, 'name' => 'Salaries',          'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'hoa_id' => 5, 'name' => 'Rent',              'created_at' => $now, 'updated_at' => $now],
            ['id' => 13, 'hoa_id' => 5, 'name' => 'Utilities',         'created_at' => $now, 'updated_at' => $now],
            ['id' => 14, 'hoa_id' => 5, 'name' => 'Other Expenses',    'created_at' => $now, 'updated_at' => $now],

            ['id' => 15, 'hoa_id' => 2, 'name' => 'Duties & Taxes Payable', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 16, 'hoa_id' => 1, 'name' => 'Tax Recoverable',        'created_at' => $now, 'updated_at' => $now],
        ]);

        // ─────────────────────────────────────────
        // CHART OF ACCOUNTS
        // ─────────────────────────────────────────
        $coaData = [

            // ── ASSETS ──────────────────────────────────────────────
            ['id' =>  1, 'account_code' => '101001', 'shoa_id' =>  1, 'name' => 'Cash in Hand',         'account_type' => 'cash',      'receivables' => 0, 'payables' => 0],
            ['id' =>  2, 'account_code' => '102001', 'shoa_id' =>  2, 'name' => 'Main Bank Account',    'account_type' => 'bank',      'receivables' => 0, 'payables' => 0],
            ['id' =>  3, 'account_code' => '104001', 'shoa_id' =>  4, 'name' => 'Stock in Hand',        'account_type' => 'inventory', 'receivables' => 0, 'payables' => 0],

            // ── LIABILITIES ─────────────────────────────────────────
            ['id' =>  4, 'account_code' => '201001', 'shoa_id' =>  5, 'name' => 'Accounts Payable',     'account_type' => 'liability', 'receivables' => 0, 'payables' => 0],
            ['id' =>  5, 'account_code' => '202001', 'shoa_id' =>  6, 'name' => 'Loan Payable',         'account_type' => 'liability', 'receivables' => 0, 'payables' => 0],

            // ── EQUITY ──────────────────────────────────────────────
            ['id' =>  6, 'account_code' => '301001', 'shoa_id' =>  7, 'name' => 'Owner Capital',        'account_type' => 'equity',    'receivables' => 0, 'payables' => 0],
            ['id' =>  7, 'account_code' => '302001', 'shoa_id' =>  7, 'name' => 'Owner Drawings',       'account_type' => 'equity',    'receivables' => 0, 'payables' => 0],
            ['id' =>  8, 'account_code' => '303001', 'shoa_id' =>  7, 'name' => 'Retained Earnings',    'account_type' => 'equity',    'receivables' => 0, 'payables' => 0],

            // ── REVENUE ─────────────────────────────────────────────
            ['id' =>  9, 'account_code' => '401001', 'shoa_id' =>  8, 'name' => 'Sales Revenue',        'account_type' => 'revenue',   'receivables' => 0, 'payables' => 0],
            ['id' => 10, 'account_code' => '402001', 'shoa_id' =>  9, 'name' => 'Other Income',         'account_type' => 'revenue',   'receivables' => 0, 'payables' => 0],

            // ── EXPENSES ────────────────────────────────────────────
            ['id' => 11, 'account_code' => '501001', 'shoa_id' => 10, 'name' => 'Cost of Goods Sold',   'account_type' => 'cogs',      'receivables' => 0, 'payables' => 0],
            ['id' => 12, 'account_code' => '502001', 'shoa_id' => 11, 'name' => 'Salaries Expense',     'account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],
            ['id' => 13, 'account_code' => '503001', 'shoa_id' => 12, 'name' => 'Rent Expense',         'account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],
            ['id' => 14, 'account_code' => '504001', 'shoa_id' => 13, 'name' => 'Utilities Expense',    'account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],
            ['id' => 15, 'account_code' => '505001', 'shoa_id' => 14, 'name' => 'Miscellaneous Expense','account_type' => 'expenses',  'receivables' => 0, 'payables' => 0],

            // ── ACCOUNTS ADDED FOR SALE MODULE (GST / WHT) ───────────
            ['id' => 16, 'account_code' => '203001', 'shoa_id' =>  5, 'name' => 'GST Payable (Output Tax)', 'account_type' => 'liability',  'receivables' => 0, 'payables' => 0],
            ['id' => 17, 'account_code' => '105001', 'shoa_id' =>  4, 'name' => 'WHT Receivable',           'account_type' => 'receivable', 'receivables' => 0, 'payables' => 0],
        ];

        foreach ($coaData as $data) {
            ChartOfAccounts::create(array_merge($data, [
                'credit_limit' => 0,
                'opening_date' => now(),
                'created_by'   => $userId,
                'updated_by'   => $userId,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]));
        }

        // 📏 Measurement Units
        MeasurementUnit::insert([
            ['id' => 1, 'name' => 'Kilogram', 'shortcode' => 'kg'],
            ['id' => 2, 'name' => 'Meter',    'shortcode' => 'm'],
            ['id' => 3, 'name' => 'Pieces',   'shortcode' => 'pcs'],
            ['id' => 4, 'name' => 'Bag',      'shortcode' => 'bag'],
            ['id' => 5, 'name' => 'Bundle',   'shortcode' => 'bundle'],
            ['id' => 6, 'name' => 'Cartons',  'shortcode' => 'cartons'],
            ['id' => 7, 'name' => 'Cases',    'shortcode' => 'cases'],
        ]);

        // 🏬 Default Location — StockService falls back to this for every
        // stock movement that doesn't specify a location explicitly.
        Location::firstOrCreate(
            ['code' => 'MAIN'],
            [
                'name'       => 'Main Warehouse',
                'is_default' => true,
                'is_active'  => true,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }
}