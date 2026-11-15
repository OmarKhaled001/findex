<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use OmarKhaled\Findex\Facades\Findex;
use OmarKhaled\Findex\FindexServiceProvider;
use OmarKhaled\Findex\Tests\Fixtures\Category;
use OmarKhaled\Findex\Tests\Fixtures\Product;

/**
 * A blueprint on a connection that compiles SQL but can never reach a database server.
 *
 * @param  class-string<Connection>  $class
 */
function blueprint(string $class, string $driver, string $table = 'products', array $config = []): Blueprint
{
    $connection = new $class(
        static fn () => throw new RuntimeException('These tests must not open a database connection.'),
        'findex',
        '',
        ['driver' => $driver, 'name' => $driver] + $config,
    );

    $connection->useDefaultSchemaGrammar();

    return new Blueprint($connection, $table);
}

describe('whereFindex', function () {
    beforeEach(function () {
        Product::create(['name' => 'أحمد', 'sku' => 'SKU-123']);
        Product::create(['name' => 'محمد']);
        Product::create(['name' => 'Laravel']);
    });

    it('searches a plain query builder', function () {
        expect(DB::table('products')->whereFindex('search_index', 'احمد')->pluck('name')->all())->toBe(['أحمد'])
            ->and(DB::table('products')->whereFindex('search_index', '١٢٣')->pluck('name')->all())->toBe(['أحمد'])
            ->and(DB::table('products')->whereFindex('search_index', 'مشقشرثم')->pluck('name')->all())->toBe(['Laravel']);
    });

    it('follows the locale', function () {
        app()->setLocale('ar');

        expect(DB::table('products')->whereFindex('search_index', 'lpl]')->pluck('name')->all())->toBe(['محمد']);
    });

    it('returns the builder so it can be chained', function () {
        $query = DB::table('products')->whereFindex('search_index', '123')->where('sku', 'SKU-123');

        expect($query->toSql())->toBe('select * from "products" where ("search_index" like ?) and "sku" = ?')
            ->and($query->count())->toBe(1);
    });

    it('leaves the query untouched for an empty term', function () {
        expect(DB::table('products')->whereFindex('search_index', null)->toSql())->toBe('select * from "products"')
            ->and(DB::table('products')->whereFindex('search_index', '')->count())->toBe(3);
    });

    it('accepts a driver', function () {
        $query = DB::table('products')->whereFindex('search_index', 'laravel', driver: 'fulltext');

        // SQLite has no MATCH ... AGAINST, so the fulltext driver uses LIKE.
        expect($query->toSql())->toBe('select * from "products" where ("search_index" like ?)')
            ->and($query->count())->toBe(1);
    });

    it('is forwarded by eloquent builders', function () {
        Category::create(['name' => 'هواتف']);

        $query = Category::query()->whereFindex('search_index', 'هواتف');

        expect($query)->toBeInstanceOf(Builder::class)
            ->and($query->count())->toBe(1)
            ->and(Product::query()->whereFindex('products.search_index', 'محمد')->pluck('name')->all())->toBe(['محمد']);
    });
});

