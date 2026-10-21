<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use App\Models\Island;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('type')->options(Customer::TYPES)->default(Customer::INDIVIDUAL)->required()->native(false),
                TextInput::make('name')->required()->maxLength(120)->helperText('The family name for a family, or the person\'s name.'),
                TextInput::make('phone')->tel()->required()->maxLength(40),
                TextInput::make('phone_alt')->label('Other phone')->tel()->maxLength(40),
                TextInput::make('email')->email()->maxLength(120),
                Select::make('island_id')->label('Island')->searchable()
                    ->options(fn (): array => Island::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Island $i): array => [$i->id => $i->label])->all()),
                TextInput::make('address')->maxLength(255)->columnSpanFull(),
                Select::make('source')->label('How they found us')->options(Customer::SOURCES)->native(false),
                Textarea::make('notes')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }
}
