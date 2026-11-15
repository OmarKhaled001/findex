<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use OmarKhaled\Findex\Concerns\Findexable;
use OmarKhaled\Findex\Reindexer;
use OmarKhaled\Findex\Tests\Fixtures\Category;
use OmarKhaled\Findex\Tests\Fixtures\Post;
use OmarKhaled\Findex\Tests\Fixtures\Product;
use OmarKhaled\Findex\Tests\Fixtures\Tag;
use Symfony\Component\Console\Exception\RuntimeException;

/**
 * Insert rows the way they exist before the package is installed: without an index.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function legacyProducts(array $rows): void
{
    foreach ($rows as $row) {
        DB::table('products')->insert($row + ['created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00']);
    }
}

/** @return array<int, string|null> */
function productIndexes(): array
{
    return DB::table('products')->orderBy('id')->pluck('search_index', 'id')->all();
}

describe('Reindexer', function () {
    it('builds the index of existing rows and returns how many were updated', function () {
        legacyProducts([
            ['name' => 'أحمد', 'sku' => 'SKU-١'],
            ['name' => 'Café'],
            ['name' => 'مدرسة'],
        ]);

        expect(Product::findex('احمد')->count())->toBe(0);

        $updated = app(Reindexer::class)->reindex(Product::class);

        expect($updated)->toBe(3)
            ->and(productIndexes())->toBe([1 => 'احمد sku 1', 2 => 'cafe', 3 => 'مدرسه'])
            ->and(Product::findex('احمد')->count())->toBe(1);
    });

    it('skips rows whose index is already correct', function () {
        legacyProducts([['name' => 'One'], ['name' => 'Two']]);
        Product::create(['name' => 'Three']);

        $reindexer = app(Reindexer::class);

        expect($reindexer->reindex(Product::class))->toBe(2)
            ->and($reindexer->reindex(Product::class))->toBe(0);

        DB::table('products')->where('id', 2)->update(['search_index' => 'stale']);

        DB::enableQueryLog();

        expect($reindexer->reindex(Product::class))->toBe(1)
            ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_starts_with($sql, 'update')))
            ->toHaveCount(1)
            ->and(productIndexes()[2])->toBe('two');
    });

    it('includes soft-deleted rows and ignores global scopes', function () {
        legacyProducts([
            ['name' => 'Visible'],
            ['name' => 'Trashed', 'deleted_at' => '2021-01-01 00:00:00'],
        ]);

        Product::addGlobalScope('hidden', fn ($query) => $query->whereRaw('1 = 0'));

        try {
            expect(app(Reindexer::class)->count(Product::class))->toBe(2)
                ->and(app(Reindexer::class)->reindex(Product::class))->toBe(2);
        } finally {
            Product::clearBootedModels();
        }

        expect(productIndexes())->toBe([1 => 'visible', 2 => 'trashed']);
    });

    it('fires no model events and does not touch updated_at', function () {
        legacyProducts([['name' => 'Quiet']]);

        $events = 0;
        Product::saving(function () use (&$events) {
            $events++;
        });
        Product::updating(function () use (&$events) {
            $events++;
        });

        app(Reindexer::class)->reindex(Product::class);

        expect($events)->toBe(0)
            ->and(DB::table('products')->value('updated_at'))->toBe('2020-01-01 00:00:00')
            ->and(productIndexes())->toBe([1 => 'quiet']);
    });

    it('reads in chunks by id and reports progress', function () {
        legacyProducts(array_map(fn (int $i) => ['name' => 'Product '.$i], range(1, 5)));

        $progress = [];

        DB::enableQueryLog();

        $updated = app(Reindexer::class)->reindex(Product::class, 2, function (int $read) use (&$progress) {
            $progress[] = $read;
        });

        $selects = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($sql) => str_starts_with($sql, 'select * from "products"'))
            ->values();

        expect($updated)->toBe(5)
            ->and($progress)->toBe([2, 2, 1])
            ->and($selects)->toHaveCount(3)
            ->and($selects[1])->toBe('select * from "products" where "id" > ? order by "id" asc limit 2');
    });

    it('wraps every chunk in a transaction', function () {
        legacyProducts(array_map(fn (int $i) => ['name' => 'Product '.$i], range(1, 5)));

        $transactions = 0;
        DB::listen(fn () => null);
        Event::listen(
            TransactionCommitted::class,
            function () use (&$transactions) {
                $transactions++;
            },
        );

        app(Reindexer::class)->reindex(Product::class, 2);

        expect($transactions)->toBe(3);
    });

    it('eager loads the relations behind dot-notation columns', function () {
        $phones = Category::create(['name' => 'Phones']);
        $tablets = Category::create(['name' => 'Tablets']);

        legacyProducts([
            ['name' => 'Galaxy', 'category_id' => $phones->id],
            ['name' => 'Pixel', 'category_id' => $phones->id],
            ['name' => 'iPad', 'category_id' => $tablets->id],
        ]);

        DB::enableQueryLog();

        app(Reindexer::class)->reindex(Product::class);

        $categoryQueries = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($sql) => str_contains($sql, 'from "categories"'));

        expect($categoryQueries)->toHaveCount(1)
            ->and(productIndexes())->toBe([1 => 'galaxy phones', 2 => 'pixel phones', 3 => 'ipad tablets']);
    });

    it('picks up changes of a parent model', function () {
        $category = Category::create(['name' => 'Phones']);
        Product::create(['name' => 'Galaxy', 'category_id' => $category->id]);

        $category->update(['name' => 'Smartphones']);

        expect(app(Reindexer::class)->reindex(Product::class))->toBe(1)
            ->and(productIndexes())->toBe([1 => 'galaxy smartphones']);
    });

    it('eager loads wildcard relations and writes a custom index column', function () {
        DB::table('posts')->insert([['title' => 'First'], ['title' => 'Second']]);
        Tag::insert([
            ['post_id' => 1, 'name' => 'News'],
            ['post_id' => 1, 'name' => 'Tech'],
            ['post_id' => 2, 'name' => 'Sport'],
        ]);

        DB::enableQueryLog();

        $updated = app(Reindexer::class)->reindex(Post::class);

        $tagQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "tags"'));

        expect($updated)->toBe(2)
            ->and($tagQueries)->toHaveCount(1)
            ->and(DB::table('posts')->orderBy('id')->pluck('needle')->all())->toBe(['first news tech', 'second sport']);
    });

    it('returns zero for an empty table', function () {
        expect(app(Reindexer::class)->reindex(Product::class))->toBe(0)
            ->and(app(Reindexer::class)->count(Product::class))->toBe(0);
    });

    it('rejects a class that is not an eloquent model', function (string $class) {
        app(Reindexer::class)->reindex($class);
    })->with(['Missing\\Model', stdClass::class])->throws(InvalidArgumentException::class, 'is not an Eloquent model');

    it('rejects a model that does not use the trait', function () {
        app(Reindexer::class)->reindex(Tag::class);
    })->throws(InvalidArgumentException::class, 'does not use the ['.Findexable::class.'] trait');
});

