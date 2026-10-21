<?php

namespace App\Filament\Pages;

use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Link this account to a Telegram chat so the bot acts as you.
 */
class Telegram extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.telegram';

    public ?string $code = null;

    public ?string $deepLink = null;

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('telegram.link');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Telegram';
    }

    public function link(): ?TelegramLink
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->telegramLink()->first();
    }

    public function botConfigured(): bool
    {
        return Bot::isConfigured();
    }

    public function botUsername(): ?string
    {
        return app(Bot::class)->username();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(fn (): string => $this->link() ? 'Link a different chat' : 'Get a link code')
                ->visible(fn (): bool => $this->botConfigured())
                ->action(function (Linker $linker): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();
                    $this->code = $linker->issueCode($user);
                    $this->deepLink = $linker->deepLink($this->code);
                }),
            Action::make('unlink')
                ->label('Unlink')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $this->link() !== null)
                ->action(function (Linker $linker): void {
                    /** @var User $user */
                    $user = Filament::auth()->user();
                    $linker->revokeFor($user);
                    $this->code = null;
                    $this->deepLink = null;
                }),
        ];
    }
}
