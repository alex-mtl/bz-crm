<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $driver
 * @property string $display_name
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property list<string>|null $scopes
 * @property bool $is_enabled
 * @property int $sort_order
 */
class AuthProvider extends Model
{
    protected $fillable = ['code', 'driver', 'display_name', 'client_id', 'client_secret', 'scopes', 'is_enabled', 'sort_order'];

    protected $hidden = ['client_secret'];

    protected function casts(): array
    {
        return [
            'client_secret' => 'encrypted',
            'scopes' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @param  Builder<AuthProvider>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_enabled', true)->whereNotNull('client_id')->whereNotNull('client_secret')->orderBy('sort_order');
    }

    public function hasSecret(): bool
    {
        return filled($this->getRawOriginal('client_secret'));
    }
}
