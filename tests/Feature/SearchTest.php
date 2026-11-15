<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use OmarKhaled\Findex\Tests\Fixtures\Category;
use OmarKhaled\Findex\Tests\Fixtures\Product;

function product(string $name, array $attributes = []): Product
{
    return Product::create(['name' => $name] + $attributes);
}

/** @return list<string> */
function names(string $term, ?string $driver = null): array
{
    return Product::findex($term, $driver)->orderBy('id')->pluck('name')->all();
}

it('finds text whatever form it was stored or typed in', function (string $stored, string $term) {
    product($stored);
    product('غير مطابق');

    expect(names($term))->toBe([$stored]);
})->with([
    'alef: plain finds hamza' => ['أحمد', 'احمد'],
    'alef: hamza finds plain' => ['احمد', 'أحمد'],
    'ta marbuta: heh finds ta marbuta' => ['مدرسة', 'مدرسه'],
    'ta marbuta: ta marbuta finds heh' => ['مدرسه', 'مدرسة'],
    'diacritics: plain finds vocalized' => ['مُحَمَّد', 'محمد'],
    'diacritics: vocalized finds plain' => ['محمد', 'مُحَمَّد'],
    'alef maqsura: yeh finds alef maqsura' => ['مستشفى', 'مستشفي'],
    'alef maqsura: alef maqsura finds yeh' => ['مستشفي', 'مستشفى'],
    'latin accents: plain finds accented' => ['Café', 'cafe'],
    'latin accents: accented finds plain' => ['Cafe', 'CAFÉ'],
    'tatweel' => ['محمد', 'محـــمد'],
    'persian yeh' => ['علي', 'علی'],
    'case' => ['Laravel', 'LARAVEL'],
    'part of a word' => ['Smartphone', 'phone'],
]);

it('finds words in any order', function () {
    product('Red Samsung Galaxy Phone');
    product('Blue Samsung Galaxy Phone');

    expect(names('phone red'))->toBe(['Red Samsung Galaxy Phone'])
        ->and(names('galaxy samsung'))->toHaveCount(2)
        ->and(names('هاتف'))->toBe([]);
});

it('requires every word to match', function () {
    product('Red Samsung Galaxy Phone');

    expect(names('red nokia'))->toBe([])
        ->and(names('red samsung'))->toHaveCount(1);
});

it('finds western digits with arabic digits', function () {
    product('Keyboard', ['sku' => 'SKU-123']);
    product('Mouse', ['sku' => 'SKU-456']);

    expect(names('١٢٣'))->toBe(['Keyboard'])
        ->and(names('۴۵۶'))->toBe(['Mouse'])
        ->and(names('sku-123'))->toBe(['Keyboard']);
});

it('finds arabic digits with western digits', function () {
    product('عرض ٢٠٢٤');

    expect(names('2024'))->toBe(['عرض ٢٠٢٤']);
});

it('corrects latin keystrokes on an arabic locale only', function () {
    product('محمد');

    app()->setLocale('ar');
    expect(names('lpl]'))->toBe(['محمد']);

    app()->setLocale('en');
    expect(names('lpl]'))->toBe([]);
});

it('corrects arabic keystrokes on a non-arabic locale only', function () {
    product('Laravel');

    app()->setLocale('en');
    expect(names('مشقشرثم'))->toBe(['Laravel']);

    app()->setLocale('ar');
    expect(names('مشقشرثم'))->toBe([]);
});

it('normalizes the corrected word too', function () {
    product('أَحْمَد');
    product('مدرسة');

    app()->setLocale('ar');

    // "Hpl]" types "أحمد" and "l]vsm" types "مدرسة".
    expect(names('Hpl]'))->toBe(['أَحْمَد'])
        ->and(names('l]vsm'))->toBe(['مدرسة']);
});

it('still finds the word as it was typed', function () {
    product('iPhone 15');

    app()->setLocale('ar');

    expect(names('iphone'))->toBe(['iPhone 15']);
});

it('finds both readings of an ambiguous lam-alef', function () {
    product('Ghost');
    product('Book');

    // "لاخسف" is "bost" or "ghost"; "لاخخن" is "book" or "ghook".
    expect(names('لاخسف'))->toBe(['Ghost'])
        ->and(names('لاخخن'))->toBe(['Book']);
});

it('corrects each word of a mixed term on its own', function () {
    product('هاتف محمد iPhone');

    app()->setLocale('ar');

    expect(names('lpl] iphone'))->toBe(['هاتف محمد iPhone']);
});

it('corrects both directions in "both" mode, whatever the locale', function (string $locale) {
    $this->configureFindex(['keyboard.mode' => 'both']);

    product('محمد');
    product('Laravel');

    app()->setLocale($locale);

    expect(names('lpl]'))->toBe(['محمد'])
        ->and(names('مشقشرثم'))->toBe(['Laravel']);
})->with(['ar', 'en']);

it('follows the "ar" and "en" modes instead of the locale', function () {
    product('محمد');
    product('Laravel');

    $this->configureFindex(['keyboard.mode' => 'ar']);
    app()->setLocale('en');

    expect(names('lpl]'))->toBe(['محمد'])->and(names('مشقشرثم'))->toBe([]);

    $this->configureFindex(['keyboard.mode' => 'en']);
    app()->setLocale('ar');

    expect(names('lpl]'))->toBe([])->and(names('مشقشرثم'))->toBe(['Laravel']);
});

