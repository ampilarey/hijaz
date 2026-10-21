<?php

namespace App\Filament\Resources\Bookings\RelationManagers;

use App\Filament\Support\PaymentActions;
use App\Models\Payment;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->description(fn (Payment $record): string => implode(' · ', array_filter([
                        $record->paid_on?->format('j M Y'),
                        Payment::METHODS[$record->method] ?? $record->method,
                        $record->payer_reference,
                    ]))),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->state(fn (Payment $record): string => $record->money()->format())
                    ->color(fn (Payment $record): ?string => $record->amount_minor < 0 ? 'warning' : null),
                TextColumn::make('status')->badge()->state(fn (Payment $record): string => $record->statusLabel())
                    ->color(fn (Payment $record): string => match ($record->status) {
                        Payment::SUCCEEDED => 'success', Payment::FAILED => 'danger', Payment::CANCELLED => 'gray', default => 'warning',
                    }),
            ])
            ->headerActions([PaymentActions::record(fn () => $this->getOwnerRecord())])
            ->recordActions(PaymentActions::forRecord())
            ->emptyStateHeading('No payments yet')
            ->emptyStateDescription('Record a transfer with its slip, or cash handed in. Finance verifies each one.');
    }
}
