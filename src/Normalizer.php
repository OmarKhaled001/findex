<?php

declare(strict_types=1);

namespace OmarKhaled\Findex;

/**
 * Folds Arabic and Latin text into one canonical, lowercase, wildcard-free form.
 *
 * The stored index and the search term both go through this class, so they
 * always meet in the same shape. The whole pipeline is four native calls:
 *
 *   mb_strtolower -> strtr (map compiled once) -> preg_replace -> trim
 *
 * The output only ever contains letters, numbers, combining marks, single
 * spaces and the configured "preserve" characters. It can never contain a
 * LIKE wildcard (%, _) or the escape character (\), which is why the package
 * never has to escape a search term.
 */
final readonly class Normalizer
{
    /**
     * Every supported option with its default value.
     *
     * @var array{
     *     alef: bool, ta_marbuta: bool, alef_maqsura: bool, hamza: bool, diacritics: bool,
     *     digits: bool, latin_accents: bool, strip_definite_article: bool, preserve: list<string>
     * }
     */
    public const array DEFAULTS = [
        'alef' => true,
        'ta_marbuta' => true,
        'alef_maqsura' => true,
        'hamza' => true,
        'diacritics' => true,
        'digits' => true,
        'latin_accents' => true,
        'strip_definite_article' => false,
        'preserve' => [],
    ];

    /**
     * Characters that may never be preserved: the LIKE wildcards and the LIKE escape character.
     *
     * @var list<string>
     */
    public const array FORBIDDEN = ['%', '_', '\\'];

    /** @var array<string, string> */
    private const array ALEF = ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ٲ' => 'ا', 'ٳ' => 'ا'];

    /** @var array<string, string> */
    private const array TA_MARBUTA = ['ة' => 'ه', 'ۃ' => 'ه', 'ۀ' => 'ه'];

    /** @var array<string, string> */
    private const array ALEF_MAQSURA = ['ى' => 'ي'];

    /** @var array<string, string> Standalone hamza (ء) is a letter of its own and is kept. */
    private const array HAMZA = ['ؤ' => 'و', 'ئ' => 'ي'];

    /** @var array<string, string> Persian / Urdu / Pashto look-alikes of Arabic kaf and yeh. */
    private const array PERSIAN = ['ک' => 'ك', 'ڪ' => 'ك', 'ی' => 'ي', 'ې' => 'ي'];

    /** @var array<string, string> Lam-alef presentation ligatures (U+FEF5 - U+FEFC). */
    private const array LAM_ALEF = [
        "\u{FEF5}" => 'لآ', "\u{FEF6}" => 'لآ',
        "\u{FEF7}" => 'لأ', "\u{FEF8}" => 'لأ',
        "\u{FEF9}" => 'لإ', "\u{FEFA}" => 'لإ',
        "\u{FEFB}" => 'لا', "\u{FEFC}" => 'لا',
    ];

    /** @var array<string, string> Arabic-Indic and Extended (Persian) digits, plus their separators. */
    private const array DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => ',',
    ];

    /** @var list<array{int, int}> Tatweel and the Arabic marks (tashkeel, Quranic annotation signs). */
    private const array DIACRITIC_RANGES = [
        [0x0640, 0x0640], [0x0610, 0x061A], [0x064B, 0x065F], [0x0670, 0x0670],
        [0x06D6, 0x06DC], [0x06DF, 0x06E4], [0x06E7, 0x06E8], [0x06EA, 0x06ED],
    ];

    /** @var list<array{int, int}> Combining Diacritical Marks, left over from decomposed (NFD) Latin text. */
    private const array COMBINING_RANGES = [[0x0300, 0x036F]];

    /** @var list<array{int, int}> Soft hyphen, zero-width characters, bidi controls and the BOM. */
    private const array INVISIBLE_RANGES = [
        [0x00AD, 0x00AD], [0x061C, 0x061C], [0x200B, 0x200F],
        [0x202A, 0x202E], [0x2066, 0x2069], [0xFEFF, 0xFEFF],
    ];

    /**
     * Matches the definite article at the start of a word that keeps at least three Arabic letters.
     */
    private const string ARTICLE = '/(?<![^ ])ال(?=(?:[\x{0621}-\x{064A}]\p{M}*+){3})/u';

    /**
     * Lowercase precomposed Latin letters and their ASCII base letters.
     *
     * Only lowercase forms are listed because the text is lowercased first.
     *
     * @var array<string, string>
     */
    private const array LATIN = [
        'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
        'ï' => 'i', 'ð' => 'd', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'th', 'ÿ' => 'y',
        'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c', 'ď' => 'd',
        'đ' => 'd', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e', 'ĝ' => 'g', 'ğ' => 'g',
        'ġ' => 'g', 'ģ' => 'g', 'ĥ' => 'h', 'ħ' => 'h', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i',
        'ı' => 'i', 'ĳ' => 'ij', 'ĵ' => 'j', 'ķ' => 'k', 'ĸ' => 'k', 'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l',
        'ŀ' => 'l', 'ł' => 'l', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n', 'ŋ' => 'n', 'ō' => 'o',
        'ŏ' => 'o', 'ő' => 'o', 'œ' => 'oe', 'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r', 'ś' => 's', 'ŝ' => 's',
        'ş' => 's', 'š' => 's', 'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u',
        'ů' => 'u', 'ű' => 'u', 'ų' => 'u', 'ŵ' => 'w', 'ŷ' => 'y', 'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'ſ' => 's', 'ƀ' => 'b', 'ƒ' => 'f', 'ƚ' => 'l', 'ơ' => 'o', 'ư' => 'u', 'ƶ' => 'z', 'ǆ' => 'dz',
        'ǉ' => 'lj', 'ǌ' => 'nj', 'ǎ' => 'a', 'ǐ' => 'i', 'ǒ' => 'o', 'ǔ' => 'u', 'ǖ' => 'u', 'ǘ' => 'u',
        'ǚ' => 'u', 'ǜ' => 'u', 'ǟ' => 'a', 'ǡ' => 'a', 'ǣ' => 'ae', 'ǥ' => 'g', 'ǧ' => 'g', 'ǩ' => 'k',
        'ǫ' => 'o', 'ǭ' => 'o', 'ǰ' => 'j', 'ǳ' => 'dz', 'ǵ' => 'g', 'ǹ' => 'n', 'ǻ' => 'a', 'ǽ' => 'ae',
        'ǿ' => 'o', 'ȁ' => 'a', 'ȃ' => 'a', 'ȅ' => 'e', 'ȇ' => 'e', 'ȉ' => 'i', 'ȋ' => 'i', 'ȍ' => 'o',
        'ȏ' => 'o', 'ȑ' => 'r', 'ȓ' => 'r', 'ȕ' => 'u', 'ȗ' => 'u', 'ș' => 's', 'ț' => 't', 'ȟ' => 'h',
        'ȧ' => 'a', 'ȩ' => 'e', 'ȫ' => 'o', 'ȭ' => 'o', 'ȯ' => 'o', 'ȱ' => 'o', 'ȳ' => 'y', 'ȷ' => 'j',
        'ȼ' => 'c', 'ɇ' => 'e', 'ɉ' => 'j', 'ɍ' => 'r', 'ɏ' => 'y', 'ɨ' => 'i', 'ḁ' => 'a', 'ḃ' => 'b',
        'ḅ' => 'b', 'ḇ' => 'b', 'ḉ' => 'c', 'ḋ' => 'd', 'ḍ' => 'd', 'ḏ' => 'd', 'ḑ' => 'd', 'ḓ' => 'd',
        'ḕ' => 'e', 'ḗ' => 'e', 'ḙ' => 'e', 'ḛ' => 'e', 'ḝ' => 'e', 'ḟ' => 'f', 'ḡ' => 'g', 'ḣ' => 'h',
        'ḥ' => 'h', 'ḧ' => 'h', 'ḩ' => 'h', 'ḫ' => 'h', 'ḭ' => 'i', 'ḯ' => 'i', 'ḱ' => 'k', 'ḳ' => 'k',
        'ḵ' => 'k', 'ḷ' => 'l', 'ḹ' => 'l', 'ḻ' => 'l', 'ḽ' => 'l', 'ḿ' => 'm', 'ṁ' => 'm', 'ṃ' => 'm',
        'ṅ' => 'n', 'ṇ' => 'n', 'ṉ' => 'n', 'ṋ' => 'n', 'ṍ' => 'o', 'ṏ' => 'o', 'ṑ' => 'o', 'ṓ' => 'o',
        'ṕ' => 'p', 'ṗ' => 'p', 'ṙ' => 'r', 'ṛ' => 'r', 'ṝ' => 'r', 'ṟ' => 'r', 'ṡ' => 's', 'ṣ' => 's',
        'ṥ' => 's', 'ṧ' => 's', 'ṩ' => 's', 'ṫ' => 't', 'ṭ' => 't', 'ṯ' => 't', 'ṱ' => 't', 'ṳ' => 'u',
        'ṵ' => 'u', 'ṷ' => 'u', 'ṹ' => 'u', 'ṻ' => 'u', 'ṽ' => 'v', 'ṿ' => 'v', 'ẁ' => 'w', 'ẃ' => 'w',
        'ẅ' => 'w', 'ẇ' => 'w', 'ẉ' => 'w', 'ẋ' => 'x', 'ẍ' => 'x', 'ẏ' => 'y', 'ẑ' => 'z', 'ẓ' => 'z',
        'ẕ' => 'z', 'ẖ' => 'h', 'ẗ' => 't', 'ẘ' => 'w', 'ẙ' => 'y', 'ạ' => 'a', 'ả' => 'a', 'ấ' => 'a',
        'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
        'ặ' => 'a', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e',
        'ệ' => 'e', 'ỉ' => 'i', 'ị' => 'i', 'ọ' => 'o', 'ỏ' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o',
        'ỗ' => 'o', 'ộ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o', 'ụ' => 'u',
        'ủ' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u', 'ỳ' => 'y', 'ỵ' => 'y',
        'ỷ' => 'y', 'ỹ' => 'y', 'ⱥ' => 'a', 'ⱦ' => 't',
    ];

    /**
     * The single translation table handed to strtr().
     *
     * @var array<string, string>
     */
    private array $map;

    /**
     * Matches every run of characters that is not part of a word.
     */
    private string $pattern;

    /**
     * The same pattern for text that is known to be pure ASCII.
     */
    private string $asciiPattern;

    private bool $stripDefiniteArticle;

    /**
     * The characters that are kept inside words, after filtering.
     *
     * @var list<string>
     */
    private array $preserved;

    /**
     * @param  array<string, mixed>  $options  Any subset of {@see self::DEFAULTS}.
     */
    public function __construct(array $options = [])
    {
        $options += self::DEFAULTS;

        $alef = (bool) $options['alef'];

        $map = self::PERSIAN
            + self::removals(self::INVISIBLE_RANGES)
            + ($alef ? array_fill_keys(array_keys(self::LAM_ALEF), 'لا') : self::LAM_ALEF);

        if ($alef) {
            $map += self::ALEF;
        }

        if ($options['ta_marbuta']) {
            $map += self::TA_MARBUTA;
        }

        if ($options['alef_maqsura']) {
            $map += self::ALEF_MAQSURA;
        }

        if ($options['hamza']) {
            $map += self::HAMZA;
        }

        if ($options['diacritics']) {
            $map += self::removals(self::DIACRITIC_RANGES);
        }

        if ($options['digits']) {
            $map += self::DIGITS;
        }

        if ($options['latin_accents']) {
            $map += self::LATIN + self::removals(self::COMBINING_RANGES);
        }

        $this->map = $map;
        $this->stripDefiniteArticle = (bool) $options['strip_definite_article'];
        $this->preserved = self::preservable($options['preserve']);

        $ascii = array_filter($this->preserved, static fn (string $char): bool => strlen($char) === 1);

        $this->pattern = '/[^\p{L}\p{N}\p{M}'.self::quote($this->preserved).']+/u';
        $this->asciiPattern = '/[^a-z0-9'.self::quote($ascii).']+/';
    }

    /**
     * Normalize a piece of text.
     */
    public function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // Pure ASCII needs no multibyte case folding and no translation table.
        if (preg_match('/[\x80-\xFF]/', $text) !== 1) {
            return trim((string) preg_replace($this->asciiPattern, ' ', strtolower($text)), ' ');
        }

        $text = trim((string) preg_replace(
            $this->pattern,
            ' ',
            strtr(mb_strtolower($text, 'UTF-8'), $this->map),
        ), ' ');

        if ($this->stripDefiniteArticle && $text !== '') {
            return preg_replace(self::ARTICLE, '', $text) ?? $text;
        }

        return $text;
    }

    /**
     * The characters that are kept inside words.
     *
     * @return list<string>
     */
    public function preserved(): array
    {
        return $this->preserved;
    }

    /**
     * Build a "remove this character" table for the given code point ranges.
     *
     * @param  list<array{int, int}>  $ranges
     * @return array<string, string>
     */
    private static function removals(array $ranges): array
    {
        $map = [];

        foreach ($ranges as [$from, $to]) {
            for ($codePoint = $from; $codePoint <= $to; $codePoint++) {
                $map[mb_chr($codePoint, 'UTF-8')] = '';
            }
        }

        return $map;
    }

    /**
     * Reduce the "preserve" option to a list of single, safe characters.
     *
     * @return list<string>
     */
    private static function preservable(mixed $preserve): array
    {
        $characters = [];

        foreach ((array) $preserve as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            foreach (mb_str_split($entry, 1, 'UTF-8') as $character) {
                if (! in_array($character, self::FORBIDDEN, true) && preg_match('/^[^\s\p{C}]$/u', $character) === 1) {
                    $characters[$character] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($characters));
    }

    /**
     * Quote characters for use inside a regular expression character class.
     *
     * @param  array<array-key, string>  $characters
     */
    private static function quote(array $characters): string
    {
        return implode('', array_map(static fn (string $character): string => preg_quote($character, '/'), $characters));
    }
}
