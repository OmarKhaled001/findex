<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Works out which relations the dot-notation columns of a model go through.
 *
 * @internal
 */
final class Relations
{
    /** @var array<string, list<string>> */
    private static array $paths = [];

    /** @var array<class-string, array<string, array{string, string}|false>> */
    private static array $keys = [];

    /**
     * The eager-loadable relation paths behind the given columns.
     *
     * "category.name" gives "category", "tags.*.name" gives "tags" and
     * "category.parent.name" gives "category.parent". Paths into array or JSON
     * attributes give nothing.
     *
     * @param  list<string>  $columns
     * @return list<string>
     */
    public static function of(Model $model, array $columns): array
    {
        return self::$paths[$model::class.'|'.implode('|', $columns)] ??= self::resolve($model, $columns);
    }

    /**
     * Make sure every relation behind the given columns is loaded and current.
     *
     * Loading explicitly, instead of leaving it to lazy loading, keeps models
     * indexable under Model::preventLazyLoading().
     *
     * @param  list<string>  $columns
     */
    public static function load(Model $model, array $columns): void
    {
        $paths = self::of($model, $columns);

        if ($paths === []) {
            return;
        }

        foreach ($paths as $path) {
            $name = strstr($path, '.', true) ?: $path;

            if ($model->relationLoaded($name) && self::isStale($model, $name)) {
                $model->unsetRelation($name);
            }
        }

        $model->loadMissing($paths);
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private static function resolve(Model $model, array $columns): array
    {
        $paths = [];

        foreach ($columns as $column) {
            if (! str_contains($column, '.')) {
                continue;
            }

            $path = [];
            $current = $model;

            foreach (explode('.', $column) as $segment) {
                if ($segment === '*') {
                    continue;
                }

                $relation = self::relation($current, $segment);

                if ($relation === null) {
                    break;
                }

                $path[] = $segment;

                // A polymorphic parent can be any model: there is nothing to walk into.
                if ($relation instanceof MorphTo) {
                    break;
                }

                $current = $relation->getRelated();
            }

            if ($path !== []) {
                $paths[implode('.', $path)] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * @return Relation<*, *, *>|null
     */
    private static function relation(Model $model, string $name): ?Relation
    {
        // Same guard as Eloquent: methods of the base model are never relations.
        if ($name === '' || method_exists(Model::class, $name) || ! $model->isRelation($name)) {
            return null;
        }

        $relation = $model->{$name}();

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * A loaded parent is stale when its foreign key was changed after it was loaded.
     */
    private static function isStale(Model $model, string $name): bool
    {
        $keys = self::$keys[$model::class][$name] ??= self::keys($model, $name);

        if ($keys === false || ! $model->isDirty($keys[0])) {
            return false;
        }

        $related = $model->getRelation($name);

        return ! $related instanceof Model
            || $related->getAttribute($keys[1]) != $model->getAttribute($keys[0]);
    }

    /**
     * The foreign key and owner key of a plain "belongs to" relation.
     *
     * @return array{string, string}|false
     */
    private static function keys(Model $model, string $name): array|false
    {
        $relation = self::relation($model, $name);

        return $relation instanceof BelongsTo && ! $relation instanceof MorphTo
            ? [$relation->getForeignKeyName(), $relation->getOwnerKeyName()]
            : false;
    }
}
