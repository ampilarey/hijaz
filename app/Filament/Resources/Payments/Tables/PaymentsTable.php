<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Support\PaymentActions;
use App\Models\Booking;
use App\Models\ExcursionBooking;
use App\Models\Payment;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['payable', 'documents.versions.extractions', 'account']))
            ->columns([
                Split::make([
                    TextColumn::make('reference')->searchable()->weight('medium')
                        ->description(fn (Payment $record): string => $record->payable?->payableLabel() ?? ''),
                    Split::make([
                        TextColumn::make('status')->badge()->grow(false)->state(fn (Payment $record): string => $record->statusLabel())
                            ->color(fn (Payment $record): string => match ($record->status) {
                                Payment::SUCCEEDED => 'success', Payment::FAILED => 'danger', Payment::CANCELLED => 'gray', default => 'warning',
                            }),
                        TextColumn::make('amount_minor')->label('Amount')->alignEnd()->weight('medium')->state(fn (Payment $record): string => $record->money()->format())
                            ->color(fn (Payment $record): ?string => $record->amount_minor < 0 ? 'warning' : null),
                    ])->grow(false),
                    TextColumn::make('paid_on')->label('Paid')->date('j M')->grow(false)->visibleFrom('sm'),
                    TextColumn::make('method')->state(fn (Payment $record): string => Payment::METHODS[$record->method] ?? $record->method)
                        ->description(fn (Payment $record): ?string => $record->payer_reference)->grow(false)->visibleFrom('md'),
                    TextColumn::make('slip')->label('Slip')->state(fn (Payment $record): string => $record->slip() ? 'yes' : ($record->method === Payment::BANK_TRANSFER ? 'none' : '—'))->grow(false)->visibleFrom('lg'),
                ])->from('md'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Payment::STATUSES),
                SelectFilter::make('method')->options(Payment::METHODS),
            ])
            ->headerActions([PaymentActions::fromSlip()])
            ->recordActions([
                ActionGroup::make([
                    ...PaymentActions::forRecord(),
                    Action::make('booking')->label('Booking')->icon('heroicon-o-ticket')->color('gray')
                        ->url(fn (Payment $record): ?string => match (true) {
                            $record->payable instanceof Booking => BookingResource::getUrl('view', ['record' => $record->payable]),
                            $record->payable instanceof ExcursionBooking => BookingResource::getUrl('view', ['record' => $record->payable->booking_id]),
                            default => null,
                        }),
                ]),
            ])
            ->emptyStateHeading('No payments recorded');
    }
}
