<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\FilamentModelLink\Contracts\HasPillLabel;
use Mmoollllee\FilamentModelLink\Contracts\HasPillParent;
use Mmoollllee\FilamentModelLink\Contracts\HasPills;

/**
 * In-memory fixture model — enough surface to exercise the presenter without
 * a database. Implements every package contract so a single class can stand
 * in for several test scenarios.
 */
class PillModel extends Model implements HasPillLabel, HasPillParent, HasPills
{
    public $timestamps = false;

    protected $guarded = [];

    protected static string $labelText = 'Model';

    protected static string $colorName = 'gray';

    public ?PillModel $parent = null;

    /**
     * Construct a saved-looking instance: gives it an id, fills attributes.
     */
    public static function fake(array $attributes = [], int $id = 1): self
    {
        $m = new self($attributes);
        $m->id = $id;
        $m->exists = true;

        return $m;
    }

    public static function withClassLabel(string $label, string $color = 'gray'): string
    {
        $klass = new class extends PillModel
        {
            protected static string $labelText = 'OVERRIDE';

            protected static string $colorName = 'gray';
        };

        $class = $klass::class;
        $class::$labelText = $label;
        $class::$colorName = $color;

        return $class;
    }

    public static function label(): string
    {
        return static::$labelText;
    }

    public static function color(): string
    {
        return static::$colorName;
    }

    public function pillLabel(): string
    {
        return $this->name ?? 'PillModel #'.$this->getKey();
    }

    public function pillParent(): ?Model
    {
        return $this->parent;
    }
}
