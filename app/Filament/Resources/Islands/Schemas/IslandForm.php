<?php

namespace App\Filament\Resources\Islands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IslandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('atoll_code')->label('Atoll code')->required()->maxLength(8)->placeholder('K'),
            TextInput::make('atoll_name')->label('Atoll')->required()->maxLength(60)->placeholder('Kaafu'),
            TextInput::make('name')->required()->maxLength(80),
            TextInput::make('name_dv')->label('Name in Dhivehi')->maxLength(80)->extraInputAttributes(['dir' => 'rtl']),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }
}
