<?php

namespace App\Services\SelfService;

use App\Models\Agreement;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentWallet;
use Illuminate\Support\Facades\DB;

/** Signs a booking's agreement: on screen by the customer, or by a scan of a paper copy from either side. */
final class Agreements
{
    /** Largest drawn signature accepted, as a data URI. */
    private const MAX_SIGNATURE_BYTES = 300_000;

    public function __construct(private readonly DocumentWallet $wallet) {}

    public function signOnScreen(Agreement $agreement, string $name, string $signature, ?string $ip, ?string $userAgent): void
    {
        if ($agreement->isSigned()) {
            throw new \InvalidArgumentException('This agreement is already signed.');
        }

        $name = trim($name);

        if (mb_strlen($name) < 3) {
            throw new \InvalidArgumentException('Type your full name as you sign.');
        }

        if (! str_starts_with($signature, 'data:image/png;base64,') || strlen($signature) > self::MAX_SIGNATURE_BYTES
            || ($png = base64_decode(substr($signature, 22), true)) === false || ! str_starts_with($png, "\x89PNG")) {
            throw new \InvalidArgumentException('Draw your signature in the box, then sign.');
        }

        $agreement->refreshWording();
        $agreement->forceFill([
            'method' => Agreement::DIGITAL,
            'signed_name' => mb_substr($name, 0, 160),
            'signature' => $signature,
            'signed_at' => now(),
            'signed_ip' => $ip,
            'signed_user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
        ])->save();
    }

    /** A signed paper copy, from the customer's portal or the office. Stored with the booking's other papers. */
    public function recordScan(Agreement $agreement, string $bytes, string $filename, string $mime, ?User $by = null): void
    {
        DB::transaction(function () use ($agreement, $bytes, $filename, $mime, $by): void {
            $booking = $agreement->booking;
            $this->wallet->store($booking, $bytes, $filename, $mime, Document::AGREEMENT, $booking->trip_id);
            $agreement->refreshWording();
            $agreement->forceFill([
                'method' => Agreement::SCAN,
                'signed_name' => null,
                'signature' => null,
                'signed_at' => now(),
                'recorded_by' => $by?->getKey(),
            ])->save();
        });
    }
}
