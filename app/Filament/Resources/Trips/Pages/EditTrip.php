<?php

namespace App\Filament\Resources\Trips\Pages;

use App\Exceptions\IllegalTripTransition;
use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Services\Operations\Readiness;
use App\Services\Trips\RouteBuilder;
use App\Services\Trips\TripCopier;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;

class EditTrip extends EditRecord
{
    protected static string $resource = TripResource::class;

    public function getSubheading(): ?string
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();
        $route = $trip->routeSummary();

        return $trip->statusLabel().' · '.$trip->seatsTaken().' of '.$trip->capacity_total.' seats'.($route ? ' · '.$route : '');
    }

    protected function getHeaderActions(): array
    {
        /** @var Trip $trip */
        $trip = $this->getRecord();

        $blockers = in_array(Trip::TRAVELLING, $trip->allowedTransitions(), true) ? app(Readiness::class)->blockers($trip) : [];

        $transitions = collect($trip->allowedTransitions())->map(fn (string $to): Action => Action::make('to_'.$to)
            ->label(match ($to) {
                Trip::OPEN => 'Open for registration',
                Trip::CLOSED => 'Close registration',
                Trip::TRAVELLING => 'Mark as travelling',
                Trip::COMPLETED => 'Mark as completed',
                Trip::CANCELLED => 'Cancel trip',
                default => ucfirst($to),
            })
            ->color($to === Trip::CANCELLED ? 'danger' : 'primary')
            ->requiresConfirmation()
            ->authorize('transition', $trip)
            ->modalDescription(fn (): ?string => $to === Trip::TRAVELLING && $blockers !== []
                ? 'The readiness board says this trip cannot travel yet: '.implode('; ', $blockers).'. Only an Admin may override, and the override is recorded.'
                : null)
            ->schema($to === Trip::CANCELLED || ($to === Trip::TRAVELLING && $blockers !== [])
                ? [Textarea::make('reason')->label($to === Trip::TRAVELLING ? 'Why it may travel anyway' : 'Reason')->required()->rows(2)]
                : [])
            ->action(function (array $data) use ($trip, $to, $blockers): void {
                if ($to === Trip::TRAVELLING && $blockers !== []) {
                    if (! auth()->user()?->can('trip.override')) {
                        Notification::make()->danger()->title('An Admin has to override the readiness board for this trip')->send();

                        return;
                    }

                    $data['reason'] = mb_substr('Override by '.auth()->user()->name.': '.($data['reason'] ?? '').' (blocked by: '.implode('; ', $blockers).')', 0, 255);
                }

                try {
                    $trip->transitionTo($to, $data['reason'] ?? null);
                } catch (IllegalTripTransition $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Trip is now '.strtolower($trip->statusLabel()))->send();
                $this->refreshFormData(['status']);
            }))->values()->all();

        return [
            Action::make('readiness')->label('Readiness')->icon('heroicon-o-clipboard-document-check')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('readiness.view'))
                ->url(TripResource::getUrl('readiness', ['record' => $trip])),
            Action::make('lists')->label('Lists')->icon('heroicon-o-document-text')->color('gray')
                ->visible(fn (): bool => (bool) auth()->user()?->can('list.view'))
                ->url(TripResource::getUrl('lists', ['record' => $trip])),
            ...$transitions,
            ActionGroup::make([
                Action::make('copy')
                    ->label('Copy this trip')
                    ->icon('heroicon-o-document-duplicate')
                    ->authorize('create', Trip::class)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(120)->default(fn (): string => $trip->name.' (copy)'),
                        DatePicker::make('departs_on')->label('New departure date')->required()->native(false),
                    ])
                    ->action(function (array $data, TripCopier $copier) use ($trip): void {
                        $copy = $copier->copy($trip, $data['name'], Carbon::parse($data['departs_on']));
                        Notification::make()->success()->title('Copied as '.$copy->code)->send();
                        $this->redirect(TripResource::getUrl('edit', ['record' => $copy]));
                    }),
                Action::make('build_route')
                    ->label('Build route from flights and hotels')
                    ->icon('heroicon-o-map')
                    ->authorize('update', $trip)
                    ->visible(fn (): bool => $trip->stops()->doesntExist())
                    ->action(function (RouteBuilder $builder) use ($trip): void {
                        $n = $builder->build($trip);
                        Notification::make()->success()->title($n === 0 ? 'Nothing to build from yet' : "{$n} stops added")->send();
                    }),
            ])->label('More')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
