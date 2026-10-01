<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentModelLink\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Article extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['tag_ids' => 'array'];

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function featuredTag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'featured_tag_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }
}
