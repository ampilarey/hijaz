<?php

namespace App\Models;

use Database\Factories\IslandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $atoll_code
 * @property string $atoll_name
 * @property string $name
 * @property string|null $name_dv
 * @property bool $is_active
 */
class Island extends Model
{
    /** @use HasFactory<IslandFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['atoll_code', 'atoll_name', 'name', 'name_dv', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** "K. Maafushi", the way an address is written. */
    public function getLabelAttribute(): string
    {
        return $this->atoll_code.'. '.$this->name;
    }
}
