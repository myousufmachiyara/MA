<?php
// app/Services/SystemAccountService.php

namespace App\Services;

use App\Models\SystemAccountMapping;
use App\Models\ChartOfAccounts;

class SystemAccountService
{
    private static array $cache = [];

    public static function get(string $key): ChartOfAccounts
    {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $mapping = SystemAccountMapping::where('key', $key)->first();

        if (!$mapping || !$mapping->account_id) {
            throw new \Exception("System account \"{$key}\" is not configured. Go to Settings → Account Mapping to set it up.");
        }

        $account = ChartOfAccounts::find($mapping->account_id);
        if (!$account) {
            throw new \Exception("Mapped account for \"{$key}\" no longer exists. Please reconfigure in Settings → Account Mapping.");
        }

        return self::$cache[$key] = $account;
    }

    public static function inventory(): ChartOfAccounts { return self::get('inventory'); }
    public static function salesRevenue(): ChartOfAccounts { return self::get('sales_revenue'); }
    public static function cogs(): ChartOfAccounts { return self::get('cogs'); }
    public static function gstPayable(): ChartOfAccounts { return self::get('gst_payable'); }
    public static function whtReceivable(): ChartOfAccounts { return self::get('wht_receivable'); }
    public static function cash(): ChartOfAccounts { return self::get('cash'); }
    public static function otherIncome(): ChartOfAccounts { return self::get('other_income'); }
    public static function writeOffExpense(): ChartOfAccounts { return self::get('write_off_expense'); }
}