<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $update_id
 * @property int|null $chat_id
 * @property string|null $kind
 * @property string|null $command
 */
class TelegramUpdate extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['update_id', 'chat_id', 'kind', 'command', 'received_at', 'handled_at', 'error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'handled_at' => 'datetime'];
    }
}