it('corrects nothing in "off" mode', function () {
    $this->configureFindex(['keyboard.mode' => 'off']);

    product('محمد');
    product('Laravel');

    app()->setLocale('ar');
    expect(names('lpl]'))->toBe([]);

    app()->setLocale('en');
    expect(names('مشقشرثم'))->toBe([]);
});

it('treats configured locale prefixes as arabic', function () {
    $this->configureFindex(['keyboard.arabic_locales' => ['ar', 'fa']]);

    product('محمد');

    app()->setLocale('fa_IR');

    expect(names('lpl]'))->toBe(['محمد']);
});

it('returns everything for an empty term', function (?string $term) {
    product('one');
    product('two');

    expect(Product::findex($term)->count())->toBe(2)
        ->and(Product::findex($term)->toSql())->toBe('select * from "products" where "products"."deleted_at" is null');
})->with([null, '', '   ', '!?']);

it('never treats the term as a like pattern', function () {
    product('abc');
    product('a c');
    product('100 percent');

    expect(names('a_c'))->toBe(['a c'])
        ->and(names('a%c'))->toBe(['a c'])
        ->and(names('%'))->toHaveCount(3)
        ->and(names('100%'))->toBe(['100 percent']);
});

it('generates minimal sql through the scope', function () {
    app()->setLocale('ar');

    $query = Product::findex('lpl] 15');

    expect($query->toSql())->toBe(
        'select * from "products" where (("products"."search_index" like ? or "products"."search_index" like ?)'
        .' and "products"."search_index" like ?) and "products"."deleted_at" is null'
    )->and($query->getBindings())->toBe(['%lpl%', '%محمد%', '%15%']);
});

it('composes with other constraints and scopes', function () {
    product('Red phone', ['stock' => 5]);
    product('Red tablet', ['stock' => 0]);
    product('Blue phone', ['stock' => 3])->delete();

    expect(Product::where('stock', '>', 0)->findex('red')->pluck('name')->all())->toBe(['Red phone'])
        ->and(Product::findex('phone')->where('stock', '>', 0)->pluck('name')->all())->toBe(['Red phone'])
        ->and(Product::withTrashed()->findex('phone')->count())->toBe(2)
        ->and(Product::query()->where('stock', 0)->orWhere->findex('blue')->count())->toBe(1);
});

it('accepts the driver as a named argument and falls back to like on sqlite', function () {
    product('Samsung Galaxy');
    product('Apple iPhone');

    $query = Product::findex('galaxy', driver: 'fulltext');

    expect($query->toSql())->toBe(
        'select * from "products" where ("products"."search_index" like ?) and "products"."deleted_at" is null'
    )->and($query->pluck('name')->all())->toBe(['Samsung Galaxy'])
        ->and(names('iphone', 'fulltext'))->toBe(['Apple iPhone'])
        ->and(names('galaxy', 'like'))->toBe(['Samsung Galaxy']);
});

it('falls back to like on sqlite when fulltext is the configured driver', function () {
    $this->configureFindex(['driver' => 'fulltext']);

    product('Samsung Galaxy');

    expect(names('galaxy sam'))->toBe(['Samsung Galaxy']);
});

it('rejects an unknown driver', function () {
    Product::findex('phone', 'elastic')->get();
})->throws(InvalidArgumentException::class, 'Unsupported Findex driver [elastic]');

it('searches through a relation', function () {
    $phones = Category::create(['name' => 'Phones']);
    $laptops = Category::create(['name' => 'Laptops']);

    product('Galaxy', ['category_id' => $phones->id]);
    product('Galaxy Book', ['category_id' => $laptops->id]);

    expect($phones->products()->findex('galaxy')->pluck('name')->all())->toBe(['Galaxy']);
});

it('qualifies the index column so joins are not ambiguous', function () {
    $phones = Category::create(['name' => 'Phones']);

    product('Galaxy', ['category_id' => $phones->id]);
    product('Pixel', ['category_id' => $phones->id]);

    $names = Product::query()
        ->join('categories', 'categories.id', '=', 'products.category_id')
        ->findex('galaxy')
        ->pluck('products.name')
        ->all();

    expect($names)->toBe(['Galaxy']);
});

it('paginates', function () {
    foreach (range(1, 7) as $i) {
        product('Phone '.$i);
    }
    product('Tablet');

    $page = Product::findex('phone')->paginate(5);

    expect($page->total())->toBe(7)->and($page->count())->toBe(5);
});

it('caps the number of searched words', function () {
    $this->configureFindex(['max_tokens' => 2]);

    product('alpha beta');

    // The third word is ignored, so it cannot make the search fail.
    expect(names('alpha beta gamma'))->toBe(['alpha beta'])
        ->and(DB::table('products')->whereFindex('search_index', 'alpha beta gamma')->getBindings())
        ->toBe(['%alpha%', '%beta%']);
});

it('searches symbols when they are preserved', function () {
    $this->configureFindex(['normalization.preserve' => ['#', '+']]);

    product('C# developer');
    product('C++ developer');
    product('C developer');

    expect(names('c#'))->toBe(['C# developer'])
        ->and(names('C++'))->toBe(['C++ developer'])
        ->and(names('developer'))->toHaveCount(3);
});

it('strips the definite article on both sides when enabled', function () {
    $this->configureFindex(['normalization.strip_definite_article' => true]);

    product('البيت الكبير');
    product('بيت صغير');

    expect(names('بيت'))->toHaveCount(2)
        ->and(names('الكبير'))->toBe(['البيت الكبير'])
        ->and(names('كبير'))->toBe(['البيت الكبير']);
});
