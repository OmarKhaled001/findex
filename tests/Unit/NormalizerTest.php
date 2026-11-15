<?php

declare(strict_types=1);

use OmarKhaled\Findex\Normalizer;

it('normalizes text with the default rules', function (string $input, string $expected) {
    expect((new Normalizer)->normalize($input))->toBe($expected);
})->with([
    // alef
    'alef with hamza above' => ['أحمد', 'احمد'],
    'alef with hamza below' => ['إسلام', 'اسلام'],
    'alef with madda' => ['آدم', 'ادم'],
    'alef wasla' => ['ٱسم', 'اسم'],
    'alef with wavy hamza above' => ['ٲب', 'اب'],
    'alef with wavy hamza below' => ['ٳب', 'اب'],
    // ta marbuta
    'ta marbuta' => ['مدرسة', 'مدرسه'],
    'ta marbuta goal' => ['مدرسۃ', 'مدرسه'],
    'heh with yeh above' => ['خانۀ', 'خانه'],
    // alef maqsura
    'alef maqsura' => ['مستشفى', 'مستشفي'],
    // hamza
    'hamza on waw' => ['مؤمن', 'مومن'],
    'hamza on yeh' => ['سائل', 'سايل'],
    'standalone hamza is kept' => ['سماء', 'سماء'],
    // diacritics
    'tashkeel' => ['مُحَمَّد', 'محمد'],
    'tanween' => ['شكرًا', 'شكرا'],
    'sukun' => ['مسْجد', 'مسجد'],
    'tatweel' => ['محـــمد', 'محمد'],
    'superscript alef' => ['هٰذا', 'هذا'],
    'quranic annotation marks' => ["قل\u{06D6} هو\u{06E4} الله\u{06ED}", 'قل هو الله'],
    'honorific signs' => ["محمد\u{0610}", 'محمد'],
    'decomposed hamza above' => ["ا\u{0654}حمد", 'احمد'],
    // digits
    'arabic-indic digits' => ['١٢٣٤٥٦٧٨٩٠', '1234567890'],
    'persian digits' => ['۰۱۲۳۴۵۶۷۸۹', '0123456789'],
    'arabic decimal separator' => ['١٢٫٥', '12 5'],
    'arabic thousands separator' => ['١٬٢٣٤', '1 234'],
    // latin
    'uppercase' => ['HELLO World', 'hello world'],
    'acute accent' => ['Café', 'cafe'],
    'sharp s' => ['Straße', 'strasse'],
    'capital sharp s' => ['STRAẞE', 'strasse'],
    'ae ligature' => ['Æther', 'aether'],
    'oe ligature' => ['Œuvre', 'oeuvre'],
    'o with stroke' => ['Søren', 'soren'],
    'polish' => ['Łódź', 'lodz'],
    'turkish dotted capital i' => ['İstanbul', 'istanbul'],
    'turkish dotless i' => ['Kadıköy', 'kadikoy'],
    'vietnamese' => ['Việt Nam', 'viet nam'],
    'decomposed accent' => ["Cafe\u{0301}", 'cafe'],
    'spanish and german' => ['Niño über Ångström', 'nino uber angstrom'],
    // punctuation and whitespace
    'punctuation becomes a space' => ['SKU-123', 'sku 123'],
    'whitespace is collapsed' => ["  Hello,\t\n  World!!  ", 'hello world'],
    'arabic punctuation' => ['مرحبا، كيف الحال؟', 'مرحبا كيف الحال'],
    'only punctuation' => ['!!! --- ???', ''],
    'emoji and symbols' => ['price: 50€ 🚀 ok', 'price 50 ok'],
    'non breaking space' => ["محمد\u{00A0}علي", 'محمد علي'],
    // invisible characters
    'zero width non-joiner' => ["مح\u{200C}مد", 'محمد'],
    'zero width space' => ["lara\u{200B}vel", 'laravel'],
    'zero width joiner' => ["مح\u{200D}مد", 'محمد'],
    'soft hyphen' => ["co\u{00AD}operate", 'cooperate'],
    'byte order mark' => ["\u{FEFF}hello", 'hello'],
    'arabic letter mark' => ["محمد\u{061C}", 'محمد'],
    'directional marks' => ["\u{200E}محمد\u{200F}", 'محمد'],
    'directional embedding' => ["\u{202B}محمد\u{202C} \u{202A}x\u{202D}y\u{202E}", 'محمد xy'],
    'directional isolates' => ["\u{2067}محمد\u{2069} \u{2066}a\u{2068}b", 'محمد ab'],
    // persian letters
    'persian kaf' => ['کتاب', 'كتاب'],
    'swash kaf' => ['ڪتاب', 'كتاب'],
    'farsi yeh' => ['علی', 'علي'],
    'pashto yeh' => ['علې', 'علي'],
    // ligatures
    'lam-alef isolated' => ["س\u{FEFB}م", 'سلام'],
    'lam-alef final' => ["س\u{FEFC}م", 'سلام'],
    'lam-alef with madda' => ["\u{FEF5}ن \u{FEF6}", 'لان لا'],
    'lam-alef with hamza above' => ["\u{FEF7}حد \u{FEF8}", 'لاحد لا'],
    'lam-alef with hamza below' => ["\u{FEF9}سلام \u{FEFA}", 'لاسلام لا'],
    // mixed
    'mixed scripts' => ['iPhone ١٥ برو ماكس', 'iphone 15 برو ماكس'],
    'full sentence' => ['أَهْلًا بِكُمْ فِي الْمَدْرَسَةِ!', 'اهلا بكم في المدرسه'],
]);

