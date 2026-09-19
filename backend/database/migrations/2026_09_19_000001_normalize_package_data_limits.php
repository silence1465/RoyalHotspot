<?php

use App\Support\DataLimit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('internet_packages')
            ->whereNotNull('data_limit')
            ->orderBy('id')
            ->chunkById(100, function ($packages) {
                foreach ($packages as $package) {
                    try {
                        $bytes = DataLimit::toBytes($package->data_limit);
                    } catch (InvalidArgumentException) {
                        continue;
                    }

                    if (! $bytes) {
                        continue;
                    }

                    DB::table('internet_packages')->where('id', $package->id)->update([
                        'usage_policy' => $package->usage_policy === 'none' ? 'data_cap' : $package->usage_policy,
                        'data_allowance_bytes' => $package->data_allowance_bytes ?: $bytes,
                    ]);

                    DB::table('purchases')
                        ->where('package_id', $package->id)
                        ->where('usage_policy', 'none')
                        ->update([
                            'usage_policy' => 'data_cap',
                            'fup_period' => 'cycle',
                            'data_allowance_bytes' => $bytes,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally non-destructive: we cannot distinguish legacy limits
        // from data-cap policies an administrator saved after this migration.
    }
};
