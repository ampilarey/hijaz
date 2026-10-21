<?php

namespace App\Filament\Resources\Pilgrims\Pages;

use App\Filament\Resources\Pilgrims\PilgrimResource;
use App\Models\Document;
use App\Models\Pilgrim;
use App\Services\Pilgrims\IdentityCheck;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditPilgrim extends EditRecord
{
    protected static string $resource = PilgrimResource::class;

    public function getSubheading(): ?string
    {
        /** @var Pilgrim $p */
        $p = $this->getRecord();

        return ucfirst($p->registration_status).' · '.($p->customer?->name ?? '').($p->date_of_birth ? ' · '.$p->date_of_birth->age.' years' : '');
    }

    protected function getHeaderActions(): array
    {
        /** @var Pilgrim $pilgrim */
        $pilgrim = $this->getRecord();

        return [
            Action::make('verify_pilgrim')
                ->label('Verify registration')
                ->icon('heroicon-o-shield-check')
                ->color('success')
                ->visible(fn (): bool => ! $pilgrim->isVerified())
                ->authorize('verify', $pilgrim)
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $pilgrim->document(Document::ID_CARD)?->isVerified()
                    ? 'The ID card is verified and the details match it.'
                    : 'The ID card copy is not verified yet. Verify it first, or confirm you have seen the original card.')
                ->action(function (): void {
                    /** @var Pilgrim $p */
                    $p = $this->getRecord();
                    $p->forceFill(['registration_status' => Pilgrim::VERIFIED, 'verified_by' => Filament::auth()->id(), 'verified_at' => now()])->save();
                    Notification::make()->success()->title('Registration verified')->send();
                    $this->refreshFormData(['registration_status']);
                }),
            ActionGroup::make([
                Action::make('identity')
                    ->label('Compare ID card with passport')
                    ->icon('heroicon-o-scale')
                    ->authorize('update', $pilgrim)
                    ->action(function (IdentityCheck $check): void {
                        /** @var Pilgrim $p */
                        $p = $this->getRecord();
                        $found = $check->run($p);
                        $open = array_filter($found, fn (array $m): bool => empty($m['explained']));
                        Notification::make()->{$open === [] ? 'success' : 'warning'}()->title($open === [] ? 'They agree' : count($open).' difference(s) found')->send();
                    }),
                Action::make('explain')
                    ->label('Explain a difference')
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->visible(fn (): bool => $pilgrim->unexplainedMismatches() !== [])
                    ->authorize('verify', $pilgrim)
                    ->schema([
                        Select::make('field')->options(fn (): array => collect($pilgrim->unexplainedMismatches())->mapWithKeys(fn (array $m): array => [$m['field'] => ucfirst(str_replace('_', ' ', $m['field']))])->all())->required()->native(false),
                        Textarea::make('note')->label('Why it is fine')->required()->rows(2),
                    ])
                    ->action(function (array $data, IdentityCheck $check): void {
                        /** @var Pilgrim $p */
                        $p = $this->getRecord();
                        $check->explain($p, $data['field'], $data['note']);
                        Notification::make()->success()->title('Noted')->send();
                    }),
            ])->label('More')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }
}