it('returns an empty string for empty input', function (?string $input) {
    expect((new Normalizer)->normalize($input))->toBe('');
})->with([null, '', ' ', "\n\t", '%', "\u{200B}"]);

it('can switch every rule off', function (string $option, string $input, string $expected) {
    expect((new Normalizer([$option => false]))->normalize($input))->toBe($expected);
})->with([
    'alef' => ['alef', 'أحمد إسلام آدم', 'أحمد إسلام آدم'],
    'alef keeps ligature hamza' => ['alef', "\u{FEF7}حد \u{FEF9}سلام \u{FEF5}ن س\u{FEFB}م", 'لأحد لإسلام لآن سلام'],
    'ta_marbuta' => ['ta_marbuta', 'مدرسة', 'مدرسة'],
    'alef_maqsura' => ['alef_maqsura', 'مستشفى', 'مستشفى'],
    'hamza' => ['hamza', 'مؤمن سائل', 'مؤمن سائل'],
    'diacritics' => ['diacritics', 'مُحَمَّد', 'مُحَمَّد'],
    'diacritics keeps tatweel' => ['diacritics', 'محـمد', 'محـمد'],
    'digits' => ['digits', '١٢٣ ۴۵۶', '١٢٣ ۴۵۶'],
    'latin_accents' => ['latin_accents', 'Café Straße', 'café straße'],
    'latin_accents keeps combining marks' => ['latin_accents', "Cafe\u{0301}", "cafe\u{0301}"],
]);

it('only disables the rule that was switched off', function () {
    $normalizer = new Normalizer(['alef' => false]);

    expect($normalizer->normalize('مَدْرَسَة ١٢٣ Café'))->toBe('مدرسه 123 cafe');
});

it('always folds persian letters and invisible characters, whatever the toggles', function () {
    $normalizer = new Normalizer(array_fill_keys(
        ['alef', 'ta_marbuta', 'alef_maqsura', 'hamza', 'diacritics', 'digits', 'latin_accents'],
        false,
    ));

    expect($normalizer->normalize("کتاب علی \u{200B}مح\u{200C}مد"))->toBe('كتاب علي محمد');
});

it('does not strip the definite article by default', function () {
    expect((new Normalizer)->normalize('البيت الكبير'))->toBe('البيت الكبير');
});

it('strips the definite article when enabled', function (string $input, string $expected) {
    expect((new Normalizer(['strip_definite_article' => true]))->normalize($input))->toBe($expected);
})->with([
    'two words' => ['البيت الكبير', 'بيت كبير'],
    'three letters remain' => ['الولد', 'ولد'],
    'fewer than three letters remain' => ['الله', 'الله'],
    'short particle' => ['إلى', 'الي'],
    'relative pronoun' => ['التي', 'التي'],
    'article in the middle of a word' => ['بالبيت', 'بالبيت'],
    'after normalization' => ['الْمَدْرَسَة', 'مدرسه'],
    'mixed with latin' => ['iPhone الجديد', 'iphone جديد'],
    'latin text is untouched' => ['alpha', 'alpha'],
]);

