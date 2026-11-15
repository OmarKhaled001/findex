<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use OmarKhaled\Findex\Attributes\FindexColumns;
use OmarKhaled\Findex\Concerns\Findexable;
use Override;

/**
 * Behaves like a spatie/laravel-translatable model, without the dependency:
 * reading "title" gives the translation of the current locale only.
 */
#[Table('articles', timestamps: false)]
#[Unguarded]
#[FindexColumns(['title', 'body'])]
class Article extends Model
{
    use Findexable;

    /** @var list<string> */
    public array $translatable = ['title'];

    public function isTranslatableAttribute(string $key): bool
    {
        return in_array($key, $this->translatable, true);
    }

    /** @return array<string, string> */
    public function getTranslations(string $key): array
    {
        return json_decode($this->getAttributes()[$key] ?? '{}', true) ?: [];
    }

    #[Override]
    public function getAttributeValue($key)
    {
        if (! $this->isTranslatableAttribute($key)) {
            return parent::getAttributeValue($key);
        }

        return $this->getTranslations($key)[app()->getLocale()] ?? '';
    }

    #[Override]
    public function setAttribute($key, $value)
    {
        if ($this->isTranslatableAttribute($key) && is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return parent::setAttribute($key, $value);
    }
}
