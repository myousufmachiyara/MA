<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ChartOfAccounts;
use App\Models\SubHeadOfAccounts;
use Illuminate\Support\Facades\DB;

class ImportCustomers extends Command
{
    protected $signature = 'customers:import
        {file : Path to the CSV file}
        {--shoa=48 : Sub Head of Account ID to file all customers under}
        {--user=1 : User ID to set as created_by/updated_by}
        {--dry-run : Preview without actually inserting}';

    protected $description = 'Bulk import customers into Chart of Accounts from a CSV file (Name, Address, Area, Sub Area)';

    public function handle()
    {
        $path = $this->argument('file');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }

        $shoaId = (int) $this->option('shoa');
        $subHead = SubHeadOfAccounts::find($shoaId);

        if (!$subHead) {
            $this->error("Sub Head of Account #{$shoaId} not found.");
            return 1;
        }

        $userId = (int) $this->option('user');
        $dryRun = $this->option('dry-run');

        // Same account_code prefix logic used by COAController::store()
        $prefix = $subHead->hoa_id . str_pad($subHead->id, 2, '0', STR_PAD_LEFT);

        $lastCode = ChartOfAccounts::withTrashed()
            ->where('account_code', 'like', $prefix . '%')
            ->max('account_code');

        $nextNumber = $lastCode ? (intval(substr($lastCode, strlen($prefix))) + 1) : 1;

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle); // skip header row

        $imported = 0;
        $skipped  = 0;
        $skippedNames = [];

        $this->info($dryRun ? 'DRY RUN — no records will be saved.' : 'Importing...');
        $bar = $this->output->createProgressBar();
        $bar->start();

        DB::beginTransaction();

        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) < 1 || trim($row[0]) === '') {
                    continue;
                }

                $name    = trim($row[0] ?? '');
                $address = trim($row[1] ?? '');
                $area    = trim($row[2] ?? '');
                $subArea = trim($row[3] ?? '');

                // Combine Address + Area + Sub Area into one text field, skipping empty parts
                $fullAddress = implode(', ', array_filter([$address, $area, $subArea], fn ($v) => $v !== ''));

                $exists = ChartOfAccounts::where('name', $name)->whereNull('deleted_at')->exists();
                if ($exists) {
                    $skipped++;
                    $skippedNames[] = $name;
                    $bar->advance();
                    continue;
                }

                $accountCode = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

                if (!$dryRun) {
                    ChartOfAccounts::create([
                        'shoa_id'      => $shoaId,
                        'account_code' => $accountCode,
                        'name'         => $name,
                        'account_type' => 'customer',
                        'address'      => $fullAddress,
                        'receivables'  => 0,
                        'payables'     => 0,
                        'credit_limit' => 0,
                        'opening_date' => now(),
                        'is_active'    => true,
                        'is_reviewed'  => true, // web/office import — no mobile-review flag needed
                        'created_by'   => $userId,
                        'updated_by'   => $userId,
                    ]);
                }

                $nextNumber++;
                $imported++;
                $bar->advance();
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

        } catch (\Exception $e) {
            DB::rollBack();
            $bar->finish();
            $this->newLine(2);
            $this->error('Import failed: ' . $e->getMessage());
            fclose($handle);
            return 1;
        }

        fclose($handle);
        $bar->finish();
        $this->newLine(2);

        $this->info("Imported: {$imported}");
        $this->warn("Skipped (duplicate name): {$skipped}");

        if ($skipped > 0 && $this->confirm('Show skipped names?', false)) {
            foreach ($skippedNames as $n) {
                $this->line(" - {$n}");
            }
        }

        return 0;
    }
}