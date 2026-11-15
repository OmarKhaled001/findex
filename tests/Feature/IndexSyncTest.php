<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use OmarKhaled\Findex\Reindexer;
use OmarKhaled\Findex\Tests\Fixtures\Article;
use OmarKhaled\Findex\Tests\Fixtures\Category;
use OmarKhaled\Findex\Tests\Fixtures\FeaturedProduct;
use OmarKhaled\Findex\Tests\Fixtures\Memo;
use OmarKhaled\Findex\Tests\Fixtures\Note;
use OmarKhaled\Findex\Tests\Fixtures\Post;
use OmarKhaled\Findex\Tests\Fixtures\Product;
use OmarKhaled\Findex\Tests\Fixtures\Status;
use OmarKhaled\Findex\Tests\Fixtures\Unconfigured;

function storedIndex(Model $model, string $column = 'search_index'): ?string
{
    return DB::table($model->getTable())->where('id', $model->getKey())->value($column);
}

describe('saving', function () {
    it('builds the index when a model is created', function () {
        $product = Product::create([
            'name' => 'أَحْمَد Café',
            'sku' => 'SKU-١٢٣',
            'description' => 'مدرسة   كبيرة!',
        ]);

        expect($product->search_index)->toBe('احمد cafe sku 123 مدرسه كبيره')
            ->and(storedIndex($product))->toBe('احمد cafe sku 123 مدرسه كبيره');
    });

    it('skips null and empty columns', function () {
        $product = Product::create(['name' => 'Phone', 'sku' => null, 'description' => '']);

        expect($product->search_index)->toBe('phone');
    });

    it('rebuilds the index when a source column changes', function () {
        $product = Product::create(['name' => 'Old name', 'sku' => 'A1']);

        $product->update(['name' => 'New name']);

        expect(storedIndex($product))->toBe('new name a1')
            ->and(Product::findex('new')->count())->toBe(1)
            ->and(Product::findex('old')->count())->toBe(0);
    });

    it('leaves the index alone when no source column changed', function () {
        $category = Category::create(['name' => 'Phones']);

        DB::table('categories')->where('id', $category->id)->update(['search_index' => 'sentinel']);

        $category = Category::find($category->id);
        $category->update(['parent_id' => 5]);

        expect($category->syncFindexIndex())->toBeFalse()
            ->and(storedIndex($category))->toBe('sentinel');
    });

    it('keeps syncing a model it created without setting every column', function () {
        // "sku" and "description" were never set: on this instance that is not a partial select.
        $product = Product::create(['name' => 'Phone']);

        $product->update(['name' => 'Tablet']);
        expect(storedIndex($product))->toBe('tablet');

        $product->update(['sku' => 'T-1']);
        expect(storedIndex($product))->toBe('tablet t 1');
    });

    it('does not wipe the index of a partially selected model', function () {
        $product = Product::create(['name' => 'Samsung Galaxy', 'sku' => 'SM-1', 'description' => 'Phone']);

        Product::query()->select(['id', 'stock'])->find($product->id)->update(['stock' => 3]);

        expect(storedIndex($product))->toBe('samsung galaxy sm 1 phone');
    });

    it('does not rebuild from an incomplete set of source columns', function () {
        $product = Product::create(['name' => 'Samsung Galaxy', 'sku' => 'SM-1', 'description' => 'Phone']);

        $partial = Product::query()->select(['id', 'name'])->find($product->id);
        $partial->update(['name' => 'Renamed']);

        // "sku" and "description" were not loaded: rebuilding would have dropped them.
        expect($partial->syncFindexIndex())->toBeFalse()
            ->and(storedIndex($product))->toBe('samsung galaxy sm 1 phone');
    });

    it('rebuilds when every source column was selected, even without the index column', function () {
        $product = Product::create(['name' => 'Samsung Galaxy', 'sku' => 'SM-1', 'description' => 'Phone']);

        Product::query()
            ->select(['id', 'name', 'sku', 'description', 'category_id'])
            ->find($product->id)
            ->update(['name' => 'Renamed']);

        expect(storedIndex($product))->toBe('renamed sm 1 phone');
    });

    it('can be forced to rebuild', function () {
        $product = Product::create(['name' => 'Phone']);

        DB::table('products')->where('id', $product->id)->update(['search_index' => 'sentinel']);

        $product = Product::find($product->id);

        expect($product->syncFindexIndex(force: true))->toBeTrue()
            ->and($product->search_index)->toBe('phone')
            ->and($product->isDirty('search_index'))->toBeTrue()
            ->and(storedIndex($product))->toBe('sentinel');

        $product->save();

        expect(storedIndex($product))->toBe('phone');
    });

    it('does not sync when events are muted', function () {
        $product = Product::create(['name' => 'Phone']);

        $product->name = 'Tablet';
        $product->saveQuietly();

        expect(storedIndex($product))->toBe('phone');
    });
});

