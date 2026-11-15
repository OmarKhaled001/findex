<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use OmarKhaled\Findex\Concerns\Findexable;

/**
 * Uses the trait but declares nothing to index.
 */
#[Table('notes', timestamps: false)]
#[Unguarded]
class Unconfigured extends Model
{
    use Findexable;
}
