<?php

namespace App\Filament;

use App\Support\InitialsAvatar;
use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        return InitialsAvatar::forName(Filament::getNameForDefaultAvatar($record));
    }
}