describe('findex:reindex', function () {
    it('reindexes the given models and succeeds', function () {
        legacyProducts([['name' => 'أحمد'], ['name' => 'Café']]);
        DB::table('categories')->insert(['name' => 'Phones']);

        $this->artisan('findex:reindex', ['models' => [Product::class, Category::class]])
            ->expectsOutputToContain(Product::class.': updated 2 of 2 rows.')
            ->expectsOutputToContain(Category::class.': updated 1 of 1 rows.')
            ->assertExitCode(0);

        expect(productIndexes())->toBe([1 => 'احمد', 2 => 'cafe'])
            ->and(DB::table('categories')->value('search_index'))->toBe('phones');
    });

    it('reports rows that were already up to date', function () {
        legacyProducts([['name' => 'One']]);
        Product::create(['name' => 'Two']);

        $this->artisan('findex:reindex', ['models' => [Product::class]])
            ->expectsOutputToContain('updated 1 of 2 rows.')
            ->assertSuccessful();
    });

    it('accepts a chunk size', function () {
        legacyProducts(array_map(fn (int $i) => ['name' => 'Product '.$i], range(1, 5)));

        DB::enableQueryLog();

        $this->artisan('findex:reindex', ['models' => [Product::class], '--chunk' => 2])->assertSuccessful();

        $selects = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'limit 2'));

        expect($selects)->toHaveCount(3)
            ->and(array_filter(productIndexes()))->toHaveCount(5);
    });

    it('resolves short names to App\\Models', function () {
        if (! class_exists('App\\Models\\Gadget')) {
            class_alias(Product::class, 'App\\Models\\Gadget');
        }

        legacyProducts([['name' => 'Short name']]);

        $this->artisan('findex:reindex', ['models' => ['Gadget']])
            ->expectsOutputToContain('App\\Models\\Gadget: updated 1 of 1 rows.')
            ->assertSuccessful();

        expect(productIndexes())->toBe([1 => 'short name']);
    });

    it('accepts forward slashes and a leading backslash in class names', function () {
        legacyProducts([['name' => 'Slashes']]);

        $this->artisan('findex:reindex', ['models' => ['/'.str_replace('\\', '/', Product::class)]])
            ->assertSuccessful();

        expect(productIndexes())->toBe([1 => 'slashes']);
    });

    it('fails for an unknown model but still reindexes the others', function () {
        legacyProducts([['name' => 'Still indexed']]);

        $this->artisan('findex:reindex', ['models' => ['Nope', Product::class]])
            ->expectsOutputToContain('[Nope] is not an Eloquent model.')
            ->assertExitCode(1);

        expect(productIndexes())->toBe([1 => 'still indexed']);
    });

    it('fails for a model without the trait', function () {
        $this->artisan('findex:reindex', ['models' => [Tag::class]])
            ->expectsOutputToContain('does not use the')
            ->assertExitCode(1);
    });

    it('rejects an invalid chunk size', function (string $chunk) {
        legacyProducts([['name' => 'Untouched']]);

        $this->artisan('findex:reindex', ['models' => [Product::class], '--chunk' => $chunk])
            ->expectsOutputToContain('The --chunk option must be a positive integer.')
            ->assertExitCode(2);

        expect(productIndexes())->toBe([1 => null]);
    })->with(['0', '-5', 'abc']);

    it('requires at least one model', function () {
        $this->artisan('findex:reindex');
    })->throws(RuntimeException::class, 'Not enough arguments');

    it('is described in the command list', function () {
        $command = $this->app->make(Kernel::class)->all()['findex:reindex'];

        expect($command->getDescription())->toBe('Rebuild the Findex search index column of existing rows')
            ->and($command->getDefinition()->getArgument('models')->isArray())->toBeTrue()
            ->and($command->getDefinition()->getOption('chunk')->getDefault())->toBe('1000');
    });
});
