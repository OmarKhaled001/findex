<?php

declare(strict_types=1);

use OmarKhaled\Findex\Keyboard;

it('converts latin keystrokes to arabic', function (string $typed, string $expected) {
    expect(Keyboard::toArabic($typed))->toBe($expected);
})->with([
    'muhammad' => ['lpl]', 'محمد'],
    'the house' => ['hgfdj', 'البيت'],
    'ahmed' => ['hpl]', 'احمد'],
    'hello in arabic' => ['lvpfh', 'مرحبا'],
    'school with ta marbuta' => ['l]vsm', 'مدرسة'],
    'b types lam-alef' => ['sbl', 'سلام'],
    'punctuation keys' => ["`;'[],./", 'ذكطجدوزظ'],
    'several words' => ['lpl] ugd', 'محمد علي'],
    'digits are untouched' => ['hdt,k 15', 'ايفون 15'],
    'arabic is untouched' => ['محمد lpl]', 'محمد محمد'],
    'empty' => ['', ''],
]);

it('maps every unshifted key of the layout', function () {
    $keys = '`qwertyuiop[]asdfghjkl;\'zxcvbnm,./';
    $arabic = 'ذضصثقفغعهخحجدشسيبلاتنمكطئءؤرلاىةوزظ';

    expect(Keyboard::toArabic($keys))->toBe($arabic);
});

it('maps shift letters to their own arabic letter', function (string $typed, string $expected) {
    expect(Keyboard::toArabic($typed))->toBe($expected);
})->with([
    'alef with hamza above' => ['H', 'أ'],
    'alef with hamza below' => ['Y', 'إ'],
    'alef with madda' => ['N', 'آ'],
    'lam-alef with hamza above' => ['G', 'لأ'],
    'lam-alef with hamza below' => ['T', 'لإ'],
    'lam-alef with madda' => ['B', 'لآ'],
    'ahmed with hamza' => ['Hpl]', 'أحمد'],
    'islam with hamza' => ['Ysbl', 'إسلام'],
]);

it('maps other uppercase letters like their lowercase key', function (string $typed, string $expected) {
    expect(Keyboard::toArabic($typed))->toBe($expected);
})->with([
    'caps lock' => ['LPL]', 'محمد'],
    'auto-capitalized first letter' => ['Lpl]', 'محمد'],
    'every remaining letter' => ['QWERUIOPASDFJKLZXCVM', 'ضصثقعهخحشسيبتنمئءؤرة'],
]);

it('converts arabic keystrokes to english', function (string $typed, array $expected) {
    expect(Keyboard::toEnglish($typed))->toBe($expected);
})->with([
    'hello' => ['اثممخ', ['hello']],
    'laravel' => ['مشقشرثم', ['laravel']],
    'iphone' => ['هحاخىث', ['iphone']],
    'several words' => ['اثممخ صخقمي', ['hello world']],
    'punctuation keys' => ['ذكطجدوزظ', ["`;'[],./"]],
    'digits are untouched' => ['سنع 123', ['sku 123']],
    'latin is untouched' => ['hello اثممخ', ['hello hello']],
    'empty' => ['', ['']],
]);

it('maps the arabic shift layer back to its keys', function (string $typed, array $expected) {
    expect(Keyboard::toEnglish($typed))->toBe($expected);
})->with([
    'shift letters' => ['أإآ', ['hyn']],
    'shift lam-alef letters' => ['لأ لإ لآ', ['g t b']],
    'capitalized hello' => ['أثممخ', ['hello']],
    'capitalized ghost' => ['لأاخسف', ['ghost']],
    'capitalized table' => ['لإشلامث', ['table', 'taghle']],
    'tatweel and punctuation' => ['ـ،؛؟', ['jkp?']],
    'marks' => ["\u{064E}\u{064B}\u{064F}\u{064C}\u{0650}\u{064D}\u{0652}\u{0651}", ['qwerasx~']],
    'symbols on letter keys' => [']ثمم ÷حاخىث', ['dell iphone']],
    'all symbols on letter keys' => [']}[{/~‘÷×’', ['dcfvlzuiom']],
]);

it('returns both readings of an ambiguous lam-alef', function (string $typed, array $expected) {
    expect(Keyboard::toEnglish($typed))->toBe($expected);
})->with([
    'ghost' => ['لاخسف', ['bost', 'ghost']],
    'book' => ['لاخخن', ['book', 'ghook']],
    'light' => ['مهلاف', ['libt', 'light']],
    'lam before lam-alef' => ['للا', ['gb', 'ggh']],
    'bought mixes both readings' => ['لاخعلاف', ['boubt', 'ghoubt', 'bought', 'ghought']],
    'three occurrences' => ['لالالا', ['bbb', 'ghbb', 'bghb', 'ghghb', 'bbgh', 'ghbgh', 'bghgh', 'ghghgh']],
    'more than three occurrences' => ['لالالالا', ['bbbb', 'ghghghgh']],
]);

it('round-trips lowercase text without the letter b', function (string $text) {
    expect(Keyboard::toEnglish(Keyboard::toArabic($text)))->toBe([$text]);
})->with(['hello world', 'laravel', "it's 4 o'clock", 'query; select, from.']);

it('round-trips arabic text through the english layout', function (string $text) {
    expect(Keyboard::toArabic(Keyboard::toEnglish($text)[0]))->toBe($text);
})->with(['محمد', 'البيت الكبير', 'مدرسة', 'سلام', 'ذهب ظريف']);
