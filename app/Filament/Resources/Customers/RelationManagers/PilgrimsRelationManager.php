<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Models\Customer;
use App\Models\Pilgrim;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PilgrimsRelationManager extends RelationManager
{
    protected static string $relationship = 'pilgrims';

    protected static ?string $title = 'People';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_en')->label('Name (as on passport)')->required()->maxLength(120)->columnSpanFull(),
            Select::make('relationship_to_head')->label('Relationship to the head')->options(Pilgrim::RELATIONSHIPS)->default('relative')->required()->native(false),
            TextInput::make('phone')->tel()->maxLength(40),
            Select::make('gender')->options(Pilgrim::GENDERS)->native(false),
            DatePicker::make('date_of_birth')->label('Date of birth')->native(false)->maxDate(today()),
            TextInput::make('id_number')->label('ID card number')->maxLength(12)->regex('/^[Aa]\d{6}$/')->placeholder('A123456'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_en')->label('Name')
                    ->description(fn (Pilgrim $record): string => (Pilgrim::RELATIONSHIPS[$record->relationship_to_head] ?? '').($record->date_of_birth ? ' · '.$record->date_of_birth->age.' yrs' : '')),
                TextColumn::make('registration_status')->badge()->label('Status')
                    ->color(fn (string $state): string => $state === Pilgrim::VERIFIED ? 'success' : 'warning'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add a person')
                    ->mutateDataUsing(function (array $data): array {
                        /** @var Customer $customer */
                        $customer = $this->getOwnerRecord();
                        if ($customer->head_pilgrim_id === null) {
                            $data['relationship_to_head'] = 'self';
                        }

                        return $data;
                    })
                    ->after(function (Pilgrim $record): void {
                        /** @var Customer $customer */
                        $customer = $this->getOwnerRecord();
                        if ($customer->head_pilgrim_id === null) {
                            $customer->forceFill(['head_pilgrim_id' => $record->getKey()])->save();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('open')->label('Open')->url(fn (Pilgrim $record): string => PilgrimResource::getUrl('edit', ['record' => $record])),
            ])
            ->emptyStateHeading('Nobody added yet');
    }
}
