<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

class CreateWebhookSiteUrlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:webhook-site';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a webhook.site URL that answers deliveries the way the provider does';

    /**
     * webhook.site's endpoint for creating a URL.
     */
    private const string TOKEN_ENDPOINT = 'https://webhook.site/token';

    /**
     * Execute the console command.
     *
     * The URL answers every request with 202 Accepted and a JSON body; webhook.site
     * fills $request.uuid$ and $request.date$ in per request, so every delivery gets
     * a messageId of its own.
     */
    public function handle(HttpFactory $http): int
    {
        try {
            $response = $http->acceptJson()->asJson()->timeout(15)->post(self::TOKEN_ENDPOINT, [
                'default_status' => 202,
                'default_content_type' => 'application/json',
                'default_content' => json_encode([
                    'messageId' => '$request.uuid$',
                    'status' => 'accepted',
                    'timestamp' => '$request.date$',
                ]),
            ]);
        } catch (ConnectionException) {
            $this->error('webhook.site could not be reached.');

            return self::FAILURE;
        }

        $uuid = $response->json('uuid');

        if (! $response->successful() || ! is_string($uuid) || $uuid === '') {
            $this->error("webhook.site did not create a URL (HTTP {$response->status()}).");

            return self::FAILURE;
        }

        $this->info('Created a webhook.site URL that answers like the provider.');
        $this->newLine();
        $this->line("  Provider URL     https://webhook.site/{$uuid}");
        $this->line("  Inbox            https://webhook.site/#!/view/{$uuid}");
        $this->newLine();
        $this->line('Add it to the .env file next to docker-compose.yml, then apply it:');
        $this->newLine();
        $this->line("  echo 'NOTIFICATIONS_GATEWAY_URL=https://webhook.site/{$uuid}' >> .env");
        $this->line('  docker compose up -d');
        $this->newLine();
        $this->comment('A free webhook.site URL expires after seven days and accepts a limited number of requests;');
        $this->comment('run this command again for a new one, and use the mock provider for load tests.');

        return self::SUCCESS;
    }
}
