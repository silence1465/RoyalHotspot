<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetMikrotikSecurityKey extends Command
{
    protected $signature = 'mikrotik:security-key';
    protected $description = 'Configure the hashed Router Management security key';

    public function handle(): int
    {
        $key = (string) $this->secret('New Router Management security key');
        if (mb_strlen($key) < 12) return $this->configurationFailed('Use at least 12 characters.');
        if (! hash_equals($key, (string) $this->secret('Confirm security key'))) return $this->configurationFailed('The keys do not match.');

        $path = app()->environmentFilePath();
        $contents = file_get_contents($path);
        if ($contents === false) return $this->configurationFailed('Could not read the environment file.');
        $line = 'MIKROTIK_ADMIN_SECURITY_KEY_HASH='.Hash::make($key).'';
        $pattern = '/^MIKROTIK_ADMIN_SECURITY_KEY_HASH=.*$/m';
        $updated = preg_match($pattern, $contents) ? preg_replace($pattern, $line, $contents) : rtrim($contents).PHP_EOL.PHP_EOL.$line.PHP_EOL;
        if (file_put_contents($path, $updated, LOCK_EX) === false) return $this->configurationFailed('Could not update the environment file.');
        $this->callSilent('config:clear');
        $this->info('Security key configured. Only its secure hash was stored.');
        return self::SUCCESS;
    }

    private function configurationFailed(string $message): int
    {
        $this->error($message);
        return self::FAILURE;
    }
}
