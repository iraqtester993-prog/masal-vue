<?php

namespace App\Models\Digital;

use Database\Factories\Digital\CatalogSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CatalogSnapshot extends Model
{
    /** @use HasFactory<CatalogSnapshotFactory> */
    use HasFactory;

    protected $table = 'digital_catalog_snapshots';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['catalog' => 'array', 'bein_provinces' => 'array', 'created_at' => 'datetime'];
    }

    public function getExcludedCatalogAttribute(): ?array
    {
        $value = DB::table('digital_catalog_exclusions')->where('snapshot_id', $this->id)->value('excluded_catalog');

        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}
