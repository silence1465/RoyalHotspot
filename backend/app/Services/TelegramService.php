<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deliberately no new Composer package — Telegram's Bot API is a plain
 * HTTPS POST, and Guzzle (via Laravel's Http facade) is already a
 * dependency. Bot token + chat ID are admin-configured in Settings
 * (System tab), stored via the existing SystemSetting key-value store.
 */
class TelegramService
{
    public function send(string $message): bool
    {
        $token = SystemSetting::get('telegram_bot_token');
        $chatId = SystemSetting::get('telegram_chat_id');

        if (! $token || ! $chatId) {
            return false;
        }

        try {
            $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("Telegram notification failed: {$e->getMessage()}");
            return false;
        }
    }
}
