<?php

namespace App\Support;

use Illuminate\Database\UniqueConstraintViolationException;

final class AccountPhone
{
    public const UNIQUE_INDEX = 'accounts_phone_key_unique';

    public static function local(?string $phone): ?string
    {
        $digits = self::digits($phone);

        return preg_match('/^07[78][0-9]{8}$/D', $digits) ? $digits : null;
    }

    public static function key(?string $phone): ?string
    {
        $digits = self::digits($phone);
        $digits = preg_replace('/[\s()+-]/u', '', $digits);
        if ($digits === '') {
            return null;
        }
        $local = preg_replace('/^(?:00964|964|0)/', '', $digits);

        return preg_match('/^7\d{9}$/D', $local) ? '964'.$local : $digits;
    }

    public static function duplicateViolation(UniqueConstraintViolationException $failure): bool
    {
        return str_contains($failure->getMessage(), self::UNIQUE_INDEX)
            || str_contains($failure->getMessage(), 'accounts.phone_key');
    }

    private static function digits(?string $phone): string
    {
        return strtr($phone ?? '', array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
    }
}
