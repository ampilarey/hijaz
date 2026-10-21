<?php

namespace App\Filament\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M, H:i')
                    ->sortable()
                    ->description(fn (AuditLog $record): string => $record->actor),
                TextColumn::make('event')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        AuditLog::DELETED => 'danger',
                        AuditLog::CREATED => 'success',
                        AuditLog::DOWNLOADED => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('subject')
                    ->label('What')
                    ->description(fn (AuditLog $record): string => '#'.$record->auditable_id),
                TextColumn::make('channel')->badge()->visibleFrom('md'),
                TextColumn::make('user_email')->label('Email')->visibleFrom('lg'),
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
