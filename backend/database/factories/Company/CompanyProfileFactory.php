<?php

namespace Database\Factories\Company;

use App\Models\Company\CompanyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyProfile> */
class CompanyProfileFactory extends Factory
{
    protected $model = CompanyProfile::class;

    public function definition(): array
    {
        return ['id' => fake()->unique()->numberBetween(2, 1000000), 'content' => ['name' => fake()->company(), 'tagline' => '', 'about' => '', 'phone' => '', 'email' => '', 'address' => '', 'website' => '', 'whatsapp' => '', 'hours' => '', 'logo' => null, 'slides' => [], 'activities' => [], 'offers' => [], 'projects' => [], 'social' => [], 'visibility' => array_fill_keys(['slides', 'about', 'activities', 'offers', 'projects', 'social', 'care'], true)], 'version' => 1];
    }
}
