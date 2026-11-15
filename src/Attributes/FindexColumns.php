<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Attributes;

use Attribute;

/**
 * Declares which columns of a model feed its search index.
 *
 *   #[FindexColumns(['name', 'sku', 'category.name', 'tags.*.name'])]
 *   class Product extends Model
 *   {
 *       use Findexable;
 *   }
 *
 * A column can be a real column, an accessor, or a dot-notation path into a
 * relation or an array / JSON attribute. Subclasses inherit the attribute.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FindexColumns
{
    /** @var list<string> */
    public array $columns;

    /**
     * @param  array<array-key, string>  $columns  The source columns, in the order they are indexed.
     * @param  string|null  $index  The index column of this model. Defaults to the "findex.column" option.
     */
    public function __construct(array $columns, public ?string $index = null)
    {
        $this->columns = array_values($columns);
    }
}
