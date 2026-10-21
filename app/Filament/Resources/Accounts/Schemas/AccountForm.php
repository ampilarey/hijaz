<?php

namespace App\Filament\Resources\Accounts\Schemas;

use App\Models\Account;
use App\Models\User;
use App\Support\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->placeholder('Bank USD'),
            Select::make('kind')->options(Account::KINDS)->required()->native(false)->live(),
            Select::make('currency')->options(Currency::options())->required()->native(false),
            TextInput::make('bank')->maxLength(80)->visible(fn (Get $get): bool => $get('kind') === Account::BANK),
            TextInput::make('account_name')->maxLength(120)->visible(fn (Get $get): bool => $get('kind') === Account::BANK),
            TextInput::make('account_number')->maxLength(40)
                ->visible(fn (Get $get): bool => $get('kind') === Account::BANK)
                ->helperText('Stored encrypted. Shown in full only on this form.'),
            Select::make('holder_user_id')->label('Held by')
                ->options(fn (): array => User::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->visible(fn (Get $get): bool => $get('kind') === Account::LEADER_CASH),
            Toggle::make('show_on_invoices')->label('Print on invoices')
                ->helperText('The account pilgrims are asked to pay into.')
                ->visible(fn (Get $get): bool => $get('kind') === Account::BANK),
            Toggle::make('is_active')->label('Active')->default(true),
            TextInput::make('sort_order')->numeric()->default(0)->minValue(0),
        ]);
    }
}
