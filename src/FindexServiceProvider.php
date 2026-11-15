<?php

declare(strict_types=1);

namespace OmarKhaled\Findex;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;
use OmarKhaled\Findex\Console\ReindexCommand;
use OmarKhaled\Findex\Drivers\FullTextDriver;
use Override;

final class FindexServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/findex.php', 'findex');

        // One instance per worker: the translation tables are compiled once. The
        // service captures configuration only; it reads the locale on every call.
        $this->app->singleton(Findex::class, static fn (Application $app): Findex => new Findex(
            (array) $app->make('config')->get('findex', []),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/findex.php' => $this->app->configPath('findex.php'),
            ], 'findex-config');

            $this->commands([ReindexCommand::class]);
        }

        $this->registerQueryMacro();
        $this->registerSchemaMacro();
    }

    /**
     * DB::table('products')->whereFindex('search_index', $term)->get();
     *
     * Eloquent builders forward the call to their query builder.
     */
    private function registerQueryMacro(): void
    {
        Builder::macro('whereFindex', function (string $column, ?string $term, ?string $driver = null): Builder {
            /** @var Builder $this */
            return Container::getInstance()->make(Findex::class)->apply($this, $column, $term, $driver);
        });
    }

    /**
     * $table->findex();                 a nullable text column
     * $table->findex(fullText: true);   + FULLTEXT index, on MySQL and MariaDB
     * $table->findex(trigram: true);    + GIN trigram index, on PostgreSQL
     *
     * An index the connection cannot create is skipped, so one migration runs
     * on every database. The trigram index needs "CREATE EXTENSION pg_trgm".
     */
    private function registerSchemaMacro(): void
    {
        Blueprint::macro('findex', function (?string $column = null, bool $fullText = false, bool $trigram = false): ColumnDefinition {
            /** @var Blueprint $this */
            $column ??= Container::getInstance()->make(Findex::class)->column();

            $definition = $this->text($column)->nullable();

            $driver = $this->connection->getDriverName();

            if ($fullText && in_array($driver, FullTextDriver::SUPPORTED, true)) {
                $this->fullText($column);
            }

            if ($trigram && $driver === 'pgsql') {
                $this->index(
                    [new Expression($this->grammar->wrap($column).' gin_trgm_ops')],
                    $this->createIndexName('trigram', [$column]),
                    'gin',
                );
            }

            return $definition;
        });
    }
}
