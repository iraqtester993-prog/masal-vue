<?php

namespace App\Support;

final class AccountPhone
{
    public static function key(?string $phone): ?string
    {
        $digits = strtr($phone ?? '', array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $digits = preg_replace('/[\s()+-]/u', '', $digits);
        if ($digits === '') {
            return null;
        }
        $local = preg_replace('/^(?:00964|964|0)/', '', $digits);

        return preg_match('/^7\d{9}$/D', $local) ? '964'.$local : $digits;
    }
}
