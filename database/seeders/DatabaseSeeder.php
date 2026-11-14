<?php

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const string OPERATOR_EMAIL = 'test@example.com';

    private const string OPERATOR_PASSWORD = 'password';

    /**
     * Seed the demo operator, its templates and an API token.
     *
     * Safe to run again: the operator and the templates are only created when
     * missing, so the Docker init step seeds on every start. It uses no
     * factories, because the production image ships without Faker.
     */
    public function run(): void
    {
        $user = User::query()->firstOrNew(['email' => self::OPERATOR_EMAIL]);

        if (! $user->exists) {
            $user->forceFill([
                'name' => 'Test User',
                'password' => self::OPERATOR_PASSWORD,
                'email_verified_at' => now(),
            ]);
        }

        $user->forceFill(['is_operator' => true])->save();

        $user->notificationTemplates()->firstOrCreate(['name' => 'order-shipped'], [
            'channel' => NotificationChannel::Sms,
            'content' => 'Hi {{ name }}, your order {{ order_id }} has shipped.',
        ]);

        $user->notificationTemplates()->firstOrCreate(['name' => 'invoice-ready'], [
            'channel' => NotificationChannel::Email,
            'content' => "Hello {{ name }},\n\nYour invoice {{ invoice_id }} is ready.",
        ]);

        $token = $user->createToken('demo')->plainTextToken;

        $this->command->info("API token for {$user->email}: {$token}");
        $this->command->info('Dashboard login: '.self::OPERATOR_EMAIL.' / '.self::OPERATOR_PASSWORD);
    }
}
