<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditObserver
{
    /**
     * Never written to the log, whatever model they appear on. A trail that
     * records a password hash or a token becomes the most valuable table in
     * the database, and the one most people may read.
     *
     * @var list<string>
     */
    private const NEVER_RECORD = [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
        'code_hash',
        'token_hash',
    ];

    /**
     * Whose change is worth recording but whose value is not: the key stays,
     * the value is masked.
     *
     * @var list<string>
     */
    private const REDACT = ['id_number', 'passport_number', 'account_number'];

    private const REDACTED = '[redacted]';

    /** @var list<string> */
    private const NOISE = ['created_at', 'updated_at'];

    public function created(Model $model): void
    {
        $this->record($model, AuditLog::CREATED, null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = $this->clean($model->getChanges());

        if ($changed === []) {
            return;
        }

        $original = $model->getRawOriginal();
        $before = [];

        foreach (array_keys($changed) as $key) {
            $before[$key] = $original[$key] ?? null;
        }

        $this->record($model, AuditLog::UPDATED, $this->clean($before), $changed);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, AuditLog::DELETED, $this->clean($model->getAttributes()), null);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(Model $model, string $event, ?array $old, ?array $new): void
    {
        $user = Auth::user();
        $request = request();

        $actorIsBeingDeleted = $event === AuditLog::DELETED
            && $user !== null
            && $model instanceof User
            && $model->getKey() === $user->getKey();

        AuditLog::create([
            'user_id' => $actorIsBeingDeleted ? null : $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => $event,
            'channel' => AuditContext::channel(),
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'url' => $request->fullUrl(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        $attributes = array_diff_key($attributes, array_flip(array_merge(self::NEVER_RECORD, self::NOISE)));

        foreach (self::REDACT as $key) {
            if (array_key_exists($key, $attributes)) {
                $attributes[$key] = $attributes[$key] === null ? null : self::REDACTED;
            }
        }

        return $attributes;
    }
}
