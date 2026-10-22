<?php

namespace App\Support;

use App\Models\Booking;

/**
 * Ready-made messages that carry a customer's portal link, for the apps
 * Maldivian customers use. Nothing is sent from the server: the office's
 * phone opens the app with the message filled in.
 */
final class CustomerShare
{
    /** @return array{message: string, whatsapp: string, viber: string, telegram: string} */
    public static function for(Booking $booking, string $url): array
    {
        $booking->loadMissing(['customer', 'trip']);
        $company = (string) Settings::get(Settings::COMPANY_NAME);
        $message = "Assalaamu alaikum {$booking->customer->name}. Your {$company} booking {$booking->reference} for {$booking->trip->name}: see the balance, sign the agreement and download your travel letters here: {$url}";
        $phone = ltrim((string) Phone::normalise($booking->customer->phone), '+');

        return [
            'message' => $message,
            'whatsapp' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message),
            'viber' => 'viber://forward?text='.rawurlencode($message),
            'telegram' => 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode(str_replace(': '.$url, '', $message)),
        ];
    }
}
