<?php

namespace App\Filament\Resources\AuditLogs\Schemas;

use App\Models\AuditLog;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextEntry::make('created_at')->label('When')->dateTime('j M Y, H:i'),
                TextEntry::make('actor')->label('Who'),
                TextEntry::make('event')->badge(),
                TextEntry::make('channel')->badge(),
                TextEntry::make('subject')->label('What'),
                TextEntry::make('record_label')->label('Record'),
                TextEntry::make('ip_address')->label('From')->placeholder('—'),
                TextEntry::make('url')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Before')->schema([
                KeyValueEntry::make('old_values')->hiddenLabel()->keyLabel('Field')->valueLabel('Value'),
            ])->visible(fn (AuditLog $record): bool => filled($record->old_values)),
            Section::make('After')->schema([
                KeyValueEntry::make('new_values')->hiddenLabel()->keyLabel('Field')->valueLabel('Value'),
            ])->visible(fn (AuditLog $record): bool => filled($record->new_values)),
        ]);
    }
}
