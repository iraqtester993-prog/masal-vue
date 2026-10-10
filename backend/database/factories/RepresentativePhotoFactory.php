<?php

namespace Database\Factories;

use App\Models\RepresentativePhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RepresentativePhoto> */
class RepresentativePhotoFactory extends Factory
{
    public function definition(): array
    {
        return ['representative_id' => NetworkRepresentativeFactory::new(), 'storage_path' => 'representatives/test/'.fake()->uuid().'.png', 'mime_type' => 'image/png', 'bytes' => 100, 'uploaded_by' => UserFactory::new()];
    }
}
