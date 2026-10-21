<?php

namespace App\Filament\Support;

use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\Booking;
use App\Models\Document;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\TakesPayments;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Services\Payments\Ledger;
use App\Services\Payments\SlipIntake;
use App\Services\Payments\SlipMatcher;
use App\Support\Currency;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Recording and reviewing money, the same way from a booking's page, an
 * additional trip's, or the payments queue. Everything goes through the
 * Ledger; these are the screens in front of it.
 */
final class PaymentActions
{
    /**
     * Record money that arrived for one payable, with its slip. The closure
     * is handed the table row when there is one, so the same action serves
     * a page header (ignore it, return the owner) and a table row (return it).
     */
    public static function record(\Closure $payable, string $label = 'Record a payment'): Action
    {
        return Action::make('record_payment')
            ->label($label)
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('payment.create'))
            ->modalWidth('2xl')
            ->fillForm(function (?Model $record) use ($payable): array {
                /** @var TakesPayments&Model $p */
                $p = $payable($record);

                return ['method' => Payment::BANK_TRANSFER, 'currency' => self::defaultCurrency($p), 'paid_on' => today()->toDateString()];
            })
            ->schema(fn (): array => self::recordSchema())
            ->action(function (array $data, ?Model $record, Ledger $ledger, DocumentWallet $wallet, ReaderManager $readers, SlipMatcher $matcher) use ($payable): void {
                /** @var TakesPayments&Model $p */
                $p = $payable($record);

                try {
                    $payment = $ledger->record($p, Money::ofMinor((int) $data['amount_minor'], $data['currency']), $data['method'], array_filter([
                        'paid_on' => $data['paid_on'] ?? null,
                        'account_id' => $data['account_id'] ?? null,
                        'payer_name' => $data['payer_name'] ?? null,
                        'payer_bank' => $data['payer_bank'] ?? null,
                        'payer_reference' => $data['payer_reference'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]));
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $warnings = [];
                $slip = $data['slip'] ?? null;
                $slip = is_array($slip) ? reset($slip) : $slip;

                if ($slip instanceof TemporaryUploadedFile) {
                    $warnings = self::attachSlip($payment, (string) file_get_contents($slip->getRealPath()), $slip->getClientOriginalName(), (string) $slip->getMimeType(), $wallet, $readers, $matcher);
                }

                Notification::make()->success()->title($payment->reference.' recorded')->body($payment->money()->format().' waits for Finance to verify it.')->send();

                if ($warnings !== []) {
                    Notification::make()->warning()->title('Check the slip')->body(implode(' ', $warnings))->persistent()->send();
                }
            });
    }

    /**
     * Start from the slip: read it, work out whose it is, record it. For the
     * payments queue, where a slip arrives by Viber before anyone has opened
     * the booking.
     */
    public static function fromSlip(): Action
    {
        return Action::make('from_slip')
            ->label('Upload a slip')
            ->icon('heroicon-o-camera')
            ->color('gray')
            ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('payment.create'))
            ->schema([
                FileUpload::make('slip')->label('Bank slip')->required()
                    ->acceptedFileTypes(config('documents.mime_types'))
                    ->maxSize((int) config('documents.max_kilobytes'))
                    ->storeFiles(false)
                    ->extraInputAttributes(['capture' => 'environment']),
                Select::make('booking_id')->label('Booking, if you know it')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => self::bookingSearch($search))
                    ->getOptionLabelUsing(fn ($value): ?string => Booking::query()->find($value)?->label),
                TextInput::make('amount')->label('Amount, if the slip is hard to read')->numeric()->step(0.01),
                Select::make('currency')->options(Currency::options())->default(Currency::MVR)->native(false),
            ])
            ->action(function (array $data, SlipIntake $intake): void {
                $slip = $data['slip'];
                $slip = is_array($slip) ? reset($slip) : $slip;
                /** @var TemporaryUploadedFile $slip */
                $payable = filled($data['booking_id'] ?? null) ? Booking::query()->find($data['booking_id']) : null;
                $typed = filled($data['amount'] ?? null) && Currency::isValid((string) ($data['currency'] ?? '')) ? Money::ofMajor((string) $data['amount'], (string) $data['currency']) : null;

                $result = $intake->record($payable, (string) file_get_contents($slip->getRealPath()), $slip->getClientOriginalName(), (string) $slip->getMimeType(), $typed);

                if ($result['payment'] === null) {
                    Notification::make()->warning()->title($result['problem'] ?? 'Could not record the slip')
                        ->body($result['payable'] === null ? 'Open the booking and record the payment from there, or pick the booking here.' : 'Type the amount and the currency and try again.')
                        ->persistent()->send();

                    return;
                }

                $payment = $result['payment'];
                Notification::make()->success()->title($payment->reference.' recorded for '.$result['payable']->payableLabel())
                    ->body($payment->money()->format().($result['reason'] ? ' · matched by the '.$result['reason'] : '').'. Finance verifies it next.')
                    ->send();

                if ($result['warnings'] !== []) {
                    Notification::make()->warning()->title('Check the slip')->body(implode(' ', $result['warnings']))->persistent()->send();
                }
            });
    }

    /** @return list<Action> */
    public static function forRecord(): array
    {
        return [
            Action::make('verify')
                ->label('Verify')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (Payment $record): bool => in_array($record->status, [Payment::PENDING, Payment::AWAITING_REVIEW], true))
                ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('payment.verify'))
                ->modalHeading(fn (Payment $record): string => 'Verify '.$record->reference.' · '.$record->money()->format())
                ->modalWidth('4xl')
                ->fillForm(fn (Payment $record): array => ['allocations' => self::defaultAllocations($record)])
                ->schema(fn (Payment $record): array => self::verifySchema($record))
                ->action(function (Payment $record, array $data, Ledger $ledger): void {
                    $rows = array_values(array_filter(array_map(fn (array $r): array => ['currency' => (string) ($r['currency'] ?? ''), 'amount_minor' => (int) ($r['amount_minor'] ?? 0)], $data['allocations'] ?? []), fn (array $r): bool => $r['amount_minor'] > 0 && $r['currency'] !== ''));

                    if ($rows === []) {
                        Notification::make()->danger()->title('Say which balance this settles')->body('At least one line with an amount is needed.')->send();

                        return;
                    }

                    try {
                        $ledger->verify($record, $rows, $data['note'] ?? null);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $payable = $record->fresh()->payable;
                    Notification::make()->success()->title('Verified')->body($payable instanceof Booking ? $payable->balanceSummary() : ($payable?->payableLabel() ?? ''))->send();
                }),

            Action::make('refuse')
                ->label('Not received')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Payment $record): bool => ! $record->isTerminal())
                ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('payment.verify'))
                ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)->placeholder('Nothing arrived in the account / the slip is not ours')])
                ->action(function (Payment $record, array $data, Ledger $ledger): void {
                    $ledger->refuse($record, $data['reason']);
                    Notification::make()->warning()->title('Marked as not received')->send();
                }),

            Action::make('cancel')
                ->label('Cancel entry')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->visible(fn (Payment $record): bool => ! $record->isTerminal() && (Filament::auth()->user()?->can('payment.verify') || $record->recorded_by === Filament::auth()->id()))
                ->requiresConfirmation()
                ->modalDescription('For an entry made by mistake. The row stays, marked cancelled.')
                ->schema([Textarea::make('reason')->label('Why')->required()->rows(2)])
                ->action(function (Payment $record, array $data, Ledger $ledger): void {
                    $ledger->cancel($record, $data['reason']);
                    Notification::make()->success()->title('Cancelled')->send();
                }),

            Action::make('refund')
                ->label('Refund')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (Payment $record): bool => $record->status === Payment::SUCCEEDED && ! $record->isRefund() && self::refundable($record) > 0)
                ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('payment.refund'))
                ->fillForm(fn (Payment $record): array => ['currency' => $record->currency, 'amount_minor' => self::refundable($record)])
                ->schema([
                    Select::make('currency')->options(Currency::options())->disabled()->dehydrated()->native(false),
                    MoneyInput::make('amount_minor', 'currency', 'Amount to refund'),
                    Textarea::make('reason')->required()->rows(2),
                ])
                ->action(function (Payment $record, array $data, Ledger $ledger): void {
                    try {
                        $refund = $ledger->refund($record, Money::ofMinor((int) $data['amount_minor'], $record->currency), $data['reason']);
                    } catch (\InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title($refund->reference.' refunded')->send();
                }),

            Action::make('receipt')
                ->label('Receipt')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (Payment $record): bool => $record->status === Payment::SUCCEEDED)
                ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('payment.download'))
                ->url(fn (Payment $record): string => route('payments.receipt', $record), shouldOpenInNewTab: true),

            Action::make('slip')
                ->label('Slip')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->visible(fn (Payment $record): bool => $record->slip()?->currentVersion() !== null)
                ->url(fn (Payment $record, DocumentWallet $wallet): string => $wallet->downloadUrl($record->slip()->currentVersion()), shouldOpenInNewTab: true),
        ];
    }

    /** @return list<Component> */
    private static function recordSchema(): array
    {
        return [
            Select::make('method')->options(Payment::METHODS)->required()->native(false)->live(),
            Select::make('currency')->options(Currency::options())->required()->native(false)->live(),
            MoneyInput::make('amount_minor', 'currency', 'Amount'),
            DatePicker::make('paid_on')->label('Paid on')->required()->native(false)->maxDate(today()),
            Select::make('account_id')->label('Into which account')
                ->options(fn (Get $get): array => Account::query()->where('is_active', true)
                    ->where('kind', $get('method') === Payment::CASH ? Account::CASH : Account::BANK)
                    ->orderBy('sort_order')->get()->mapWithKeys(fn (Account $a): array => [$a->id => $a->name.' ('.$a->currency.')'])->all())
                ->native(false),
            TextInput::make('payer_reference')->label('Bank reference')->maxLength(80)->visible(fn (Get $get): bool => $get('method') === Payment::BANK_TRANSFER),
            TextInput::make('payer_name')->label('Paid by')->maxLength(120),
            TextInput::make('payer_bank')->label('From which bank')->maxLength(60)->visible(fn (Get $get): bool => $get('method') === Payment::BANK_TRANSFER),
            FileUpload::make('slip')->label('Slip (photo or PDF)')
                ->acceptedFileTypes(config('documents.mime_types'))
                ->maxSize((int) config('documents.max_kilobytes'))
                ->storeFiles(false)
                ->extraInputAttributes(['capture' => 'environment'])
                ->visible(fn (Get $get): bool => $get('method') === Payment::BANK_TRANSFER)
                ->columnSpanFull(),
            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ];
    }

    /** @return list<Component> */
    private static function verifySchema(Payment $record): array
    {
        $payable = $record->payable;
        $notes = [];
        $slipVersion = $record->slip()?->currentVersion();
        $x = $record->slip()?->latestExtraction();

        if ($x !== null && ($x->fields ?? []) !== []) {
            $f = $x->fields;
            $notes[] = 'The slip reads: '.implode(' · ', array_filter([
                isset($f['amount']) ? strtoupper((string) ($f['currency'] ?? '')).' '.$f['amount'] : null,
                isset($f['reference']) ? 'ref '.$f['reference'] : null,
                isset($f['paid_on']) ? 'dated '.$f['paid_on'] : null,
                isset($f['payee_account']) ? 'into '.$f['payee_account'] : null,
                isset($f['remarks']) ? '"'.$f['remarks'].'"' : null,
            ]));

            if (isset($f['amount'], $f['currency'])) {
                try {
                    $read = Money::ofMajor((string) $f['amount'], strtoupper((string) $f['currency']));

                    if ($read->currency !== $record->currency || $read->minor !== $record->amount_minor) {
                        $notes[] = 'FAILED to match: the slip reads '.$read->format().' but '.$record->money()->format().' was recorded.';
                    }
                } catch (\Throwable) {
                }
            }

            foreach (app(SlipMatcher::class)->propose($f)['warnings'] as $w) {
                $notes[] = 'FAILED: '.$w;
            }
        } elseif ($record->method === Payment::BANK_TRANSFER && $slipVersion === null) {
            $notes[] = 'No slip was uploaded. Check the bank statement before verifying.';
        }

        if ($payable instanceof Booking) {
            $notes[] = 'Booking: '.$payable->balanceSummary().'.';
        } elseif ($payable instanceof ExcursionBooking) {
            $notes[] = 'Additional trip: '.$payable->balance()->format().' due.';
        }

        $currencies = $payable instanceof TakesPayments ? $payable->paymentCurrencies() : Currency::ALL;

        return [
            View::make('filament.documents.preview')->viewData([
                'url' => $slipVersion ? app(DocumentWallet::class)->previewUrl($slipVersion) : null,
                'isPdf' => $slipVersion?->isPdf() ?? false,
                'notes' => $notes,
                'reader' => $x?->reader,
            ]),
            Section::make('Which balance it settles')
                ->description('One line per currency. A payment in a different currency uses the exchange rate in force on the day it was paid.')
                ->schema([
                    Repeater::make('allocations')
                        ->hiddenLabel()
                        ->schema([
                            Select::make('currency')->options(array_intersect_key(Currency::options(), array_flip($currencies)))->required()->native(false)->live(),
                            MoneyInput::make('amount_minor', 'currency', 'Amount'),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->maxItems(3)
                        ->addActionLabel('Settle another currency'),
                    Textarea::make('note')->label('Note')->rows(2),
                ]),
        ];
    }

    /** @return list<array{currency: string, amount_minor: int}> */
    private static function defaultAllocations(Payment $record): array
    {
        $payable = $record->payable;
        $currencies = $payable instanceof TakesPayments ? $payable->paymentCurrencies() : [$record->currency];

        if (in_array($record->currency, $currencies, true)) {
            return [['currency' => $record->currency, 'amount_minor' => $record->amount_minor]];
        }

        return [['currency' => $currencies[0], 'amount_minor' => 0]];
    }

    private static function defaultCurrency(TakesPayments&Model $payable): string
    {
        if ($payable instanceof Booking) {
            foreach ($payable->balances() as $balance) {
                if ($balance->minor > 0) {
                    return $balance->currency;
                }
            }
        }

        return $payable->paymentCurrencies()[0] ?? Currency::MVR;
    }

    private static function refundable(Payment $record): int
    {
        return $record->amount_minor + (int) $record->refunds()->succeeded()->sum('amount_minor');
    }

    /** @return array<int, string> */
    public static function bookingSearch(string $search): array
    {
        return Booking::query()->with(['customer', 'trip'])
            ->where(fn ($query) => $query->where('reference', 'like', "%{$search}%")->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderByDesc('id')->limit(20)->get()
            ->mapWithKeys(fn (Booking $b): array => [$b->id => $b->label])->all();
    }

    /**
     * Store the slip against the payment, read it, and say what looks off.
     *
     * @return list<string>
     */
    public static function attachSlip(Payment $payment, string $bytes, string $name, string $mime, DocumentWallet $wallet, ReaderManager $readers, SlipMatcher $matcher): array
    {
        $version = $wallet->store($payment, $bytes, $name, $mime, Document::BANK_SLIP);
        $payment->transactions()->create(['type' => PaymentTransaction::SLIP_UPLOADED, 'user_id' => Auth::id(), 'payload' => ['document_version_id' => $version->getKey()]]);
        $x = $readers->read($version);
        $fields = $x->fields ?? [];

        if ($fields === []) {
            return [];
        }

        $warnings = $matcher->propose($fields)['warnings'];

        if (isset($fields['amount'], $fields['currency'])) {
            try {
                $read = Money::ofMajor((string) $fields['amount'], strtoupper((string) $fields['currency']));

                if ($read->currency !== $payment->currency || $read->minor !== $payment->amount_minor) {
                    $warnings[] = 'The slip reads '.$read->format().' but '.$payment->money()->format().' was entered.';
                }
            } catch (\Throwable) {
            }
        }

        return $warnings;
    }
}
