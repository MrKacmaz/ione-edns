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

    private const string DEMO_TOKEN_NAME = 'demo';

    /**
     * How long a demo API token works.
     */
    private const int DEMO_TOKEN_DAYS = 7;

    /**
     * Seed the demo operator, its templates and an API token.
     *
     * Safe to run again: the operator and the templates are only created when
     * missing, so the Docker init step seeds on every start. It uses no
     * factories, because the production image ships without Faker.
     *
     * Every run prints a new demo token, which expires after a week; expired ones
     * are deleted. In production no token is created or printed, as the output
     * ends up in the logs.
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

        $this->command->info('Dashboard login: '.self::OPERATOR_EMAIL.' / '.self::OPERATOR_PASSWORD);

        if (app()->isProduction()) {
            return;
        }

        $user->tokens()->where('name', self::DEMO_TOKEN_NAME)->where('expires_at', '<', now())->delete();

        $token = $user->createToken(self::DEMO_TOKEN_NAME, ['*'], now()->addDays(self::DEMO_TOKEN_DAYS))->plainTextToken;

        $this->command->info("API token for {$user->email} (valid for ".self::DEMO_TOKEN_DAYS." days): {$token}");
    }
}
