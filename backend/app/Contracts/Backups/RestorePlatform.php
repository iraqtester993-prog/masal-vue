<?php

namespace App\Contracts\Backups;

interface RestorePlatform
{
    /** @return array{prepare: bool, switch: bool, reason: ?string} */
    public function capabilities(): array;

    /** @return array{identifier: string, connection: array<string, mixed>} */
    public function createScratch(string $jobId): array;

    /** @return array{rollback: array<string, mixed>} */
    public function beginMaintenance(string $jobId): array;

    public function finishMaintenance(string $jobId): void;

    /** @param array{identifier: string, connection: array<string, mixed>} $scratch
     * @return array{rollback: array<string, mixed>}
     */
    public function switchVerified(string $jobId, array $scratch): array;

    /** @param array<string, mixed> $rollback */
    public function rollbackSwitch(array $rollback): void;
}
