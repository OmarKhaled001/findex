<?php

declare(strict_types=1);

use OmarKhaled\Findex\Findex;

function findex(array $config = [], string $locale = 'en'): Findex
{
    return new Findex($config, static fn (): string => $locale);
}

it('returns no groups for empty input', function (?string $term) {
    expect(findex()->parse($term))->toBe([]);
})->with([null, '', '   ', "\t\n", '!!!', '% _ \\', "\u{200B}"]);

it('splits a term into one group per word', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse('  Red   iPhone 15  '))
        ->toBe([['red'], ['iphone'], ['15']]);
});

it('normalizes every word', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse('أَحْمَد Café مدرسة ١٢٣'))
        ->toBe([['احمد'], ['cafe'], ['مدرسه'], ['123']]);
});

it('splits on any unicode whitespace', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse("محمد\u{00A0}علي\u{3000}حسن\tسعيد"))
        ->toBe([['محمد'], ['علي'], ['حسن'], ['سعيد']]);
});

it('keeps punctuation inside a word as one phrase variant', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse('SKU-123'))->toBe([['sku 123']]);
});

it('resolves the keyboard direction from the mode and the locale', function (string $mode, string $locale, array $expected) {
    expect(findex(['keyboard' => ['mode' => $mode]], $locale)->parse('lpl] اثممخ'))->toBe($expected);
})->with([
    'auto on an arabic locale' => ['auto', 'ar', [['lpl', 'محمد'], ['اثممخ']]],
    'auto on a regional arabic locale' => ['auto', 'ar_EG', [['lpl', 'محمد'], ['اثممخ']]],
    'auto on a hyphenated arabic locale' => ['auto', 'ar-SA', [['lpl', 'محمد'], ['اثممخ']]],
    'auto on an uppercase arabic locale' => ['auto', 'AR', [['lpl', 'محمد'], ['اثممخ']]],
    'auto on an english locale' => ['auto', 'en', [['lpl'], ['اثممخ', 'hello']]],
    'auto on any other locale' => ['auto', 'fr', [['lpl'], ['اثممخ', 'hello']]],
    'both on an arabic locale' => ['both', 'ar', [['lpl', 'محمد'], ['اثممخ', 'hello']]],
    'both on an english locale' => ['both', 'en', [['lpl', 'محمد'], ['اثممخ', 'hello']]],
    'ar on an english locale' => ['ar', 'en', [['lpl', 'محمد'], ['اثممخ']]],
    'ar on an arabic locale' => ['ar', 'ar', [['lpl', 'محمد'], ['اثممخ']]],
    'en on an arabic locale' => ['en', 'ar', [['lpl'], ['اثممخ', 'hello']]],
    'en on an english locale' => ['en', 'en', [['lpl'], ['اثممخ', 'hello']]],
    'off on an arabic locale' => ['off', 'ar', [['lpl'], ['اثممخ']]],
    'off on an english locale' => ['off', 'en', [['lpl'], ['اثممخ']]],
]);

it('treats configured locale prefixes as arabic', function () {
    $config = ['keyboard' => ['arabic_locales' => ['ar', 'FA']]];

    expect(findex($config, 'fa_IR')->parse('lpl]'))->toBe([['lpl', 'محمد']])
        ->and(findex($config, 'en')->parse('lpl]'))->toBe([['lpl']])
        ->and(findex(['keyboard' => ['arabic_locales' => []]], 'ar')->parse('lpl]'))->toBe([['lpl']]);
});

it('reads the locale at call time, never at construction', function () {
    $locale = 'en';
    $findex = new Findex([], static function () use (&$locale): string {
        return $locale;
    });

    expect($findex->parse('lpl]'))->toBe([['lpl']]);

    $locale = 'ar';

    expect($findex->parse('lpl]'))->toBe([['lpl', 'محمد']]);

    $locale = 'en';

    expect($findex->parse('lpl]'))->toBe([['lpl']]);
});

it('computes layout alternatives on the raw word, before normalization', function () {
    // "]" is punctuation to the normalizer but the letter "د" on the keyboard.
    expect(findex(locale: 'ar')->parse('lpl]'))->toBe([['lpl', 'محمد']])
        ->and(findex(locale: 'ar')->parse('hgfdj'))->toBe([['hgfdj', 'البيت']])
        ->and(findex(locale: 'ar')->parse("l'hv"))->toBe([['l hv', 'مطار']]);
});

it('normalizes layout alternatives', function () {
    // "l]vsm" types "مدرسة"; "Hpl]" types "أحمد".
    expect(findex(locale: 'ar')->parse('l]vsm'))->toBe([['l vsm', 'مدرسه']])
        ->and(findex(locale: 'en')->parse('ؤشبث'))->toBe([['وشبث', 'cafe']]);
});

