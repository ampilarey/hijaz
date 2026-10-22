<?php

namespace App\Filament\Pages;

use App\Support\Settings as Store;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static UnitEnum|string|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.pages.settings';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('setting.view');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Settings';
    }

    public function mount(): void
    {
        $this->form->fill(Store::all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Company')
                    ->description('Printed on invoices, receipts, cards and letters.')
                    ->schema([
                        TextInput::make(Store::COMPANY_NAME)->label('Name')->required()->maxLength(120),
                        TextInput::make(Store::COMPANY_REGISTRATION)->label('Registration number')->maxLength(60),
                        Textarea::make(Store::COMPANY_ADDRESS)->label('Address')->rows(2)->maxLength(300),
                        TextInput::make(Store::COMPANY_EMAIL)->label('Email')->email()->maxLength(120),
                        TextInput::make(Store::COMPANY_PHONES)->label('Phones')->maxLength(120),
                    ]),
                Section::make('Documents')
                    ->schema([
                        TextInput::make(Store::PASSPORT_VALIDITY_MONTHS)
                            ->label('Passport must be valid for, after the return date (months)')
                            ->numeric()->integer()->minValue(0)->maxValue(24)->required(),
                        Textarea::make(Store::AGREEMENT_TERMS)
                            ->label('Customer agreement')
                            ->helperText('What a customer signs for each booking, in the portal or on paper. {company}, {customer}, {booking} and {trip} are filled in. An agreement already signed keeps the wording it was signed with.')
                            ->rows(14)->required()->maxLength(20000),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('setting.update'))
                ->action('save'),
        ];
    }

    public function save(): void
    {
        abort_unless(Filament::auth()->user()?->can('setting.update'), 403);

        foreach ($this->form->getState() as $key => $value) {
            Store::set($key, $value);
        }

        Notification::make()->success()->title('Settings saved')->send();
    }
}
