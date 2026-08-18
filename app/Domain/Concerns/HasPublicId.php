<?php

namespace App\Domain\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gives a model a non-sequential, prefixed public identifier (e.g. `bkg_01K2N84J4...`)
 * safe to expose to clients instead of the internal auto-incrementing id (§48).
 *
 * @mixin Model
 */
trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (Model $model): void {
            $column = $model->getPublicIdColumn();

            if (empty($model->{$column})) {
                $model->{$column} = static::generatePublicId();
            }
        });
    }

    public static function generatePublicId(): string
    {
        return static::publicIdPrefix().'_'.Str::lower((string) Str::ulid());
    }

    public function getPublicIdColumn(): string
    {
        return 'public_id';
    }

    public function getRouteKeyName(): string
    {
        return $this->getPublicIdColumn();
    }

    abstract public static function publicIdPrefix(): string;
}
