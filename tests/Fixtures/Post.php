<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OmarKhaled\Findex\Attributes\FindexColumns;
use OmarKhaled\Findex\Concerns\Findexable;

/**
 * Exercises every kind of source: accessor, enum, array cast, raw JSON, bool, nested path and a "has many" relation.
 */
#[Table('posts', timestamps: false)]
#[Unguarded]
#[FindexColumns(
    ['title', 'author', 'status', 'meta.keywords', 'raw', 'published', 'tags.*.name', 'tags'],
    index: 'needle',
)]
class Post extends Model
{
    use Findexable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'meta' => 'array',
            'published' => 'boolean',
        ];
    }

    /** @return Attribute<string, never> */
    protected function author(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }
}
