<?php

declare(strict_types=1);

namespace App\Support\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A declarative "retain until" date on a record (Д-18). New records get the default from config/retention.php;
 * an empty date means the same default. Nothing purges by this date yet — retention policy is pending.
 *
 * @mixin Model
 */
trait HasRetention
{
    public static function bootHasRetention(): void
    {
        static::creating(function (Model $model): void {
            $model->setAttribute('retain_until', $model->getAttribute('retain_until') ?? self::defaultRetainUntil());
        });
    }

    public function initializeHasRetention(): void
    {
        $this->mergeCasts(['retain_until' => 'immutable_date']);
    }

    public function retainUntil(): CarbonImmutable
    {
        $date = $this->getAttribute('retain_until');

        return $date instanceof CarbonImmutable ? $date : self::defaultRetainUntil();
    }

    public static function defaultRetainUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse((string) config('retention.default_until'))->startOfDay();
    }
}
