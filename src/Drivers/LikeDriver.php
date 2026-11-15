<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Drivers;

use Illuminate\Database\Query\Builder;
use Override;

/**
 * Substring search with LIKE. Works on every database.
 *
 * Variants are already normalized and can never contain a LIKE wildcard, so
 * they are bound as-is, without escaping.
 */
final readonly class LikeDriver implements Driver
{
    #[Override]
    public function apply(Builder $query, string $column, array $groups): void
    {
        // A single group needs no parentheses of its own: the caller's nested where is enough.
        if (count($groups) === 1) {
            $this->variants($query, $column, $groups[0]);

            return;
        }

        foreach ($groups as $variants) {
            $this->group($query, $column, $variants);
        }
    }

    /**
     * Add one group as a single AND constraint.
     *
     * @param  non-empty-list<string>  $variants
     */
    public function group(Builder $query, string $column, array $variants): void
    {
        if (count($variants) === 1) {
            $query->where($column, 'like', '%'.$variants[0].'%');

            return;
        }

        $query->where(function (Builder $nested) use ($column, $variants): void {
            $this->variants($nested, $column, $variants);
        });
    }

    /**
     * @param  non-empty-list<string>  $variants
     */
    private function variants(Builder $query, string $column, array $variants): void
    {
        foreach ($variants as $variant) {
            $query->orWhere($column, 'like', '%'.$variant.'%');
        }
    }
}
