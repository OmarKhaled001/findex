<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Facades;

use Illuminate\Support\Facades\Facade;
use Override;

/**
 * @method static string normalize(?string $text)
 * @method static list<list<string>> parse(?string $term)
 * @method static string toArabicLayout(string $text)
 * @method static list<string> toEnglishLayout(string $text)
 * @method static \Illuminate\Database\Query\Builder apply(\Illuminate\Database\Query\Builder $query, string $column, ?string $term, ?string $driver = null)
 * @method static string column()
 * @method static \OmarKhaled\Findex\Normalizer normalizer()
 *
 * @see \OmarKhaled\Findex\Findex
 */
final class Findex extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return \OmarKhaled\Findex\Findex::class;
    }
}
