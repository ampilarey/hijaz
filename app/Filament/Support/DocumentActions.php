<?php

namespace App\Filament\Support;

use App\Models\Document;
use App\Models\Island;
use App\Models\Pilgrim;
use App\Services\Documents\DocumentWallet;
use App\Services\Documents\ReaderManager;
use App\Services\Pilgrims\Acceptor;
use App\Services\Pilgrims\PhotoCheck;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The actions every document list offers, so the pilgrim's page and the
 * documents queue behave the same: upload, read, accept, verify, reject,
 * download.
 */
final class DocumentActions
{
    /** The upload action for a pilgrim's documents list. */
    public static function upload(\Closure $documentable): Action
    {
        return Action::make('upload')
            ->label('Upload a copy')
            ->icon('heroicon-o-camera')
            ->authorize('create', Document::class)
            ->schema([
                Select::make('type')->options(Document::TYPES)->required()->native(false)->live()->default(Document::ID_CARD),
                Select::make('trip_id')->label('For which trip')
                    ->options(fn (): array => TripOptions::all(liveOnly: true))
                    ->visible(fn ($get): bool => $get('type') === Document::VISA)
                    ->required(fn ($get): bool => $get('type') === Document::VISA),
                FileUpload::make('file')->label('Photo or scan')->required()
                    ->acceptedFileTypes(config('documents.mime_types'))
                    ->maxSize((int) config('documents.max_kilobytes'))
                    ->storeFiles(false)
                    ->extraInputAttributes(['capture' => 'environment']),
                TextInput::make('number')->label('Number on it')->maxLength(60)->visible(fn ($get): bool => in_array($get('type'), [Document::VISA, Document::VACCINATION, Document::OTHER], true)),
                DatePicker::make('expires_at')->label('Expires')->native(false)->visible(fn ($get): bool => in_array($get('type'), [Document::VISA, Document::VACCINATION, Document::OTHER], true)),
            ])
            ->action(function (array $data, DocumentWallet $wallet, ReaderManager $readers) use ($documentable): void {
                /** @var Model $owner */
                $owner = $documentable();
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];
                $bytes = (string) file_get_contents($file->getRealPath());
                $mime = $file->getMimeType();
                $type = $data['type'];

                if ($wallet->tooManyToday($owner, $type)) {
                    Notification::make()->danger()->title('Too many uploads of this type today')->send();

                    return;
                }

                if ($type === Document::PHOTO && ($problems = PhotoCheck::problems($bytes, $mime)) !== []) {
                    Notification::make()->warning()->title('The photo may be refused')->body(implode(' ', $problems))->persistent()->send();
                }

                $version = $wallet->store($owner, $bytes, $file->getClientOriginalName(), $mime, $type, $data['trip_id'] ?? null, array_filter([
                    'number' => $data['number'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                ]));

                if ($version->document->isReadable()) {
                    $extraction = $readers->read($version);
                    $n = count($extraction->fields ?? []);
                    Notification::make()->success()->title('Uploaded')->body($n > 0 ? "{$n} fields proposed. Open \"Accept reading\" to check them." : 'Nothing could be read; type the details by hand.')->send();
                } else {
                    Notification::make()->success()->title('Uploaded')->send();
                }
            });
    }