describe('Blueprint::findex', function () {
    it('adds a nullable text column named after the configuration', function () {
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->findex();
        });

        $column = collect(Schema::getColumns('shops'))->firstWhere('name', 'search_index');

        expect($column['type_name'])->toBe('text')->and($column['nullable'])->toBeTrue();
    });

    it('accepts a column name', function () {
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->findex('needle');
        });

        expect(Schema::hasColumn('shops', 'needle'))->toBeTrue()
            ->and(Schema::hasColumn('shops', 'search_index'))->toBeFalse();
    });

    it('uses the configured column name', function () {
        $this->configureFindex(['column' => 'haystack']);

        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->findex();
        });

        expect(Schema::hasColumn('shops', 'haystack'))->toBeTrue();
    });

    it('skips the fulltext and trigram indexes on sqlite without errors', function () {
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
            $table->findex(fullText: true, trigram: true);
        });

        expect(Schema::hasColumn('shops', 'search_index'))->toBeTrue()
            ->and(Schema::getIndexes('shops'))->toHaveCount(1)
            ->and(Schema::getIndexes('shops')[0]['primary'])->toBeTrue();
    });

    it('can be added to an existing table', function () {
        Schema::create('shops', function (Blueprint $table): void {
            $table->id();
        });

        Schema::table('shops', function (Blueprint $table): void {
            $table->findex(fullText: true);
        });

        expect(Schema::hasColumn('shops', 'search_index'))->toBeTrue();
    });

    it('returns the column definition', function () {
        $blueprint = blueprint(SQLiteConnection::class, 'sqlite');

        expect($blueprint->findex())->toBeInstanceOf(ColumnDefinition::class)
            ->and($blueprint->findex('other')->comment('Search index')->get('comment'))->toBe('Search index');
    });

    it('creates a gin trigram index on postgresql', function () {
        $blueprint = blueprint(PostgresConnection::class, 'pgsql');

        $blueprint->findex(trigram: true);

        expect($blueprint->toSql())->toBe([
            'alter table "products" add column "search_index" text null',
            'create index "products_search_index_trigram" on "products" using gin ("search_index" gin_trgm_ops)',
        ]);
    });

    it('creates the trigram index with the table when creating on postgresql', function () {
        $blueprint = blueprint(PostgresConnection::class, 'pgsql');

        $blueprint->create();
        $blueprint->id();
        $blueprint->findex('needle', trigram: true);

        expect($blueprint->toSql())->toBe([
            'create table "products" ("id" bigserial not null primary key, "needle" text null)',
            'create index "products_needle_trigram" on "products" using gin ("needle" gin_trgm_ops)',
        ]);
    });

    it('skips the fulltext index on postgresql', function () {
        $blueprint = blueprint(PostgresConnection::class, 'pgsql');

        $blueprint->findex(fullText: true);

        expect($blueprint->toSql())->toBe(['alter table "products" add column "search_index" text null']);
    });

    it('creates a fulltext index on mysql and mariadb', function (string $class, string $driver) {
        $blueprint = blueprint($class, $driver);

        $blueprint->findex(fullText: true);

        expect($blueprint->toSql())->toBe([
            'alter table `products` add `search_index` text null',
            'alter table `products` add fulltext `products_search_index_fulltext`(`search_index`)',
        ]);
    })->with([
        'mysql' => [MySqlConnection::class, 'mysql'],
        'mariadb' => [MariaDbConnection::class, 'mariadb'],
    ]);

    it('skips the trigram index on mysql', function () {
        $blueprint = blueprint(MySqlConnection::class, 'mysql');

        $blueprint->findex(trigram: true);

        expect($blueprint->toSql())->toBe(['alter table `products` add `search_index` text null']);
    });

    it('adds no index unless asked', function (string $class, string $driver, string $sql) {
        $blueprint = blueprint($class, $driver);

        $blueprint->findex();

        expect($blueprint->toSql())->toBe([$sql]);
    })->with([
        'mysql' => [MySqlConnection::class, 'mysql', 'alter table `products` add `search_index` text null'],
        'pgsql' => [PostgresConnection::class, 'pgsql', 'alter table "products" add column "search_index" text null'],
    ]);
});

describe('service container', function () {
    it('registers the service as a singleton behind the facade and its alias', function () {
        expect(app(OmarKhaled\Findex\Findex::class))->toBe(app(OmarKhaled\Findex\Findex::class))
            ->and(Findex::getFacadeRoot())->toBe(app(OmarKhaled\Findex\Findex::class))
            ->and(\Findex::normalize('أَحْمَد'))->toBe('احمد');
    });

    it('exposes the helpers through the facade', function () {
        expect(Findex::normalize('أَحْمَد'))->toBe('احمد')
            ->and(Findex::toArabicLayout('lpl]'))->toBe('محمد')
            ->and(Findex::toEnglishLayout('اثممخ'))->toBe(['hello'])
            ->and(Findex::column())->toBe('search_index');

        app()->setLocale('ar');

        expect(Findex::parse('lpl] iphone'))->toBe([['lpl', 'محمد'], ['iphone', 'هحاخيث']]);
    });

    it('reads the locale of the current container on every call', function () {
        $findex = app(OmarKhaled\Findex\Findex::class);

        app()->setLocale('ar');
        expect($findex->parse('lpl]'))->toBe([['lpl', 'محمد']]);

        app()->setLocale('en');
        expect($findex->parse('lpl]'))->toBe([['lpl']]);
    });

    it('merges the default configuration', function () {
        expect(config('findex.column'))->toBe('search_index')
            ->and(config('findex.driver'))->toBe('like')
            ->and(config('findex.fulltext.min_token_length'))->toBe(3)
            ->and(config('findex.fulltext.stopwords'))->toContain('the', 'with', 'www')->toHaveCount(35)
            ->and(config('findex.keyboard.mode'))->toBe('auto')
            ->and(config('findex.keyboard.arabic_locales'))->toBe(['ar'])
            ->and(config('findex.max_tokens'))->toBe(10)
            ->and(config('findex.normalization'))->toBe([
                'alef' => true,
                'ta_marbuta' => true,
                'alef_maqsura' => true,
                'hamza' => true,
                'diacritics' => true,
                'digits' => true,
                'latin_accents' => true,
                'strip_definite_article' => false,
                'preserve' => [],
            ]);
    });

    it('publishes the configuration under the findex-config tag', function () {
        $paths = ServiceProvider::pathsToPublish(
            FindexServiceProvider::class,
            'findex-config',
        );

        expect($paths)->toHaveCount(1)
            ->and(realpath(array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/findex.php'))
            ->and(array_values($paths)[0])->toBe(config_path('findex.php'));
    });

    it('keeps working with a partial published configuration', function () {
        // An older published file may lack keys that were added later.
        config()->set('findex', ['column' => 'search_index']);
        app()->forgetInstance(OmarKhaled\Findex\Findex::class);

        Product::create(['name' => 'أحمد']);

        expect(Product::findex('احمد')->count())->toBe(1);
    });
});
