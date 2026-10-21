<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Split::make([
                    TextColumn::make('created_at')
                        ->label('When')
                        ->dateTime('j M, H:i')
                        ->sortable()
                        ->grow(false)
                        ->description(fn (AuditLog $record): string => $record->actor),
                    Split::make([
                        TextColumn::make('event')
                            ->badge()
                            ->grow(false)
                            ->color(fn (string $state): string => match ($state) {
                                AuditLog::DELETED => 'danger',
                                AuditLog::CREATED => 'success',
                                AuditLog::DOWNLOADED => 'warning',
                                default => 'gray',
                            }),
                        TextColumn::make('subject')
                            ->label('What')
                            ->description(fn (AuditLog $record): string => $record->record_label),
                    ]),
                    TextColumn::make('channel')->badge()->grow(false)->visibleFrom('md'),
                    TextColumn::make('user_email')->label('Email')->grow(false)->visibleFrom('lg'),
                ])->from('md'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('event')->options([
                    AuditLog::CREATED => 'Created',
                    AuditLog::UPDATED => 'Updated',
                    AuditLog::DELETED => 'Deleted',
                    AuditLog::DOWNLOADED => 'Downloaded',
                ]),
                SelectFilter::make('auditable_type')->label('What')->options(
                    fn (): array => AuditLog::query()->distinct()->pluck('auditable_type')
                        ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])->all(),
                ),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('Nothing recorded yet');
    }
}
