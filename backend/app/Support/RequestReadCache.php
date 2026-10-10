<?php

namespace App\Support;

use Closure;

/** Reuses authorization reads only within one authenticated GET request. */
class RequestReadCache
{
    public static function remember(string $key, Closure $resolve): mixed
    {
        $request = request();
        if (! $request->isMethod('GET') || ! $request->attributes->has('masal.read_cache')) {
            return $resolve();
        }
        $values = $request->attributes->get('masal.read_cache');
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        $value = $resolve();
        $values = $request->attributes->get('masal.read_cache');
        $values[$key] = $value;
        $request->attributes->set('masal.read_cache', $values);

        return $value;
    }
}
