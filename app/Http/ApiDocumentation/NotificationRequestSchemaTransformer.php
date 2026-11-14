<?php

namespace App\Http\ApiDocumentation;

use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Requests\StoreNotificationTemplateRequest;
use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Combined\AnyOf;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\NumberType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

/**
 * Corrects the request schemas Scramble derives from the notification validation rules.
 * (While the document is built, its schemas are keyed by the request's class name.)
 *
 * - `variables` is validated with the `array` rule, which Scramble reads as a list of
 *   strings; it is an object mapping variable names to text or number values.
 * - The `distinct` rule on `notifications.*.idempotency_key` makes Scramble mark the
 *   whole `notifications` array as `uniqueItems`, while only the keys must differ.
 * - The create requests get realistic examples instead of generated placeholder values.
 */
final class NotificationRequestSchemaTransformer implements DocumentTransformer
{
    private const array NOTIFICATION_EXAMPLE = [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your order has shipped.',
        'priority' => 'high',
    ];

    private const array EMAIL_NOTIFICATION_EXAMPLE = [
        'channel' => 'email',
        'recipient' => 'customer@example.com',
        'content' => 'Your invoice is ready.',
        'priority' => 'low',
    ];

    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $single = $document->components->schemas[StoreNotificationRequest::class]->type ?? null;

        if ($single instanceof ObjectType) {
            $this->describeVariables($single);
            $single->examples([self::NOTIFICATION_EXAMPLE]);
        }

        $batch = $document->components->schemas[StoreNotificationBatchRequest::class]->type ?? null;
        $notifications = $batch instanceof ObjectType && $batch->hasProperty('notifications')
            ? $batch->getProperty('notifications')
            : null;

        if ($batch instanceof ObjectType) {
            $batch->examples([['notifications' => [self::NOTIFICATION_EXAMPLE, self::EMAIL_NOTIFICATION_EXAMPLE]]]);
        }

        $template = $document->components->schemas[StoreNotificationTemplateRequest::class]->type ?? null;

        if ($template instanceof ObjectType) {
            $template->examples([[
                'name' => 'order-shipped',
                'channel' => 'sms',
                'content' => 'Hi {{ name }}, your order {{ order_id }} has shipped.',
            ]]);
        }

        if ($notifications instanceof ArrayType) {
            $notifications->uniqueItems = null;

            if ($notifications->items instanceof ObjectType) {
                $this->describeVariables($notifications->items);
            }
        }
    }

    private function describeVariables(ObjectType $notification): void
    {
        if (! $notification->hasProperty('variables')) {
            return;
        }

        $values = (new AnyOf)->setItems([new StringType, new IntegerType, new NumberType]);

        $notification->addProperty('variables', (new ObjectType)
            ->additionalProperties($values)
            ->setDescription('The values of the template\'s placeholders, by variable name. Only with `template`.'));
    }
}
