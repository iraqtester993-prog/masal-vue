<?php

namespace App\Models\Company;

use Database\Factories\Company\CompanyInquiryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyInquiry extends Model
{
    /** @use HasFactory<CompanyInquiryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'contact', 'message', 'status', 'idempotency_key', 'payload_hash', 'version', 'reviewer_id', 'reviewed_at'];

    protected $hidden = ['payload_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'reviewed_at' => 'immutable_datetime'];
    }
}
