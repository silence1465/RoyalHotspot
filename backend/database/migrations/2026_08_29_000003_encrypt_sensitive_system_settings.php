<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $setting = DB::table('system_settings')->where('key', 'telegram_bot_token')->first();
        if ($setting && filled($setting->value) && ! str_starts_with($setting->value, 'encrypted:')) {
            DB::table('system_settings')->where('key', 'telegram_bot_token')->update([
                'value' => 'encrypted:'.Crypt::encryptString($setting->value),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Secrets deliberately remain encrypted on rollback.
    }
};
