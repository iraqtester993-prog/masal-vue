<?php

namespace App\Models\Company;

use Database\Factories\Company\CompanyAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyAsset extends Model
{
    /** @use HasFactory<CompanyAssetFactory> */
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $fillable = ['id', 'user_id', 'path', 'mime', 'bytes', 'width', 'height'];

    protected $hidden = ['path', 'user_id'];
}
