<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Support\Access;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextInput::make('name')->required()->maxLength(120),
                TextInput::make('email')->email()->required()->unique(ignoreRecord: true)->maxLength(190),
                TextInput::make('phone')->tel()->maxLength(40),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(12)
                    ->maxLength(200)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->helperText('At least 12 characters. Leave blank to keep the current one.'),
                Select::make('roles')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText(implode(' · ', Access::ROLES)),
                Toggle::make('is_active')
                    ->label('Active')
                    ->default(true)
                    ->helperText('Off keeps the account and its history but lets nobody sign in with it.'),
            ]),
        ]);
    }
}
