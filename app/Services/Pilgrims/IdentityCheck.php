<?php

namespace App\Services\Pilgrims;

use App\Models\Document;
use App\Models\DocumentExtraction;
use App\Models\Pilgrim;

/**
 * Does the ID card agree with the passport? Compares what each accepted
 * reading said: date of birth and gender must match; names are compared
 * as sets of words so "MOHAMED AHMED" and "AHMED, MOHAMED" agree. A
 * mismatch is recorded on the pilgrim and shown everywhere until a person
 * explains it.
 */
class IdentityCheck
{
    public const NAME_THRESHOLD = 0.6;

    /** @return list<array<string, mixed>> */
    public function run(Pilgrim $pilgrim): array
    {
        $id = $this->accepted($pilgrim, Document::ID_CARD);
        $passport = $this->accepted($pilgrim, Document::PASSPORT);

        if ($id === null || $passport === null) {
            return $pilgrim->identity_mismatches ?? [];
        }

        $found = [];

        if (isset($id['date_of_birth'], $passport['date_of_birth']) && $id['date_of_birth'] !== $passport['date_of_birth']) {
            $found[] = ['field' => 'date_of_birth', 'id_card' => $id['date_of_birth'], 'passport' => $passport['date_of_birth']];
        }

        if (isset($id['gender'], $passport['gender']) && strtolower($id['gender']) !== strtolower($passport['gender'])) {
            $found[] = ['field' => 'gender', 'id_card' => $id['gender'], 'passport' => $passport['gender']];
        }

        $idName = $id['name_en'] ?? null;
        $passportName = $passport['name_en'] ?? trim(($passport['given_names'] ?? '').' '.($passport['surname'] ?? ''));

        if ($idName && $passportName && self::nameSimilarity($idName, $passportName) < self::NAME_THRESHOLD) {
            $found[] = ['field' => 'name', 'id_card' => $idName, 'passport' => $passportName];
        }

        // Keep explanations staff already gave for the same mismatch.
        $previous = collect($pilgrim->identity_mismatches ?? []);
        $merged = array_map(function (array $m) use ($previous): array {
            $old = $previous->first(fn (array $p): bool => $p['field'] === $m['field'] && ($p['id_card'] ?? null) === $m['id_card'] && ($p['passport'] ?? null) === $m['passport']);

            return $old ? array_merge($m, ['explained' => $old['explained'] ?? false, 'note' => $old['note'] ?? null]) : $m + ['explained' => false, 'note' => null];
        }, $found);

        $pilgrim->forceFill(['identity_mismatches' => $merged ?: null])->save();

        return $merged;
    }

    public function explain(Pilgrim $pilgrim, string $field, string $note): void
    {
        $mismatches = array_map(function (array $m) use ($field, $note): array {
            if ($m['field'] === $field) {
                $m['explained'] = true;
                $m['note'] = $note;
            }

            return $m;
        }, $pilgrim->identity_mismatches ?? []);

        $pilgrim->forceFill(['identity_mismatches' => $mismatches])->save();
    }

    /** Jaccard similarity of the words, after upper-casing and dropping punctuation. */
    public static function nameSimilarity(string $a, string $b): float
    {
        $words = fn (string $s): array => array_values(array_unique(array_filter(preg_split('/\s+/', strtoupper(preg_replace('/[^A-Za-z\s]/', ' ', $s) ?? '')) ?: [])));
        $wa = $words($a);
        $wb = $words($b);

        if ($wa === [] || $wb === []) {
            return 0.0;
        }

        $union = array_unique(array_merge($wa, $wb));

        return count(array_intersect($wa, $wb)) / count($union);
    }

    /** @return array<string, mixed>|null */
    private function accepted(Pilgrim $pilgrim, string $type): ?array
    {
        $document = $pilgrim->documents()->where('type', $type)->first();

        if ($document === null) {
            return null;
        }

        $extraction = DocumentExtraction::query()
            ->whereIn('document_version_id', $document->versions()->pluck('id'))
            ->where('status', DocumentExtraction::ACCEPTED)
            ->latest('id')
            ->first();

        return $extraction?->accepted_fields;
    }
}
