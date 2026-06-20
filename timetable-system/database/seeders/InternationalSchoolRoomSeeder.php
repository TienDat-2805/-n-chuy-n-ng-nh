<?php

namespace Database\Seeders;

use App\Services\DefaultRoomService;
use Illuminate\Database\Seeder;

class InternationalSchoolRoomSeeder extends Seeder
{
    public function run(): void
    {
        app(DefaultRoomService::class)->seed();
    }
}
