<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Mmoollllee\FilamentModelLink\Contracts\HasPills;

class Tag extends Model implements HasPills
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['retired' => 'boolean'];

    public static function label(): string
    {
        return 'Tag';
    }

    public static function color(): string
    {
        return 'warning';
    }
}
