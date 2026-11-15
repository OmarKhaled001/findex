<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Drivers;

use Illuminate\Database\Query\Builder;

/**
 * Turns parsed word groups into where clauses.
 *
 * A driver always receives an empty nested query, so everything it adds ends
 * up inside one pair of parentheses.
 */
interface Driver
{
    /**
     * Every group is an AND constraint; the variants inside a group are OR'd.
     *
     * @param  non-empty-list<non-empty-list<string>>  $groups
     */
    public function apply(Builder $query, string $column, array $groups): void;
}
