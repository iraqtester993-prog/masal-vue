<?php

namespace App\Models;

use Database\Factories\AccountAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountAttachment extends Model
{
    /** @use HasFactory<AccountAttachmentFactory> */
    use HasFactory;

    public const DOCUMENT_LABELS = [
        'civil_id' => 'هوية الأحوال المدنية',
        'national_card' => 'البطاقة الوطنية',
        'residence' => 'بطاقة السكن',
        'shop_license' => 'إجازة أو رخصة المحل',
        'other' => 'مستمـسكات أخرى',
    ];

    protected $fillable = ['account_id', 'kind', 'document_type', 'storage_path', 'mime_type', 'bytes', 'uploaded_by'];

    protected $hidden = ['storage_path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function label(): string
    {
        return match ($this->kind) {
            'agent_image' => 'صورة الوكيل',
            'personal_image' => 'الصورة الشخصية',
            default => self::DOCUMENT_LABELS[$this->document_type] ?? 'مستمـسكات أخرى',
        };
    }
}
