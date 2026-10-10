<?php

namespace Database\Factories\Company;

use App\Models\Company\CompanyInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyInquiry> */
class CompanyInquiryFactory extends Factory
{
    protected $model = CompanyInquiry::class;

    public function definition(): array
    {
        return ['name' => fake()->name(), 'contact' => fake()->email(), 'message' => fake()->paragraph(), 'status' => 'new', 'idempotency_key' => fake()->uuid(), 'payload_hash' => hash('sha256', fake()->uuid()), 'version' => 1];
    }
}
