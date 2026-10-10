<?php

namespace Database\Factories\Company;

use App\Models\Company\CompanyAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyAsset> */
class CompanyAssetFactory extends Factory
{
    protected $model = CompanyAsset::class;

    public function definition(): array
    {
        $id = fake()->uuid();

        return ['id' => $id, 'user_id' => User::factory(), 'path' => 'company/assets/'.$id.'.png', 'mime' => 'image/png', 'bytes' => 100, 'width' => 1, 'height' => 1];
    }
}