it('drops symbols like # and + unless they are preserved', function () {
    expect((new Normalizer)->normalize('C# and C++'))->toBe('c and c');
});

it('preserves configured characters inside words', function () {
    $normalizer = new Normalizer(['preserve' => ['#', '+']]);

    expect($normalizer->normalize('C# and C++ (.NET)'))->toBe('c# and c++ net')
        ->and($normalizer->normalize('سي++ ممتاز'))->toBe('سي++ ممتاز')
        ->and($normalizer->preserved())->toBe(['#', '+']);
});

it('accepts the preserved characters as a string', function () {
    expect((new Normalizer(['preserve' => '#+']))->normalize('C# C++'))->toBe('c# c++');
});

it('preserves characters that are special inside a regular expression', function () {
    $normalizer = new Normalizer(['preserve' => ['-', ']', '^', '/', '.']]);

    expect($normalizer->normalize('SKU-123 a]b ^c 1/2 v1.5 x*y'))->toBe('sku-123 a]b ^c 1/2 v1.5 x y');
});

it('never preserves like wildcards, the escape character or whitespace', function () {
    $normalizer = new Normalizer(['preserve' => ['%', '_', '\\', ' ', "\n", '#', '%_\\']]);

    expect($normalizer->preserved())->toBe(['#'])
        ->and($normalizer->normalize('50%_off\\now #1'))->toBe('50 off now #1');
});

it('can never output a like wildcard', function (array $options) {
    $normalizer = new Normalizer($options);

    $inputs = [
        '100% _private_ back\\slash',
        '%%%___\\\\\\',
        'محمد%علي_حسن\\',
        "a\u{FF05}b\u{FF3F}c\u{FF3C}d\u{FE6A}e\u{066A}f",
        '%'.implode('', array_map(chr(...), range(32, 126))).'_',
        'Ünïcödé_%\\',
    ];

    foreach ($inputs as $input) {
        expect($normalizer->normalize($input))
            ->not->toContain('%')
            ->not->toContain('_')
            ->not->toContain('\\');
    }
})->with([
    'defaults' => [[]],
    'everything off' => [array_fill_keys(
        ['alef', 'ta_marbuta', 'alef_maqsura', 'hamza', 'diacritics', 'digits', 'latin_accents'],
        false,
    )],
    'hostile preserve list' => [['preserve' => ['%', '_', '\\', '#', '+', '%_\\']]],
    'article stripping' => [['strip_definite_article' => true]],
]);

it('only outputs letters, numbers, marks and single spaces', function () {
    $everything = implode('', array_map(
        static fn (int $codePoint): string => mb_chr($codePoint, 'UTF-8'),
        [...range(0x20, 0x24F), ...range(0x600, 0x6FF), ...range(0x2000, 0x206F), ...range(0xFE70, 0xFEFF)],
    ));

    $normalized = (new Normalizer)->normalize($everything);

    expect($normalized)->toMatch('/^[\p{L}\p{N}\p{M}]+(?: [\p{L}\p{N}\p{M}]+)*$/u');
});

it('gives the same result on the ascii fast path and the unicode path', function (string $ascii) {
    $normalizer = new Normalizer(['preserve' => ['#', '+']]);

    expect($normalizer->normalize($ascii.' é'))->toBe(trim($normalizer->normalize($ascii).' e'));
})->with([
    'Hello, World!',
    '  SKU-123/AB_45  ',
    'C# and C++ (.NET) 100%',
    "tabs\tand\nnewlines",
    '',
    '!!!',
]);

it('is idempotent', function (string $input) {
    $normalizer = new Normalizer;

    $once = $normalizer->normalize($input);

    expect($normalizer->normalize($once))->toBe($once);
})->with([
    'أَهْلًا بِكُمْ فِي الْمَدْرَسَةِ!',
    'Café Straße Œuvre İstanbul',
    'SKU-123 ١٢٣ ۴۵۶',
    "س\u{FEFB}م کتاب علی",
]);

it('survives invalid utf-8', function () {
    $normalized = (new Normalizer)->normalize("caf\xC3 محمد \xFF");

    expect($normalized)->toContain('محمد')
        ->and(mb_check_encoding($normalized, 'UTF-8'))->toBeTrue();
});
