<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SMS body parsing patterns
    |--------------------------------------------------------------------------
    |
    | MoMo payment SMS text is provider-specific free text (MTN MoMo,
    | Vodafone Cash, AirtelTigo Money all format differently) and will
    | almost certainly need tuning against real received SMS samples —
    | these are reasonable starting patterns, not guaranteed to match
    | your actual provider's exact wording. This file is the one place
    | to adjust them.
    |
    */

    // Our own generated order references always look like "RW-XXXXXX" —
    // matched directly rather than via a generic "reference" keyword
    // search, since this exact format is unambiguous wherever it appears
    // in the SMS text.
    'reference_pattern' => '/\bRW-[A-Z0-9]{4,}\b/i',

    // Generic transaction/trans ID label, e.g. "Trans ID: 123456789" or
    // "Transaction ID:123456789ABC".
    'transaction_id_pattern' => '/(?:trans(?:action)?\s*id|txn\s*id)\s*[:\-]?\s*([A-Za-z0-9]{4,})/i',

    // GHS amount, e.g. "GHS30.00", "GH₵ 100.00", "GHS 30".
    'amount_pattern' => '/GH[₵C]?S?\.?\s*([\d,]+(?:\.\d{1,2})?)/iu',

    // Ghana local mobile number format (0XXXXXXXXX).
    'phone_pattern' => '/\b(0\d{9})\b/',

];
