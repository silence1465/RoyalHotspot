<?php

namespace App\Services;

class CheckoutFeeService
{
    public function calculate(string|float|int $subtotal, string $paymentMethod = 'momo'): array
    {
        $normalizedSubtotal = number_format((float) $subtotal, 2, '.', '');
        $fee = $paymentMethod === 'paystack'
            ? number_format(round((float) $normalizedSubtotal * 0.02, 2), 2, '.', '')
            : '0.00';

        return [
            'subtotal' => $normalizedSubtotal,
            'payment_fee' => $fee,
            'total' => bcadd($normalizedSubtotal, $fee, 2),
        ];
    }
}
