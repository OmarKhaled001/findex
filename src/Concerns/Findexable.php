<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Concerns;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use OmarKhaled\Findex\Findex;
use OmarKhaled\Findex\Support\AttributeReader;
use OmarKhaled\Findex\Support\ValueExtractor;

/**
 * Keeps a normalized search index column in sync and adds the findex() scope.
 *
 * @mixin Model
 */
trait Findexable
{
    /**
     * Rebuild the index column whenever the model is saved.
     */
    public static function bootFindexable(): void
    {
        static::saving(static function (Model $model): void {
            /** @var Model&Findexable $model */
            $model->syncFindexIndex();
        });
    }

    /**
     * The columns that feed the index.
     *
     * Read from the #[FindexColumns] attribute. Override this method to build the list yourself.
     *
     * @return list<string>
     */
    public function findexColumns(): array
    {
        return AttributeReader::for(static::class)?->columns ?? [];
    }

    /**
     * The column that stores the index.
     */
    public function findexIndexColumn(): string
    {
        return AttributeReader::for(static::class)?->index
            ?? Container::getInstance()->make(Findex::class)->column();
    }

    /**
     * The raw text to index, before normalization.
     *
     * Override this method to take full control of what is searchable. Keep
     * findexColumns() accurate if you do, so the index is only rebuilt when
     * one of its sources changed.
     */
    public function toFindexText(): string
    {
        $columns = $this->findexColumns();

        if ($columns === []) {
            throw new LogicException(sprintf(
                'Model [%s] uses the Findexable trait but has nothing to index. '
                ."Add the #[FindexColumns(['name', ...])] attribute to the class, "
                .'or override findexColumns() or toFindexText().',
                static::class,
            ));
        }

        return ValueExtractor::text($this, $columns);
    }

    /**
     * The normalized value of the index column for the current state of the model.
     */
    public function buildFindexIndex(): string
    {
        return Container::getInstance()->make(Findex::class)->normalize($this->toFindexText());
    }

    /**
     * Rebuild the index column in memory when one of its sources changed.
     *
     * The model is not saved. Returns whether the index was rebuilt.
     *
     * @param  bool  $force  Rebuild even when nothing seems to have changed.
     */
    public function syncFindexIndex(bool $force = false): bool
    {
        if (! $force && ! $this->shouldSyncFindexIndex()) {
            return false;
        }

        $this->setAttribute($this->findexIndexColumn(), $this->buildFindexIndex());

        return true;
    }

    /**
     * Search the index column.
     *
     *   Product::findex($request->q)->paginate();
     *   Product::findex($q, driver: 'fulltext')->get();
     *
     * @param  Builder<static>  $query
     * @param  string|null  $driver  "like" or "fulltext". Defaults to the configured driver.
     */
    #[Scope]
    protected function findex(Builder $query, ?string $term, ?string $driver = null): void
    {
        Container::getInstance()->make(Findex::class)->apply(
            $query->getQuery(),
            $query->qualifyColumn($this->findexIndexColumn()),
            $term,
            $driver,
        );
    }

    /**
     * Whether saving the model has to rebuild the index.
     */
    protected function shouldSyncFindexIndex(): bool
    {
        if (! $this->exists) {
            return true;
        }

        $columns = $this->findexColumns();

        // A custom toFindexText() without columns: its sources are unknown.
        if ($columns === []) {
            return true;
        }

        $attributes = $this->getAttributes();
        $loaded = [];
        $computed = false;

        foreach ($columns as $column) {
            $name = strstr($column, '.', true) ?: $column;

            if (array_key_exists($name, $attributes)) {
                $loaded[] = $name;
            } elseif ($this->hasAnyGetMutator($name) || $this->isRelation($name)) {
                $computed = true;
            } elseif (! $this->wasRecentlyCreated) {
                // A real column that was not selected. Rebuilding now would index
                // only part of the model, so the stored index is left alone. On a
                // model created by this instance a missing column was simply never set.
                return false;
            }
        }

        // Accessors and relations cannot be checked for changes: always rebuild.
        return $computed || ($loaded !== [] && $this->isDirty($loaded));
    }
}