describe('configuration', function () {
    it('reads the columns from the attribute', function () {
        expect((new Product)->findexColumns())->toBe(['name', 'sku', 'description', 'category.name'])
            ->and((new Product)->findexIndexColumn())->toBe('search_index');
    });

    it('inherits the attribute from a parent class', function () {
        $product = FeaturedProduct::create(['name' => 'Inherited']);

        expect($product->findexColumns())->toBe(['name', 'sku', 'description', 'category.name'])
            ->and($product->search_index)->toBe('inherited')
            ->and(FeaturedProduct::findex('inherited')->count())->toBe(1);
    });

    it('uses the index column named by the attribute', function () {
        $post = Post::create(['title' => 'Hello']);

        expect($post->findexIndexColumn())->toBe('needle')
            ->and(storedIndex($post, 'needle'))->toBe('hello')
            ->and(Post::findex('hello')->toSql())->toBe('select * from "posts" where ("posts"."needle" like ?)');
    });

    it('uses the configured index column', function () {
        $this->configureFindex(['column' => 'body']);

        $note = Note::create(['title' => 'Configured']);

        expect($note->findexIndexColumn())->toBe('body')
            ->and(storedIndex($note, 'body'))->toBe('configured');
    });

    it('accepts columns from an overridden findexColumns method', function () {
        $note = Note::create(['title' => 'Meeting', 'body' => 'Notes']);

        expect($note->search_index)->toBe('meeting notes');

        $note->update(['body' => 'Minutes']);

        expect(storedIndex($note))->toBe('meeting minutes');
    });

    it('accepts text from an overridden toFindexText method', function () {
        $memo = Memo::create(['title' => 'Budget']);

        expect($memo->search_index)->toBe('memo budget')
            ->and(Memo::findex('memo')->count())->toBe(1);

        // Without declared columns the sources are unknown, so every save rebuilds.
        $memo->update(['title' => 'Forecast']);

        expect(storedIndex($memo))->toBe('memo forecast');
    });

    it('explains what is missing when nothing is configured', function () {
        Unconfigured::create(['title' => 'Nothing']);
    })->throws(
        LogicException::class,
        'Model ['.Unconfigured::class.'] uses the Findexable trait but has nothing to index.',
    );
});

describe('relations', function () {
    it('indexes a relation column given in dot notation', function () {
        $category = Category::create(['name' => 'هواتف ذكيّة']);
        $product = Product::create(['name' => 'Galaxy', 'category_id' => $category->id]);

        expect(storedIndex($product))->toBe('galaxy هواتف ذكيه')
            ->and(Product::findex('هواتف')->pluck('name')->all())->toBe(['Galaxy']);
    });

    it('ignores a relation that is not set', function () {
        expect(Product::create(['name' => 'Orphan'])->search_index)->toBe('orphan');
    });

    it('rebuilds on every save because a relation cannot be checked for changes', function () {
        $category = Category::create(['name' => 'Phones']);
        $product = Product::create(['name' => 'Galaxy', 'category_id' => $category->id]);

        $category->update(['name' => 'Tablets']);

        // Saving the parent does not touch its children ...
        expect(storedIndex($product))->toBe('galaxy phones');

        // ... saving the child does, even though none of its own columns changed.
        Product::find($product->id)->update(['stock' => 1]);

        expect(storedIndex($product))->toBe('galaxy tablets');
    });

    it('reloads a parent whose foreign key changed after it was loaded', function () {
        $phones = Category::create(['name' => 'Phones']);
        $tablets = Category::create(['name' => 'Tablets']);
        $product = Product::create(['name' => 'Galaxy', 'category_id' => $phones->id]);

        $product = Product::with('category')->find($product->id);
        $product->update(['category_id' => $tablets->id]);

        expect(storedIndex($product))->toBe('galaxy tablets');

        $product->update(['category_id' => null]);

        expect(storedIndex($product))->toBe('galaxy');
    });

    it('keeps a parent that was associated in memory', function () {
        $phones = Category::create(['name' => 'Phones']);
        $product = Product::create(['name' => 'Galaxy']);

        DB::enableQueryLog();

        $product->category()->associate($phones)->save();

        expect(storedIndex($product))->toBe('galaxy phones')
            ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'from "categories"')))
            ->toBeEmpty();
    });

    it('still indexes relations when lazy loading is prevented', function () {
        $category = Category::create(['name' => 'Phones']);
        Product::create(['name' => 'Galaxy', 'category_id' => $category->id]);
        Product::create(['name' => 'Pixel', 'category_id' => $category->id]);

        Model::preventLazyLoading();

        try {
            Product::all()->each(fn (Product $product) => $product->update(['stock' => 2]));
        } finally {
            Model::preventLazyLoading(false);
        }

        expect(Product::findex('phones')->count())->toBe(2);
    });

    it('works in eloquent strict mode', function () {
        $category = Category::create(['name' => 'Phones']);
        Product::create(['name' => 'Galaxy', 'category_id' => $category->id]);
        Product::create(['name' => 'Pixel', 'category_id' => $category->id]);

        Model::shouldBeStrict();

        try {
            // Full models: the relation is loaded explicitly, never lazily.
            Product::all()->each(fn (Product $product) => $product->update(['stock' => 2]));

            // Partial models: the sync is skipped before any missing attribute is read.
            Product::query()->select(['id', 'stock'])->get()
                ->each(fn (Product $product) => $product->update(['stock' => 3]));

            $created = Product::create(['name' => 'Xperia']);
            $created->update(['name' => 'Xperia 5']);

            expect(app(Reindexer::class)->reindex(Product::class))->toBe(0);
        } finally {
            Model::shouldBeStrict(false);
        }

        expect(Product::findex('phones')->count())->toBe(2)
            ->and(Product::findex('xperia 5')->count())->toBe(1)
            ->and(DB::table('products')->where('stock', 3)->count())->toBe(2);
    });

    it('walks nested relation paths', function () {
        $root = Category::create(['name' => 'Electronics']);
        $child = Category::create(['name' => 'Phones', 'parent_id' => $root->id]);

        $product = new class extends Product
        {
            protected $table = 'products';

            public function findexColumns(): array
            {
                return ['name', 'category.parent.name'];
            }
        };

        $product->fill(['name' => 'Galaxy', 'category_id' => $child->id])->save();

        expect(storedIndex($product))->toBe('galaxy electronics');
    });
});

