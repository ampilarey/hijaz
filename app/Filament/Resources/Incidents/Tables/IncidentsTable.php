<?php

namespace App\Filament\Resources\Incidents\Tables;

use App\Events\IncidentRaised;
use App\Filament\Support\TripOptions;
use App\Models\BookingPilgrim;
use App\Models\Incident;
use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class IncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['trip', 'bookingPilgrim.pilgrim', 'recorder', 'resolver']))
            ->columns([
                Split::make([
                    TextColumn::make('summary')->searchable()->wrap()->weight('medium')
                        ->description(fn (Incident $record): string => implode(' · ', array_filter([$record->reference, $record->trip->code, $record->categoryLabel(), $record->bookingPilgrim?->pilgrim?->name_en, $record->location, $record->happened_at->format('j M H:i'), $record->recorder?->name]))),
                    Split::make([
                        TextColumn::make('severity')->badge()->grow(false)->state(fn (Incident $record): string => $record->severityLabel())
                            ->color(fn (Incident $record): string => match ($record->severity) {
                                Incident::EMERGENCY => 'danger', Incident::SERIOUS => 'warning', default => 'gray',
                            }),
                        TextColumn::make('status')->badge()->grow(false)->state(fn (Incident $record): string => $record->isOpen() ? 'Open' : 'Resolved')
                            ->color(fn (Incident $record): string => $record->isOpen() ? 'danger' : 'success'),
                    ])->grow(false),
                ])->from('md'),
            ])
            ->defaultSort('happened_at', 'desc')
            ->filters([
                SelectFilter::make('trip_id')->label('Trip')->options(fn (): array => TripOptions::all()),
                SelectFilter::make('severity')->options(Incident::SEVERITIES),
                SelectFilter::make('category')->options(Incident::CATEGORIES),
            ])
            ->headerActions([
                Action::make('raise')
                    ->label('Log an incident')
                    ->icon('heroicon-o-plus')
                    ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('incident.create'))
                    ->modalWidth('2xl')
                    ->fillForm(['severity' => Incident::MINOR, 'category' => 'other', 'happened_at' => now()])
                    ->schema([
                        Select::make('trip_id')->label('Trip')->required()->native(false)->live()->options(fn (): array => TripOptions::all(liveOnly: true)),
                        Select::make('booking_pilgrim_id')->label('Who')->native(false)->searchable()
                            ->options(fn (Get $get): array => filled($get('trip_id')) ? Trip::query()->find($get('trip_id'))?->travellers()->with('pilgrim')->get()->mapWithKeys(fn (BookingPilgrim $bp): array => [$bp->id => $bp->pilgrim->name_en])->all() ?? [] : []),
                        Select::make('severity')->options(Incident::SEVERITIES)->required()->native(false),
                        Select::make('category')->options(Incident::CATEGORIES)->required()->native(false),
                        TextInput::make('summary')->label('What happened')->required()->maxLength(255)->columnSpanFull(),
                        TextInput::make('location')->label('Where')->maxLength(255),
                        DateTimePicker::make('happened_at')->label('When')->required()->native(false),
                        Textarea::make('detail')->rows(3)->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        $incident = Incident::query()->create($data + ['client_uuid' => (string) Str::uuid()]);
                        IncidentRaised::dispatch($incident);
                        Notification::make()->success()->title($incident->reference.' logged')->send();
                    }),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('resolve')
                        ->label('Resolve')
                        ->icon('heroicon-o-check')
                        ->color('success')
                        ->visible(fn (Incident $record): bool => $record->isOpen())
                        ->authorize(fn (): bool => (bool) Filament::auth()->user()?->can('incident.resolve'))
                        ->schema([Textarea::make('resolution')->label('What was done')->required()->rows(3)])
                        ->action(function (Incident $record, array $data): void {
                            $record->resolve($data['resolution']);
                            Notification::make()->success()->title($record->reference.' resolved')->send();
                        }),
                    Action::make('detail')->label('Detail')->icon('heroicon-o-eye')->color('gray')
                        ->modalHeading(fn (Incident $record): string => $record->reference.' · '.$record->summary)
                        ->modalContent(fn (Incident $record) => new HtmlString('<div class="prose prose-sm max-w-none">'.nl2br(e(implode("\n\n", array_filter([
                            $record->severityLabel().' · '.$record->categoryLabel().' · '.$record->happened_at->format('j M Y H:i').($record->location ? ' · '.$record->location : ''),
                            $record->bookingPilgrim ? 'Who: '.$record->bookingPilgrim->pilgrim->name_en : null,
                            $record->detail,
                            'Raised by '.($record->recorder?->name ?? 'the leader'),
                            $record->resolution ? 'Resolved by '.($record->resolver?->name ?? '').' on '.$record->resolved_at?->format('j M H:i').': '.$record->resolution : null,
                        ])))).'</div>'))
                        ->modalSubmitAction(false)->modalCancelActionLabel('Close'),

                ]),
            ])
            ->emptyStateHeading('No incidents')
            ->emptyStateDescription('Leaders raise them from the ground page; the office logs them here.');
    }
}
