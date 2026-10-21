<?php

namespace App\Filament\Resources\Pilgrims\Schemas;

use App\Models\Customer;
use App\Models\Document;
use App\Models\Island;
use App\Models\Pilgrim;
use App\Services\Documents\ReaderManager;
use App\Services\Pilgrims\Duplicates;
use App\Support\Settings;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class PilgrimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scan the ID card')
                ->description('Optional. Point the camera at the card and the fields below are filled in for you to check. A pilgrim can be registered with just a name and a phone; copies can come later.')
                ->visibleOn('create')
                ->schema([
                    FileUpload::make('id_card_scan')
                        ->label('ID card')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize((int) config('documents.max_kilobytes'))
                        ->storeFiles(false)
                        ->extraInputAttributes(['capture' => 'environment'])
                        ->live()
                        ->dehydrated(false)
                        ->afterStateUpdated(function ($state, Set $set): void {
                            if (! $state instanceof TemporaryUploadedFile) {
                                return;
                            }

                            $x = app(ReaderManager::class)->run(app(ReaderManager::class)->reader(), (string) file_get_contents($state->getRealPath()), $state->getMimeType(), Document::ID_CARD);

                            foreach (['name_en', 'name_dv', 'id_number', 'gender', 'date_of_birth', 'id_expires_on', 'address', 'island_id'] as $field) {
                                if (isset($x->fields[$field])) {
                                    $set($field, $x->fields[$field]);
                                }
                            }

                            $set('scan_reading', json_encode(['reader' => $x->reader, 'model' => $x->model, 'fields' => $x->fields, 'confidence' => $x->confidence, 'raw_text' => $x->rawText, 'error' => $x->error]));

                            $dupes = Duplicates::find($x->fields['id_number'] ?? null, null, $x->fields['name_en'] ?? null, $x->fields['date_of_birth'] ?? null);

                            if ($dupes->isNotEmpty()) {
                                Notification::make()->warning()->persistent()->title('Already on file?')
                                    ->body('This looks like '.$dupes->map(fn (Pilgrim $p): string => $p->name_en.' (#'.$p->id.')')->implode(', ').'. Open that record instead of making a second one.')->send();
                            } elseif (count($x->fields) === 0) {
                                Notification::make()->warning()->title('Nothing could be read')->body($x->error ?? 'Type the details by hand; the scan is still saved with the record.')->send();
                            } else {
                                Notification::make()->success()->title(count($x->fields).' fields read')->body('Check each one against the card before saving.')->send();
                            }
                        }),
                    Hidden::make('scan_reading')->dehydrated(false),
                ]),

            View::make('filament.documents.checklist')
                ->visibleOn('edit')
                ->viewData(fn (?Pilgrim $record): array => self::checklist($record)),

            Section::make('Who')->columns(2)->schema([
                TextInput::make('name_en')->label('Name (as on passport)')->required()->maxLength(120)
                    ->dehydrateStateUsing(fn (?string $state): string => strtoupper(trim((string) $state))),
                TextInput::make('name_dv')->label('Name in Dhivehi')->maxLength(120)->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('phone')->tel()->maxLength(40),
                Select::make('gender')->options(Pilgrim::GENDERS)->native(false),
                DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->maxDate(today()),
                Select::make('customer_id')->label('Customer / family')->required()->searchable()->preload()
                    ->relationship('customer', 'name')
                    ->createOptionForm([
                        Select::make('type')->options(Customer::TYPES)->default(Customer::INDIVIDUAL)->required()->native(false),
                        TextInput::make('name')->required()->maxLength(120),
                        TextInput::make('phone')->tel()->required()->maxLength(40),
                    ])
                    ->helperText('An individual is their own customer. A family member joins the family.'),
                Select::make('relationship_to_head')->label('Relationship to the head')->options(Pilgrim::RELATIONSHIPS)->default('self')->required()->native(false),
            ]),

            Section::make('ID card')->columns(2)->schema([
                TextInput::make('id_number')->label('ID number')->maxLength(12)->regex('/^[Aa]\d{6}$/')->placeholder('A123456')
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper(trim($state)) : null)
                    ->rule(fn (?Pilgrim $record) => function (string $attribute, $value, \Closure $fail) use ($record): void {
                        if ($value && ($dupe = Pilgrim::findByIdNumber($value)) && (! $record || ! $dupe->is($record))) {
                            $fail("That ID number is already on file for {$dupe->name_en} (#{$dupe->id}).");
                        }
                    }),
                DatePicker::make('id_expires_on')->label('ID expires')->native(false),
                TextInput::make('address')->maxLength(255),
                Select::make('island_id')->label('Island')->searchable()
                    ->options(fn (): array => Island::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Island $i): array => [$i->id => $i->label])->all()),
            ]),

            Section::make('Passport')->columns(2)->collapsible()->schema([
                TextInput::make('passport_number')->label('Passport number')->maxLength(12)
                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ? strtoupper(trim($state)) : null),
                TextInput::make('nationality')->maxLength(3)->default('MDV'),
                DatePicker::make('passport_issued_on')->label('Issued')->native(false),
                DatePicker::make('passport_expires_on')->label('Expires')->native(false)
                    ->helperText(fn (): string => 'Must be valid '.Settings::get(Settings::PASSPORT_VALIDITY_MONTHS).' months after the trip returns.'),
                TextInput::make('passport_issuing_country')->label('Issuing country')->maxLength(3)->default('MDV'),
            ]),

            Section::make('Contact and needs')->columns(2)->collapsible()->collapsed(fn (string $operation): bool => $operation === 'create')->schema([
                TextInput::make('emergency_contact_name')->label('Emergency contact')->maxLength(120),
                TextInput::make('emergency_contact_phone')->label('Emergency phone')->tel()->maxLength(40),
                CheckboxList::make('needs')->options(Pilgrim::NEEDS)->columns(2)->columnSpanFull(),
                Textarea::make('medical_notes')->label('Medical notes')->rows(2)->columnSpanFull(),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    public static function checklist(?Pilgrim $record): array
    {
        if ($record === null) {
            return ['items' => [], 'mismatches' => [], 'passport' => null];
        }

        $items = [];

        foreach (Document::REQUIRED_FOR_PILGRIMS as $type) {
            $doc = $record->document($type);
            $status = $doc?->status ?? 'missing';
            $items[] = ['label' => Document::TYPES[$type], 'status' => $status, 'text' => match ($status) {
                'missing' => 'missing',
                Document::PENDING => $doc?->latestExtraction()?->isProposed() ? 'read, to accept' : 'to verify',
                Document::VERIFIED => 'verified',
                Document::REJECTED => 'rejected',
                default => $status,
            }];
        }

        foreach ($record->documents->where('type', Document::VISA) as $visa) {
            $items[] = ['label' => 'Visa'.($visa->trip ? ' '.$visa->trip->code : ''), 'status' => $visa->status, 'text' => $visa->status];
        }

        $passport = null;

        if ($record->passport_expires_on) {
            $months = (int) Settings::get(Settings::PASSPORT_VALIDITY_MONTHS);
            $passport = 'Passport expires '.$record->passport_expires_on->format('j M Y').'; good for trips returning before '.$record->passport_expires_on->copy()->subMonths($months)->format('j M Y').'.';
        }

        return ['items' => $items, 'mismatches' => $record->unexplainedMismatches(), 'passport' => $passport];
    }
}
