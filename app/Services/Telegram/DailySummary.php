<?php

namespace App\Services\Telegram;

use App\Models\User;

/**
 * The morning message. Each phase adds a section (slips to verify, documents
 * to accept, passports expiring); today it says the portal is up and what
 * the person has access to.
 */
class DailySummary
{
    public function for(User $user): string
    {
        $lines = [
            '<b>'.e(now()->translatedFormat('l j F')).'</b>',
            'Good morning, '.e($user->name).'.',
            '',
            'Nothing is waiting for you yet. Trips and pilgrims arrive in the next phases.',
            '',
            'Portal: '.e(rtrim((string) config('app.url'), '/').'/portal'),
        ];

        return implode("\n", $lines);
    }
}
