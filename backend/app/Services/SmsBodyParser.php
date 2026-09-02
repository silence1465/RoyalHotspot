<?php

namespace App\Services;

class SmsBodyParser
{
    /**
     * Returns ['transaction_id' => ?string, 'amount' => ?float,
     * 'phone' => ?string, 'reference' => ?string] — any field can be
     * null if the pattern didn't match. Never throws on unexpected text;
     * a fully-null result just means the SMS gets stored as unmatched
     * for a human to look at (see PaymentMatchingService).
     */
    public function parse(string $body): array
    {
        return [
            'transaction_id' => $this->firstMatch(config('smspayment.transaction_id_pattern'), $body),
            'amount' => $this->parseAmount($body),
            'phone' => $this->firstMatch(config('smspayment.phone_pattern'), $body),
            'reference' => $this->firstMatch(config('smspayment.reference_pattern'), $body),
        ];
    }

    protected function firstMatch(string $pattern, string $body): ?string
    {
        return preg_match($pattern, $body, $m) ? ($m[1] ?? $m[0]) : null;
    }

    protected function parseAmount(string $body): ?float
    {
        $match = $this->firstMatch(config('smspayment.amount_pattern'), $body);

        if ($match === null) {
            return null;
        }

        return (float) str_replace(',', '', $match);
    }
}
