<?php

namespace App\Services\Delivery\Channels;

use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\DeliveryChannel;

final class SmsChannel implements DeliveryChannel
{
    /**
     * The characters of a single SMS in the GSM-7 alphabet.
     */
    private const int MAX_GSM7_LENGTH = 160;

    /**
     * The UTF-16 code units of a single SMS in UCS-2, which any other character needs.
     */
    private const int MAX_UCS2_LENGTH = 70;

    /**
     * The basic character set of the GSM 03.38 alphabet; each takes one septet.
     */
    private const string GSM7_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?"
        .'¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /**
     * The extension table of the GSM 03.38 alphabet; each takes two septets (escape and character).
     */
    private const string GSM7_EXTENSION = "\f^{}\\[~]|€";

    /**
     * An E.164 phone number: a plus sign and up to 15 digits without a leading zero.
     */
    private const string E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    /**
     * Get the length of the content in one SMS: septets in GSM-7, UTF-16 code units in UCS-2.
     *
     * In UCS-2 a character outside the Basic Multilingual Plane, such as most emoji,
     * takes two code units.
     */
    public function contentLength(string $content): int
    {
        $septets = self::gsm7Septets($content);

        return $septets ?? intdiv(strlen((string) mb_convert_encoding($content, 'UTF-16BE', 'UTF-8')), 2);
    }

    /**
     * Get the limit of a single SMS: 160 in GSM-7, or 70 once the content needs UCS-2,
     * because one character, such as a Turkish ş or an emoji, is outside the GSM-7 alphabet.
     */
    public function maxContentLength(string $content): int
    {
        return self::gsm7Septets($content) === null ? self::MAX_UCS2_LENGTH : self::MAX_GSM7_LENGTH;
    }

    public function isValidRecipient(string $recipient): bool
    {
        return preg_match(self::E164_PATTERN, $recipient) === 1;
    }

    public function recipientFormat(): string
    {
        return __('notifications.recipient_formats.sms');
    }

    /**
     * Count the septets of the content in the GSM-7 alphabet, or null when it needs UCS-2.
     */
    private static function gsm7Septets(string $content): ?int
    {
        /** @var array<string, int>|null $septetsByCharacter */
        static $septetsByCharacter = null;

        $septetsByCharacter ??= [
            ...array_fill_keys(mb_str_split(self::GSM7_BASIC), 1),
            ...array_fill_keys(mb_str_split(self::GSM7_EXTENSION), 2),
        ];

        $septets = 0;

        foreach (mb_str_split($content) as $character) {
            if (! isset($septetsByCharacter[$character])) {
                return null;
            }

            $septets += $septetsByCharacter[$character];
        }

        return $septets;
    }
}
