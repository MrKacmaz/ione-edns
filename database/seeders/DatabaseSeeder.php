<?php

namespace Database\Seeders;

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->operator()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        NotificationTemplate::factory()->for($user)->create(['name' => 'order-shipped']);
        NotificationTemplate::factory()->for($user)->email()->create(['name' => 'invoice-ready']);

        $token = $user->createToken('demo')->plainTextToken;

        $this->command->info("API token for {$user->email}: {$token}");
        $this->command->info("Dashboard login: {$user->email} / password");
    }
}
