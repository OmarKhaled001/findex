<?php

declare(strict_types=1);

namespace OmarKhaled\Findex;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use OmarKhaled\Findex\Concerns\Findexable;
use OmarKhaled\Findex\Support\Relations;

/**
 * Rebuilds the index column of rows that already exist.
 *
 * Run it after adding Findex to a table that has data, after changing the
 * indexed columns of a model, and after changing the normalization options.
 */
final readonly class Reindexer
{
    /**
     * Rebuild the index column of every row of a model.
     *
     * Rows are read in chunks by primary key, without global scopes, so
     * soft-deleted rows are included. Each chunk is written in one transaction,
     * straight through the query builder: no model events fire and "updated_at"
     * does not move. Rows whose index is already correct are not written.
     *
     * @param  class-string<Model>  $model
     * @param  (Closure(int): mixed)|null  $progress  Called after every chunk with the number of rows read.
     * @return int The number of rows that were updated.
     */
    public function reindex(string $model, int $chunk = 1000, ?Closure $progress = null): int
    {
        $instance = $this->instance($model);

        $column = $instance->findexIndexColumn();
        $key = $instance->getKeyName();
        $connection = $instance->getConnection();
        $updated = 0;

        $this->query($instance)
            ->with(Relations::of($instance, $instance->findexColumns()))
            ->chunkById(max(1, $chunk), static function (Collection $models) use ($connection, $column, $key, $progress, &$updated): void {
                $connection->transaction(static function () use ($models, $column, $key, &$updated): void {
                    foreach ($models as $model) {
                        /** @var Model&Findexable $model */
                        $index = $model->buildFindexIndex();

                        if ($index === $model->getRawOriginal($column)) {
                            continue;
                        }

                        $model->newModelQuery()->toBase()
                            ->where($key, $model->getKey())
                            ->update([$column => $index]);

                        $updated++;
                    }
                });

                if ($progress !== null) {
                    $progress($models->count());
                }
            });

        return $updated;
    }

    /**
     * The number of rows reindex() will read.
     *
     * @param  class-string<Model>  $model
     */
    public function count(string $model): int
    {
        return $this->query($this->instance($model))->count();
    }

    /**
     * @return Builder<Model>
     */
    private function query(Model $instance): Builder
    {
        return $instance->newQuery()->withoutGlobalScopes();
    }

    /**
     * @return Model&Findexable
     */
    private function instance(string $model): Model
    {
        if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException(sprintf('[%s] is not an Eloquent model.', $model));
        }

        if (! in_array(Findexable::class, class_uses_recursive($model), true)) {
            throw new InvalidArgumentException(sprintf(
                'Model [%s] does not use the [%s] trait.', $model, Findexable::class,
            ));
        }

        return new $model;
    }
}