describe('values', function () {
    it('extracts accessors, enums, array paths, json, and wildcard relations', function () {
        $post = Post::create([
            'title' => 'Hello',
            'first_name' => 'Omar',
            'last_name' => 'Khaled',
            'status' => Status::Published,
            'meta' => ['keywords' => ['php', ['nested' => 'laravel']], 'ignored' => 'secret'],
            'raw' => '{"en":"World","ar":"عالم","count":7,"flag":true}',
            'published' => true,
        ]);

        $post->tags()->createMany([['name' => 'News'], ['name' => 'أخبار']]);
        $post->refresh()->save();

        expect(storedIndex($post, 'needle'))
            ->toBe('hello omar khaled published php laravel world عالم 7 news اخبار');
    });

    it('skips booleans and nested models', function () {
        $post = Post::create(['title' => 'Only title', 'published' => true]);
        $post->tags()->create(['name' => 'Tagged']);

        $post->refresh()->save();

        // "published" is a bool and the bare "tags" column is a collection of models.
        expect(storedIndex($post, 'needle'))->toBe('only title tagged');
    });

    it('always rebuilds when a source is an accessor', function () {
        $post = Post::create(['title' => 'Hello', 'first_name' => 'Omar']);

        // "first_name" is not a declared source, the "author" accessor built from it is.
        $post->update(['first_name' => 'Ali']);

        expect(storedIndex($post, 'needle'))->toBe('hello ali');
    });

    it('keeps text that only looks like json', function () {
        $note = Note::create(['title' => '[Sale] {50% off}', 'body' => 'true null 123']);

        expect($note->search_index)->toBe('sale 50 off true null 123');
    });

    it('indexes every translation of a translatable attribute', function () {
        app()->setLocale('en');

        $article = Article::create([
            'title' => ['en' => 'Hello World', 'ar' => 'مرحباً بالعالم'],
            'body' => 'Body',
        ]);

        // Reading the attribute gives the current locale only ...
        expect($article->title)->toBe('Hello World')
            // ... the index holds every locale.
            ->and(storedIndex($article))->toBe('hello world مرحبا بالعالم body')
            ->and(Article::findex('مرحبا')->count())->toBe(1)
            ->and(Article::findex('hello')->count())->toBe(1);
    });

    it('rebuilds a translatable attribute when a translation changes', function () {
        $article = Article::create(['title' => ['en' => 'Hello', 'ar' => 'مرحبا']]);

        $article->update(['title' => ['en' => 'Goodbye', 'ar' => 'وداعا']]);

        expect(storedIndex($article))->toBe('goodbye وداعا');
    });
});
