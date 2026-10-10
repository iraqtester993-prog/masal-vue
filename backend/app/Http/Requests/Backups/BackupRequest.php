<?php

namespace App\Http\Requests\Backups;

use App\Services\Backups\BackupAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(BackupAccess::class)->require($this->user(), $this->routeIs('backups.create') ? 'backup.create' : 'backup.restore');

        return true;
    }

    public function rules(): array
    {
        if ($this->routeIs('backups.preview')) {
            return ['file' => ['required_without:backup_id', Rule::prohibitedIf($this->filled('backup_id')), 'file', 'max:15360'], 'backup_id' => ['required_without:file', Rule::prohibitedIf($this->hasFile('file')), 'uuid']];
        }
        if ($this->routeIs('backups.restore')) {
            return ['preview_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'], 'current_password' => ['required', 'string', 'max:255']];
        }

        return [];
    }
}
