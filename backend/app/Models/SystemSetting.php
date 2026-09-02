<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class SystemSetting extends Model
{
    private const SECRET_KEYS = ['telegram_bot_token'];

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        static::saved(fn ($setting) => Cache::forget("system_setting:{$setting->key}"));
        static::deleted(fn ($setting) => Cache::forget("system_setting:{$setting->key}"));
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever("system_setting:{$key}", function () use ($key, $default) {
            $value = static::where('key', $key)->value('value');
            if ($value === null) return $default;

            if (in_array($key, self::SECRET_KEYS, true) && str_starts_with($value, 'encrypted:')) {
                return Crypt::decryptString(substr($value, 10));
            }

            return $value;
        });
    }

    public static function set(string $key, mixed $value): void
    {
        if (in_array($key, self::SECRET_KEYS, true) && filled($value)) {
            $value = 'encrypted:'.Crypt::encryptString((string) $value);
        }
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
