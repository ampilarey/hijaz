<?php

namespace App\Services\Operations;

use App\Models\BookingPilgrim;
use App\Models\Card;
use App\Models\Document;
use App\Models\Pilgrim;
use App\Services\Invoices\Documents;
use App\Support\Settings;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;

/**
 * Customer cards and luggage tags: issued once per traveller and kind,
 * printed as many times as needed, each carrying a QR with a random token
 * that the Scan page turns back into the traveller.
 */
final class Cards
{
    public function __construct(private readonly Documents $documents) {}

    public function issue(BookingPilgrim $bp, string $kind): Card
    {
        $existing = $bp->cards()->active()->where('kind', $kind)->first();

        if ($existing instanceof Card) {
            return $existing;
        }

        return $bp->cards()->create(['kind' => $kind]);
    }

    /** Lost or wrong: the old token stops working and a fresh card is issued. */
    public function reissue(BookingPilgrim $bp, string $kind): Card
    {
        $bp->cards()->active()->where('kind', $kind)->get()->each(fn (Card $c) => $c->forceFill(['revoked_at' => now()])->save());

        return $this->issue($bp, $kind);
    }

    public function resolve(string $tokenOrSerial): ?Card
    {
        $value = trim($tokenOrSerial);

        if ($value === '') {
            return null;
        }

        if (preg_match('#[?&]t=([A-Za-z0-9]+)#', $value, $m)) {
            $value = $m[1];
        }

        return Card::query()->active()->where('qr_token', $value)->orWhere(fn ($q) => $q->active()->where('serial', strtoupper($value)))->with('bookingPilgrim')->first();
    }

    /**
     * A sheet of cards or tags for these travellers, issuing any that do not
     * exist yet and counting the print.
     *
     * @param  Collection<int, BookingPilgrim>  $travellers
     */
    public function sheet(Collection $travellers, string $kind): PdfDocument
    {
        $items = [];

        foreach ($travellers as $bp) {
            $bp->loadMissing(['pilgrim.documents.versions', 'booking.trip.hotels', 'booking.customer', 'busGroup', 'roomAssignments.room']);
            $card = $this->issue($bp, $kind);
            $card->increment('printed_count');

            $items[] = [
                'card' => $card,
                'bp' => $bp,
                'pilgrim' => $bp->pilgrim,
                'trip' => $bp->booking->trip,
                'qr' => $this->qrDataUri($card->url()),
                'photo' => $kind === Card::CUSTOMER ? $this->photoDataUri($bp->pilgrim) : null,
                'rooms' => $bp->booking->trip->hotels->map(fn ($h) => ['city' => $h->cityLabel(), 'room' => $bp->roomIn($h)?->label])->all(),
            ];
        }

        $view = $kind === Card::LUGGAGE ? 'pdf.luggage-tags' : 'pdf.cards';

        return Pdf::loadView($view, [
            'items' => $items,
            'issuer' => $this->documents->issuer(),
            'phones' => (string) Settings::get(Settings::COMPANY_PHONES),
        ])->setPaper('a4', 'portrait');
    }

    public function qrDataUri(string $contents): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(240, 0), new SvgImageBackEnd)))->writeString($contents);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** The verified-or-pending photo as a data URI dompdf can draw, or null. */
    public function photoDataUri(Pilgrim $pilgrim): ?string
    {
        $version = $pilgrim->document(Document::PHOTO)?->currentVersion();

        if ($version === null || ! in_array($version->mime_type, ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        $bytes = $version->contents();

        return $bytes === null ? null : 'data:'.$version->mime_type.';base64,'.base64_encode($bytes);
    }
}
