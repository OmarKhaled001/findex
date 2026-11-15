<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OmarKhaled\Findex\Attributes\FindexColumns;
use OmarKhaled\Findex\Concerns\Findexable;

#[Table('categories', timestamps: false)]
#[Unguarded]
#[FindexColumns(['name'])]
class Category extends Model
{
    use Findexable;

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
