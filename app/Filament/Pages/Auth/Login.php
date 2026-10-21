<?php

namespace App\Filament\Pages\Auth;

use App\Support\Phone;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use SensitiveParameter;

/** Sign in with an email address or a mobile number; the field is one box either way. */
class Login extends BaseLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Email or mobile number')
            ->required()
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['inputmode' => 'email']);
    }

    /** @return array<string, mixed> */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        $login = trim((string) $data['email']);

        if (Phone::looksLikeOne($login)) {
            return ['phone' => Phone::normalise($login) ?? $login, 'password' => $data['password']];
        }

        return ['email' => strtolower($login), 'password' => $data['password']];
    }
}