    /** @return list<Action> */
    public static function forRecord(): array
    {
        return [
            Action::make('accept')
                ->label('Accept reading')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->visible(fn (Document $record): bool => $record->latestExtraction()?->isProposed() === true)
                ->authorize('accept', Document::class)
                ->modalHeading(fn (Document $record): string => 'Check the '.strtolower($record->typeLabel()).' against the scan')
                ->modalWidth('5xl')
                ->fillForm(function (Document $record): array {
                    $x = $record->latestExtraction();
                    $fields = $x?->fields ?? [];
                    $pilgrim = $record->documentable;

                    // Prefill from the reading, falling back to what is already on the pilgrim.
                    if ($pilgrim instanceof Pilgrim) {
                        foreach (Acceptor::PILGRIM_FIELDS[$record->type] ?? [] as $f) {
                            $fields[$f] ??= $pilgrim->{$f} instanceof CarbonInterface ? $pilgrim->{$f}->toDateString() : $pilgrim->{$f};
                        }
                    }

                    return $fields + ['verify' => false];
                })
                ->schema(fn (Document $record): array => self::acceptSchema($record))
                ->action(function (Document $record, array $data, Acceptor $acceptor): void {
                    $x = $record->latestExtraction();

                    if ($x === null) {
                        return;
                    }

                    $verify = (bool) ($data['verify'] ?? false) && Filament::auth()->user()?->can('document.verify');
                    unset($data['verify']);
                    $acceptor->accept($x, $data, $verify);

                    Notification::make()->success()->title($verify ? 'Accepted and verified' : 'Accepted')->send();
                }),

            Action::make('read')
                ->label('Read again')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->visible(fn (Document $record): bool => $record->isReadable() && $record->currentVersion() !== null)
                ->authorize('accept', Document::class)
                ->action(function (Document $record, ReaderManager $readers): void {
                    $version = $record->currentVersion();
                    $x = $readers->read($version);
                    $n = count($x->fields ?? []);
                    Notification::make()->success()->title($n > 0 ? "{$n} fields proposed" : 'Nothing could be read')->body($x->error ?? '')->send();
                }),

            Action::make('verify')
                ->label('Verify')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->visible(fn (Document $record): bool => $record->status !== Document::VERIFIED && $record->currentVersion() !== null)
                ->authorize('verify', Document::class)
                ->requiresConfirmation()
                ->modalDescription('You have looked at the copy and it is genuine and readable.')
                ->action(function (Document $record, DocumentWallet $wallet): void {
                    $wallet->verify($record);
                    Notification::make()->success()->title('Verified')->send();
                }),

            Action::make('reject')
                ->label('Reject')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (Document $record): bool => $record->status !== Document::REJECTED && $record->currentVersion() !== null)
                ->authorize('verify', Document::class)
                ->schema([Textarea::make('reason')->label('What is wrong with it')->required()->rows(2)])
                ->action(function (Document $record, array $data, DocumentWallet $wallet): void {
                    $wallet->reject($record, $data['reason']);
                    Notification::make()->warning()->title('Rejected')->send();
                }),

            Action::make('download')
                ->label('Open the file')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (Document $record): bool => $record->currentVersion() !== null)
                ->authorize('download', Document::class)
                ->url(fn (Document $record, DocumentWallet $wallet): string => $wallet->downloadUrl($record->currentVersion()), shouldOpenInNewTab: true),
        ];
    }

    /** @return list<Component> */
    private static function acceptSchema(Document $record): array
    {
        $x = $record->latestExtraction();
        $low = $x?->lowConfidenceFields() ?? [];
        $flag = fn (string $f): ?string => in_array($f, $low, true) ? 'The reader was unsure. Check this one.' : null;

        $fields = match ($record->type) {
            Document::ID_CARD => [
                TextInput::make('name_en')->label('Name')->required()->maxLength(120)->hint($flag('name_en')),
                TextInput::make('name_dv')->label('Name in Dhivehi')->maxLength(120)->extraInputAttributes(['dir' => 'rtl'])->hint($flag('name_dv')),
                TextInput::make('id_number')->label('ID number')->required()->regex('/^[Aa]\d{6}$/')->hint($flag('id_number')),
                Select::make('gender')->options(Pilgrim::GENDERS)->native(false)->hint($flag('gender')),
                DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->maxDate(today())->hint($flag('date_of_birth')),
                DatePicker::make('id_expires_on')->label('ID expires')->native(false)->hint($flag('id_expires_on')),
                TextInput::make('address')->maxLength(255)->columnSpanFull()->hint($flag('address')),
                Select::make('island_id')->label('Island')->searchable()
                    ->options(fn (): array => Island::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($i): array => [$i->id => $i->label])->all())
                    ->hint($flag('island_id')),
            ],
            Document::PASSPORT => [
                TextInput::make('name_en')->label('Name')->required()->maxLength(120)->hint($flag('name_en')),
                TextInput::make('passport_number')->label('Passport number')->required()->maxLength(12)->hint($flag('passport_number')),
                Select::make('gender')->options(Pilgrim::GENDERS)->native(false)->hint($flag('gender')),
                DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->maxDate(today())->hint($flag('date_of_birth')),
                DatePicker::make('passport_issued_on')->label('Issued')->native(false)->hint($flag('passport_issued_on')),
                DatePicker::make('passport_expires_on')->label('Expires')->required()->native(false)->hint($flag('passport_expires_on')),
                TextInput::make('nationality')->maxLength(3)->default('MDV')->hint($flag('nationality')),
                TextInput::make('passport_issuing_country')->label('Issuing country')->maxLength(3)->default('MDV')->hint($flag('passport_issuing_country')),
            ],
            default => [
                TextInput::make('number')->label('Number')->maxLength(60)->hint($flag('number')),
                DatePicker::make('issued_at')->label('Issued')->native(false)->hint($flag('issued_at')),
                DatePicker::make('expires_at')->label('Expires')->native(false)->hint($flag('expires_at')),
            ],
        };

        $notes = [];

        if ($record->type === Document::PASSPORT && $x?->mrz_valid === true) {
            $notes[] = 'The machine-readable zone passed its check digits: number, date of birth and expiry are confirmed.';
        } elseif ($record->type === Document::PASSPORT && $x?->mrz_valid === false) {
            $notes[] = 'The machine-readable zone FAILED its check digits. Compare every field with the scan.';
        }

        if ($x?->error) {
            $notes[] = 'Reader: '.$x->error;
        }

        return [
            View::make('filament.documents.preview')->viewData([
                'url' => app(DocumentWallet::class)->previewUrl($record->currentVersion()),
                'isPdf' => $record->currentVersion()->isPdf(),
                'notes' => $notes,
                'reader' => $x?->reader,
            ]),
            Section::make('What it says')->columns(2)->schema($fields),
            Toggle::make('verify')->label('Also mark it verified')
                ->helperText('You have compared every field with the scan.')
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('document.verify')),
        ];
    }
}
