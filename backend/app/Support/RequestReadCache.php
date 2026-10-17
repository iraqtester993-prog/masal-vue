<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;

/** Reuses GET reads, or selected authorization reads inside a locked write phase. */
class RequestReadCache
{
    public static function withinLockedAuthorization(Closure $work): mixed
    {
        abort_unless(DB::transactionLevel() > 0, 500, 'Authorization cache requires a locked transaction.');
        $request = request();
        if (! $request->isMethod('POST')) {
            return $work();
        }
        $attribute = 'masal.locked_authorization_cache';
        $present = $request->attributes->has($attribute);
        $previous = $request->attributes->get($attribute);
        $request->attributes->set($attribute, []);
        try {
            return $work();
        } finally {
            $request->attributes->remove($attribute);
            if ($present) {
                $request->attributes->set($attribute, $previous);
            }
        }
    }

    public static function remember(string $key, Closure $resolve): mixed
    {
        $request = request();
        $attribute = 'masal.read_cache';
        if ($request->isMethod('POST') && $request->attributes->has('masal.locked_authorization_cache') && DB::transactionLevel() > 0
            && (str_starts_with($key, 'Account.isOperational:') || str_starts_with($key, 'AccountMembership.permissions:')
                || str_starts_with($key, 'AccountMembership.scopeRoots:') || str_starts_with($key, 'CatalogAccess.context:'))) {
            $attribute = 'masal.locked_authorization_cache';
        } elseif (! $request->isMethod('GET') || ! $request->attributes->has($attribute)) {
            return $resolve();
        }
        $values = $request->attributes->get($attribute);
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        $value = $resolve();
        $values = $request->attributes->get($attribute);
        $values[$key] = $value;
        $request->attributes->set($attribute, $values);

        return $value;
    }
}