it('only swaps words that contain letters of the source script', function () {
    expect(findex(['keyboard' => ['mode' => 'both']])->parse('123 ; محمد hello'))
        ->toBe([['123'], ['محمد', 'lpl'], ['hello', 'اثممخ']]);
});

it('always keeps the original word as the first variant', function () {
    expect(findex(locale: 'ar')->parse('iphone')[0][0])->toBe('iphone')
        ->and(findex(locale: 'en')->parse('محمد')[0][0])->toBe('محمد');
});

it('tries shift letters and their plain keys for uppercase input', function () {
    // "H" types "أ", and with caps lock the same key was meant as "ا".
    expect(findex(locale: 'ar')->parse('Yhgd'))->toBe([['yhgd', 'االي', 'غالي']])
        ->and(findex(locale: 'ar')->parse('LPL]'))->toBe([['lpl', 'محمد']])
        ->and(findex(locale: 'ar')->parse('Hpl]'))->toBe([['hpl', 'احمد']]);
});

it('returns every reading of an ambiguous lam-alef', function () {
    expect(findex(locale: 'en')->parse('لاخسف'))->toBe([['لاخسف', 'bost', 'ghost']]);
});

it('drops duplicate variants', function () {
    // The arabic layout alternative of "123a" would be "123ش"; plain digits have none.
    expect(findex(['keyboard' => ['mode' => 'both']])->parse('٢٠٢٤'))->toBe([['2024']]);
});

it('drops duplicate groups', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse('Red red RED blue'))->toBe([['red'], ['blue']])
        ->and(findex(locale: 'ar')->parse('lpl] Lpl] محمد'))->toBe([['lpl', 'محمد'], ['محمد']]);
});

it('drops words that normalize to nothing', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse('red -- !! blue'))->toBe([['red'], ['blue']]);
});

it('caps the number of groups at max_tokens', function () {
    $term = 'one two three four five six seven eight nine ten eleven twelve';

    expect(findex(['keyboard' => ['mode' => 'off']])->parse($term))->toHaveCount(10)
        ->and(findex(['keyboard' => ['mode' => 'off'], 'max_tokens' => 3])->parse($term))
        ->toBe([['one'], ['two'], ['three']])
        ->and(findex(['keyboard' => ['mode' => 'off'], 'max_tokens' => 0])->parse($term))->toBe([['one']]);
});

it('counts groups, not raw words, towards max_tokens', function () {
    expect(findex(['keyboard' => ['mode' => 'off'], 'max_tokens' => 2])->parse('a a ! a b c'))
        ->toBe([['a'], ['b']]);
});

it('returns the same groups from the cache', function () {
    $findex = findex(locale: 'ar');

    expect($findex->parse('lpl] iphone'))->toBe($findex->parse('lpl] iphone'))
        ->toBe([['lpl', 'محمد'], ['iphone', 'هحاخيث']]);
});

it('keeps the parse cache bounded', function () {
    $findex = findex();

    foreach (range(1, 500) as $i) {
        $findex->parse('term'.$i);
    }
    $findex->parse(str_repeat('x', 5000));

    $cache = (new ReflectionProperty($findex, 'cache'))->getValue($findex);

    expect($cache)->toHaveCount(128)
        ->and(array_key_last($cache))->toBe('-eterm500')
        ->and(array_key_first($cache))->toBe('-eterm373');
});

it('survives invalid utf-8', function () {
    expect(findex(['keyboard' => ['mode' => 'off']])->parse("محمد \xFF\xFE علي"))->toContain(['محمد'], ['علي']);
});

it('exposes the helpers', function () {
    $findex = findex();

    expect($findex->normalize('أَحْمَد'))->toBe('احمد')
        ->and($findex->toArabicLayout('lpl]'))->toBe('محمد')
        ->and($findex->toEnglishLayout('اثممخ'))->toBe(['hello'])
        ->and($findex->column())->toBe('search_index')
        ->and(findex(['column' => 'needle'])->column())->toBe('needle');
});

it('passes the normalization options to the normalizer', function () {
    $findex = findex(['normalization' => ['alef' => false, 'preserve' => ['#']]]);

    expect($findex->normalize('أحمد C#'))->toBe('أحمد c#');
});

it('rejects an unknown keyboard mode', function () {
    findex(['keyboard' => ['mode' => 'qwerty']]);
})->throws(InvalidArgumentException::class, 'Unsupported Findex keyboard mode [qwerty]');

it('rejects an unknown default driver', function () {
    findex(['driver' => 'elastic']);
})->throws(InvalidArgumentException::class, 'Unsupported Findex driver [elastic]');
