<?php

namespace App\Models\Company;

use Database\Factories\Company\CompanyProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyProfile extends Model
{
    /** @use HasFactory<CompanyProfileFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $fillable = ['id', 'content', 'published', 'version', 'editor_id', 'published_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'published' => 'array', 'version' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
