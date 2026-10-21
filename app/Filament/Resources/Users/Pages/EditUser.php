<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\LastAdmin;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Access;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Taking the Admin role off the last Admin is the same loss as deleting
     * them. The form saves roles through the relationship after the record,
     * so the check happens here, before anything is written.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $keepsAdmin = in_array(
            Role::findByName(Access::ADMIN)->getKey(),
            array_map('intval', (array) ($this->data['roles'] ?? [])),
            true,
        );

        if ($record->isTheLastAdmin() && ! $keepsAdmin) {
            Notification::make()->danger()->title((new LastAdmin)->getMessage())->send();
            $this->halt();
        }

        return parent::handleRecordUpdate($record, $data);
    }
}
