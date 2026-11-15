<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Stringable;
use UnitEnum;

/**
 * Collects the searchable text behind the source columns of a model.
 *
 * @internal
 */
final class ValueExtractor
{
    /**
     * The raw, not yet normalized text of the given columns, joined by spaces.
     *
     * @param  list<string>  $columns
     */
    public static function text(Model $model, array $columns): string
    {
        Relations::load($model, $columns);

        $pieces = [];

        foreach ($columns as $column) {
            self::collect(self::value($model, $column), $pieces);
        }

        return implode(' ', $pieces);
    }

    private static function value(Model $model, string $column): mixed
    {
        if (! str_contains($column, '.')) {
            return self::attribute($model, $column);
        }

        [$attribute, $path] = explode('.', $column, 2);

        return data_get(self::attribute($model, $attribute), $path);
    }

    /**
     * An attribute, accessor or relation of the model.
     *
     * Models that look like spatie/laravel-translatable models give every
     * translation of a translatable attribute, not only the current locale.
     */
    private static function attribute(Model $model, string $name): mixed
    {
        if (method_exists($model, 'isTranslatableAttribute')
            && method_exists($model, 'getTranslations')
            && $model->isTranslatableAttribute($name)) {
            return $model->getTranslations($name);
        }

        return $model->getAttribute($name);
    }

    /**
     * Flatten any value into plain strings.
     *
     * @param  list<string>  $pieces
     */
    private static function collect(mixed $value, array &$pieces): void
    {
        if ($value === null || is_bool($value)) {
            return;
        }

        if (is_string($value)) {
            if ($value === '') {
                return;
            }

            // A JSON document stored as text: index its values, not its syntax or keys.
            if (($value[0] === '{' || $value[0] === '[') && json_validate($value)) {
                self::collect(json_decode($value, true), $pieces);

                return;
            }

            $pieces[] = $value;

            return;
        }

        if (is_int($value) || is_float($value)) {
            $pieces[] = (string) $value;

            return;
        }

        match (true) {
            // A related model is not text. Point the column at one of its attributes instead.
            $value instanceof Model => null,
            $value instanceof BackedEnum => $pieces[] = (string) $value->value,
            $value instanceof UnitEnum => $pieces[] = $value->name,
            // Collections also have __toString(), so iterables must come before Stringable.
            is_iterable($value) => self::each($value, $pieces),
            $value instanceof Arrayable => self::each($value->toArray(), $pieces),
            $value instanceof Stringable => $pieces[] = (string) $value,
            default => null,
        };
    }

    /**
     * @param  iterable<mixed>  $values
     * @param  list<string>  $pieces
     */
    private static function each(iterable $values, array &$pieces): void
    {
        foreach ($values as $value) {
            self::collect($value, $pieces);
        }
    }
}
