<?php

namespace App\Services\Backups;

use App\Contracts\Backups\RestorePlatform;
use RuntimeException;

class UnavailableRestorePlatform implements RestorePlatform
{
    public function capabilities(): array
    {
        return ['prepare' => false, 'switch' => false, 'reason' => 'يحتاج الاسترجاع إلى ربط إنشاء قاعدة مستقلة وصلاحيات التحويل الآمن على الاستضافة.'];
    }

    public function createScratch(string $jobId): array
    {
        throw new RuntimeException($this->capabilities()['reason']);
    }

    public function switchVerified(string $jobId, array $scratch): array
    {
        throw new RuntimeException($this->capabilities()['reason']);
    }

    public function beginMaintenance(string $jobId): array
    {
        throw new RuntimeException($this->capabilities()['reason']);
    }

    public function finishMaintenance(string $jobId): void
    {
        throw new RuntimeException($this->capabilities()['reason']);
    }

    public function rollbackSwitch(array $rollback): void
    {
        throw new RuntimeException($this->capabilities()['reason']);
    }
}
