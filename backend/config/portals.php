<?php

return [
    // Exact hostnames, never a wildcard. Headers are accepted only in local/testing.
    'hosts' => [
        'admin' => env('PORTAL_ADMIN_HOST', 'admin.localhost'),
        'agents' => env('PORTAL_AGENTS_HOST', 'agents.localhost'),
        'pos' => env('PORTAL_POS_HOST', 'pos.localhost'),
    ],
    'public_hosts' => [env('PUBLIC_SITE_HOST', 'dananir-iq.com'), env('PUBLIC_SITE_WWW_HOST', 'www.dananir-iq.com')],
];
