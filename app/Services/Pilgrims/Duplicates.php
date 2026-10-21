<?php

namespace App\Services\Pilgrims;

use App\Models\Pilgrim;
use Illuminate\Support\Collection;

/** "Is this the same person?" by ID number, passport number, or name and date of birth. */
final class Duplicates
{
    /** @return Collection<int, Pilgrim> */
    public static function find(?string $idNumber, ?string $passportNumber, ?string $name, ?string $dateOfBirth, ?int $exceptId = null): Collection
    {
        $query = Pilgrim::query()->where(function ($q) use ($idNumber, $passportNumber, $name, $dateOfBirth): void {
            $any = false;

            if (filled($idNumber)) {
                $q->orWhere(fn ($qq) => $qq->withIdNumber($idNumber));
                $any = true;
            }

            if (filled($passportNumber)) {
                $q->orWhere(fn ($qq) => $qq->withPassportNumber($passportNumber));
                $any = true;
            }

            if (filled($name) && filled($dateOfBirth)) {
                $q->orWhere(fn ($qq) => $qq->whereDate('date_of_birth', $dateOfBirth)->where('name_en', 'like', '%'.trim(explode(' ', trim($name))[0]).'%'));
                $any = true;
            }

            if (! $any) {
                $q->whereRaw('1 = 0');
            }
        });

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->limit(5)->get();
    }
}
