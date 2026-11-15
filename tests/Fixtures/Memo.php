<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use OmarKhaled\Findex\Concerns\Findexable;

/**
 * Configured by overriding toFindexText() only.
 */
#[Table('notes', timestamps: false)]
#[Unguarded]
class Memo extends Model
{
    use Findexable;

    public function toFindexText(): string
    {
        return 'MEMO '.$this->title;
    }
}
