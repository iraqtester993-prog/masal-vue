<?php

namespace App\Services\Digital;

class ProviderRejection extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('لم يقبل خادم الشركة الطلب.');
    }
}
