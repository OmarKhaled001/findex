<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use OmarKhaled\Findex\Concerns\Findexable;

/**
 * Configured by overriding findexColumns() instead of using the attribute.
 */
#[Table('notes', timestamps: false)]
#[Unguarded]
class Note extends Model
{
    use Findexable;

    public function findexColumns(): array
    {
        return ['title', 'body'];
    }
}
