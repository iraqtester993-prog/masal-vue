<?php

return [
    'gateway_url' => env('DIGITAL_GATEWAY_URL'),
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('DIGITAL_GATEWAY_ALLOWED_HOSTS', ''))))),
    'contract_verified' => env('DIGITAL_GATEWAY_CONTRACT_VERIFIED', false),
    'purchases_enabled' => env('DIGITAL_PURCHASES_ENABLED', false),
    'contract' => 'masal-normalized-v1',
    'rabiaa' => [
        'driver' => env('DIGITAL_RABIAA_DRIVER', 'nojoom_v2_1'),
        'url' => env('DIGITAL_RABIAA_URL', 'https://tryapi.nojoomalrabiaa.com/api/v2'),
        'allowed_hosts' => ['tryapi.nojoomalrabiaa.com'],
        'contract' => 'Nojoom AL Rabiaa Vendor API 2.1.0',
    ],
    'topup' => [
        'driver' => env('DIGITAL_TOPUP_DRIVER', 'masal_v2_1'),
        'url' => env('DIGITAL_TOPUP_URL', 'https://masal-co.com'),
        'allowed_hosts' => ['masal-co.com'],
        'contract' => 'Masal API Documentation V2.1',
    ],
];
