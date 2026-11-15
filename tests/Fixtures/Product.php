<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OmarKhaled\Findex\Attributes\FindexColumns;
use OmarKhaled\Findex\Concerns\Findexable;

#[Table('products')]
#[Unguarded]
#[FindexColumns(['name', 'sku', 'description', 'category.name'])]
class Product extends Model
{
    use Findexable;
    use SoftDeletes;

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
