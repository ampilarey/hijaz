<?php

namespace App\Filament\Support;

use App\Filament\Forms\MoneyInput;
use App\Models\Account;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Services\Finance\Books;
use App\Support\Currency;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/** The four ways money is written into the books by hand. Each is one modal; the Books service does the rest. */
final class BookActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::entry(), self::transfer(), self::purchase(), self::tt()];
    }

    public static function entry(): Action
    {
        return Action::make('entry')
            ->label('Record income or expense')
            ->icon('heroicon-o-pencil-square')
            ->authorize(self::may())
            ->modalWidth('2xl')
            ->fillForm(['kind' => LedgerEntry::EXPENSE, 'occurred_on' => today()->toDateString()])
            ->schema([
                Select::make('kind')->options([LedgerEntry::EXPENSE => 'Expense (money out)', LedgerEntry::INCOME => 'Income (money in)'])->required()->native(false)->live(),
                Select::make('account_id')->label('Account')->required()->native(false)->live()->options(self::accounts()),
                Select::make('category_id')->label('Category')->required()->native(false)
                    ->options(fn (Get $get): array => LedgerCategory::options($get('kind') === LedgerEntry::INCOME ? LedgerCategory::INCOME : LedgerCategory::EXPENSE)),
                MoneyInput::make('amount_minor', 'currency', 'Amount')->helperText(fn (Get $get): string => 'In '.(self::currencyOf($get('account_id')) ?? 'the account\'s currency')),
                DatePicker::make('occurred_on')->label('Date')->required()->native(false)->maxDate(today()),
                Select::make('trip_id')->label('Trip (blank for overheads)')->native(false)->options(fn (): array => TripOptions::all()),
                TextInput::make('description')->required()->maxLength(255)->columnSpanFull()->placeholder('What it was'),
                TextInput::make('counterparty')->label('Paid to / received from')->maxLength(160),
                TextInput::make('reference')->label('Invoice or bank reference')->maxLength(80),
                self::evidence(),
            ])
            ->action(function (array $data, Books $books): void {
                $account = Account::query()->findOrFail($data['account_id']);
                $category = LedgerCategory::query()->findOrFail($data['category_id']);

                try {
                    $entry = $books->record($account, $data['kind'], $data['kind'] === LedgerEntry::INCOME ? LedgerEntry::IN : LedgerEntry::OUT, Money::ofMinor((int) $data['amount_minor'], $account->currency), $category, Carbon::parse($data['occurred_on']), $data['description'], self::details($data));
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Recorded '.$entry->money()->format())->body($entry->mvr_minor === null && $entry->currency !== Currency::MVR ? 'No exchange rate is set for that date, so reports cannot total it in MVR until one is.' : null)->send();
            });
    }

    public static function transfer(): Action
    {
        return Action::make('transfer')
            ->label('Transfer between accounts')
            ->icon('heroicon-o-arrows-right-left')
            ->color('gray')
            ->authorize(self::may())
            ->fillForm(['occurred_on' => today()->toDateString()])
            ->schema([
                Select::make('from_account_id')->label('From')->required()->native(false)->live()->options(self::accounts()),
                Select::make('to_account_id')->label('To')->required()->native(false)
                    ->options(fn (Get $get): array => self::accounts(self::currencyOf($get('from_account_id')))),
                MoneyInput::make('amount_minor', 'currency', 'Amount'),
                DatePicker::make('occurred_on')->label('Date')->required()->native(false)->maxDate(today()),
                TextInput::make('description')->required()->maxLength(255)->placeholder('Cash to the bank / SAR handed back by the leader')->columnSpanFull(),
                Select::make('trip_id')->label('Trip, if it belongs to one')->native(false)->options(fn (): array => TripOptions::all()),
                TextInput::make('reference')->maxLength(80),
                self::evidence(),
            ])
            ->action(function (array $data, Books $books): void {
                $from = Account::query()->findOrFail($data['from_account_id']);
                $to = Account::query()->findOrFail($data['to_account_id']);

                try {
                    [$out] = $books->transfer($from, $to, Money::ofMinor((int) $data['amount_minor'], $from->currency), Carbon::parse($data['occurred_on']), $data['description'], self::details($data));
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($out->money()->format().' moved from '.$from->name.' to '.$to->name)->send();
            });
    }

    public static function purchase(): Action
    {
        return Action::make('purchase')
            ->label('Buy currency')
            ->icon('heroicon-o-currency-dollar')
            ->color('gray')
            ->authorize(self::may())
            ->modalWidth('2xl')
            ->fillForm(['occurred_on' => today()->toDateString(), 'paid_currency' => Currency::MVR, 'received_currency' => Currency::USD])
            ->schema([
                Select::make('paid_account_id')->label('Paid from')->required()->native(false)->live()->options(self::accounts()),
                MoneyInput::make('paid_minor', 'paid_currency', 'Amount paid')->helperText(fn (Get $get): string => 'In '.(self::currencyOf($get('paid_account_id')) ?? '…')),
                Select::make('received_account_id')->label('Received into')->required()->native(false)->live()->options(self::accounts()),
                MoneyInput::make('received_minor', 'received_currency', 'Amount received')->helperText(fn (Get $get): string => 'In '.(self::currencyOf($get('received_account_id')) ?? '…')),
                MoneyInput::make('charges_minor', 'paid_currency', 'Bank charges (in what was paid)')->required(false),
                DatePicker::make('occurred_on')->label('Date')->required()->native(false)->maxDate(today()),
                TextInput::make('counterparty')->label('Bank or exchange')->maxLength(160),
                TextInput::make('reference')->maxLength(80),
                Select::make('trip_id')->label('Trip, if bought for one')->native(false)->options(fn (): array => TripOptions::all()),
                self::evidence(),
            ])
            ->action(function (array $data, Books $books): void {
                $from = Account::query()->findOrFail($data['paid_account_id']);
                $to = Account::query()->findOrFail($data['received_account_id']);

                try {
                    $entries = $books->buyCurrency($from, Money::ofMinor((int) $data['paid_minor'], $from->currency), $to, Money::ofMinor((int) $data['received_minor'], $to->currency), Carbon::parse($data['occurred_on']), filled($data['charges_minor'] ?? null) ? Money::ofMinor((int) $data['charges_minor'], $from->currency) : null, self::details($data));
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Bought '.$entries[1]->money()->format().' at '.rtrim(rtrim(number_format((float) $entries[1]->rate_snapshot, 4, '.', ''), '0'), '.'))->send();
            });
    }

    public static function tt(): Action
    {
        return Action::make('tt')
            ->label('Send a TT')
            ->icon('heroicon-o-paper-airplane')
            ->color('gray')
            ->authorize(self::may())
            ->modalWidth('2xl')
            ->fillForm(['occurred_on' => today()->toDateString()])
            ->schema([
                Select::make('account_id')->label('From account')->required()->native(false)->live()->options(self::accounts()),
                MoneyInput::make('amount_minor', 'currency', 'Amount')->helperText(fn (Get $get): string => 'In '.(self::currencyOf($get('account_id')) ?? '…')),
                MoneyInput::make('charges_minor', 'currency', 'Bank charges')->required(false),
                Select::make('category_id')->label('What for')->required()->native(false)->options(fn (): array => LedgerCategory::options(LedgerCategory::EXPENSE)),
                Select::make('trip_id')->label('Trip')->native(false)->options(fn (): array => TripOptions::all()),
                DatePicker::make('occurred_on')->label('Date')->required()->native(false)->maxDate(today()),
                TextInput::make('counterparty')->label('Beneficiary')->required()->maxLength(160),
                TextInput::make('beneficiary_bank')->label('Their bank')->maxLength(120),
                TextInput::make('beneficiary_account')->label('Their account / IBAN')->maxLength(60),
                TextInput::make('reference')->label('TT reference')->maxLength(80),
                TextInput::make('description')->maxLength(255)->placeholder('Makkah hotel balance')->columnSpanFull(),
                self::evidence('TT advice'),
            ])
            ->action(function (array $data, Books $books): void {
                $from = Account::query()->findOrFail($data['account_id']);
                $category = LedgerCategory::query()->findOrFail($data['category_id']);

                try {
                    $entries = $books->sendTt($from, Money::ofMinor((int) $data['amount_minor'], $from->currency), $category, Carbon::parse($data['occurred_on']), $data['counterparty'], self::details($data) + ['beneficiary_bank' => $data['beneficiary_bank'] ?? null, 'beneficiary_account' => $data['beneficiary_account'] ?? null, 'description' => $data['description'] ?? null], filled($data['charges_minor'] ?? null) ? Money::ofMinor((int) $data['charges_minor'], $from->currency) : null);
                } catch (\InvalidArgumentException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('TT of '.$entries[0]->money()->format().' to '.$data['counterparty'].' recorded')->send();
            });
    }

    private static function may(): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can('ledger.create');
    }

    /** @return array<int, string> */
    private static function accounts(?string $currency = null): array
    {
        return Account::query()->where('is_active', true)->when($currency, fn ($q) => $q->where('currency', $currency))->orderBy('sort_order')->get()
            ->mapWithKeys(fn (Account $a): array => [$a->id => $a->name.' ('.$a->currency.')'])->all();
    }

    private static function currencyOf(mixed $accountId): ?string
    {
        return filled($accountId) ? Account::query()->find($accountId)?->currency : null;
    }

    private static function evidence(string $label = 'Receipt or advice (photo or PDF)'): FileUpload
    {
        return FileUpload::make('evidence')->label($label)
            ->acceptedFileTypes(config('documents.mime_types'))
            ->maxSize((int) config('documents.max_kilobytes'))
            ->storeFiles(false)
            ->extraInputAttributes(['capture' => 'environment'])
            ->columnSpanFull();
    }

    /** @param  array<string, mixed>  $data */
    private static function details(array $data): array
    {
        $file = $data['evidence'] ?? null;
        $file = is_array($file) ? reset($file) : $file;

        return array_filter([
            'trip_id' => $data['trip_id'] ?? null,
            'counterparty' => $data['counterparty'] ?? null,
            'reference' => $data['reference'] ?? null,
            'evidence' => $file instanceof TemporaryUploadedFile ? (string) file_get_contents($file->getRealPath()) : null,
            'evidence_name' => $file instanceof TemporaryUploadedFile ? $file->getClientOriginalName() : null,
            'evidence_mime' => $file instanceof TemporaryUploadedFile ? (string) $file->getMimeType() : null,
        ], fn ($v) => $v !== null && $v !== '');
    }
}
