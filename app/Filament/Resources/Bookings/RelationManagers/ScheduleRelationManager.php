<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Filament\Forms\MoneyInput;
use App\Models\Booking;
use App\Models\PaymentSchedule;
use App\Support\Currency;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Instalments: this much by this date. Information for the customer and a nudge for the office, not a rule the ledger enforces. */
class ScheduleRelationManager extends RelationManager
{
    protected static string $relationship = 'schedule';

    protected static ?string $title = 'Instalments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        /** @var Booking $booking */
        $booking = $this->getOwnerRecord();

        return $schema->columns(2)->components([
            TextInput::make('label')->required()->maxLength(60)->placeholder('Deposit / Second payment / Balance'),
            DatePicker::make('due_on')->label('Due')->required()->native(false),
            Select::make('currency')->options(array_intersect_key(Currency::options(), array_flip($booking->currencies() ?: [Currency::MVR])))->default($booking->currencies()[0] ?? Currency::MVR)->required()->native(false)->live(),
            MoneyInput::make('amount_minor', 'currency', 'Amount'),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Booking $booking */
        $booking = $this->getOwnerRecord();

        return $table
            ->columns([
                TextColumn::make('label')->description(fn (PaymentSchedule $record): string => 'due '.$record->due_on->format('j M Y')),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->state(fn (PaymentSchedule $record): string => $record->money()->format()),
                TextColumn::make('state')->label('')->badge()
                    ->state(fn (PaymentSchedule $record): string => $record->isOverdue() ? 'Overdue' : ($record->due_on->isPast() ? 'Covered' : 'Upcoming'))
                    ->color(fn (string $state): string => match ($state) {
                        'Overdue' => 'danger', 'Covered' => 'success', default => 'gray',
                    }),
            ])
            ->defaultSort('due_on')
            ->headerActions([CreateAction::make()->label('Add an instalment')->authorize($this->may('update'))])
            ->recordActions([EditAction::make()->authorize($this->may('update')), DeleteAction::make()->authorize($this->may('update'))])
            ->paginated(false)
            ->emptyStateHeading('No instalments set')
            ->emptyStateDescription('Optional. Set them and the invoice shows the dates; the Today page flags any that fall behind.');
    }

    /**
     * Filament checks a relation manager's actions against the related
     * model, so the owner's own policy is asked by hand.
     */
    private function may(string $ability): \Closure
    {
        return fn (): bool => (bool) Filament::auth()->user()?->can($ability, $this->getOwnerRecord());
    }
}
