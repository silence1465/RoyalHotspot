<?php

return [
    'key_hash' => env('MIKROTIK_ADMIN_SECURITY_KEY_HASH'),
    'unlock_minutes' => (int) env('MIKROTIK_ADMIN_UNLOCK_MINUTES', 15),
    'sliding' => filter_var(env('MIKROTIK_ADMIN_UNLOCK_SLIDING', false), FILTER_VALIDATE_BOOL),
    'allowed_address_lists' => array_values(array_filter(array_map('trim', explode(',', env('MIKROTIK_ALLOWED_ADDRESS_LISTS', 'royal-hotspot-allow,royal-hotspot-block'))))),
    'protected_interfaces' => array_values(array_filter(array_map('trim', explode(',', env('MIKROTIK_PROTECTED_INTERFACES', 'ether1,wan,wireguard1,wg0'))))),
];
