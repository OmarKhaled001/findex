<?php

declare(strict_types=1);

namespace OmarKhaled\Findex;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use OmarKhaled\Findex\Drivers\Driver;
use OmarKhaled\Findex\Drivers\FullTextDriver;
use OmarKhaled\Findex\Drivers\LikeDriver;

/**
 * The Findex service: normalizes text, parses search terms and applies them to queries.
 *
 * It is registered as a singleton. Everything it holds is immutable and derived
 * from configuration; the only thing that changes between requests, the locale,
 * is resolved at call time. That keeps the instance safe to share under Octane.
 */
final class Findex
{
    public const string MODE_AUTO = 'auto';

    public const string MODE_BOTH = 'both';

    public const string MODE_ARABIC = 'ar';

    public const string MODE_ENGLISH = 'en';

    public const string MODE_OFF = 'off';

    /** @var list<string> */
    public const array MODES = [
        self::MODE_AUTO, self::MODE_BOTH, self::MODE_ARABIC, self::MODE_ENGLISH, self::MODE_OFF,
    ];

    /**
     * The number of parsed terms kept in memory.
     */
    private const int CACHE_SIZE = 128;

    /**
     * Longer terms are parsed every time, so the cache cannot be filled with huge strings.
     */
    private const int CACHEABLE_BYTES = 256;

    private readonly Normalizer $normalizer;

    private readonly string $column;

    private readonly string $defaultDriver;

    private readonly string $mode;

    /** @var list<string> */
    private readonly array $arabicLocales;

    private readonly int $maxTokens;

    /** @var array<string, Driver> */
    private readonly array $drivers;

    /** @var Closure(): string */
    private readonly Closure $locale;

    /**
     * Parsed terms, keyed by keyboard direction and raw term. A pure memo: it holds no request state.
     *
     * @var array<string, list<list<string>>>
     */
    private array $cache = [];

    /**
     * @param  array<string, mixed>  $config  The "findex" configuration array. Missing keys use the defaults.
     * @param  (Closure(): string)|null  $locale  Resolves the current locale. Defaults to the application locale.
     */
    public function __construct(array $config = [], ?Closure $locale = null)
    {
        $keyboard = (array) ($config['keyboard'] ?? []);
        $fullText = (array) ($config['fulltext'] ?? []);

        $this->normalizer = new Normalizer((array) ($config['normalization'] ?? []));
        $this->column = (string) ($config['column'] ?? 'search_index');
        $this->mode = (string) ($keyboard['mode'] ?? self::MODE_AUTO);
        $this->arabicLocales = array_values(array_map(
            static fn (mixed $prefix): string => strtolower((string) $prefix),
            (array) ($keyboard['arabic_locales'] ?? ['ar']),
        ));
        $this->maxTokens = max(1, (int) ($config['max_tokens'] ?? 10));

        $like = new LikeDriver;

        $this->drivers = [
            'like' => $like,
            'fulltext' => new FullTextDriver(
                $like,
                max(1, (int) ($fullText['min_token_length'] ?? 3)),
                // Compared against normalized variants, so they are normalized too.
                array_values(array_filter(
                    array_map(
                        fn (mixed $word): string => $this->normalizer->normalize((string) $word),
                        (array) ($fullText['stopwords'] ?? FullTextDriver::INNODB_STOPWORDS),
                    ),
                    static fn (string $word): bool => $word !== '',
                )),
            ),
        ];

        $this->defaultDriver = (string) ($config['driver'] ?? 'like');

        if (! in_array($this->mode, self::MODES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported Findex keyboard mode [%s]. Supported modes: %s.',
                $this->mode,
                implode(', ', self::MODES),
            ));
        }

        $this->driver($this->defaultDriver);

        // Never capture the locale: the singleton outlives the request under Octane.
        $this->locale = $locale ?? static function (): string {
            $container = Container::getInstance();

            return $container->bound('config')
                ? (string) $container->make('config')->get('app.locale', 'en')
                : 'en';
        };
    }

    /**
     * Normalize text the same way the index column is built.
     */
    public function normalize(?string $text): string
    {
        return $this->normalizer->normalize($text);
    }

    /**
     * Convert text typed on an English keyboard layout to the Arabic layout.
     */
    public function toArabicLayout(string $text): string
    {
        return Keyboard::toArabic($text);
    }

    /**
     * Convert text typed on an Arabic keyboard layout to the English layout.
     *
     * More than one reading is returned when the text contains the ambiguous "لا".
     *
     * @return list<string>
     */
    public function toEnglishLayout(string $text): array
    {
        return Keyboard::toEnglish($text);
    }

    /**
     * Parse a search term into word groups.
     *
     * Every group is a list of normalized variants of one word: the word itself
     * first, then its keyboard layout alternatives. A row matches when it matches
     * at least one variant of every group.
     *
     * @return list<list<string>>
     */
    public function parse(?string $term): array
    {
        if ($term === null || $term === '') {
            return [];
        }

        [$toArabic, $toEnglish] = $this->directions();

        $key = ($toArabic ? 'a' : '-').($toEnglish ? 'e' : '-').$term;

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $groups = $this->tokenize($term, $toArabic, $toEnglish);

        if (strlen($term) <= self::CACHEABLE_BYTES) {
            if (count($this->cache) >= self::CACHE_SIZE) {
                unset($this->cache[array_key_first($this->cache)]);
            }

            $this->cache[$key] = $groups;
        }

        return $groups;
    }

    /**
     * Constrain a query to the rows whose index column matches the term.
     *
     * The constraints are wrapped in one nested where, so they compose safely
     * with whatever else is on the query. An empty term leaves the query untouched.
     *
     * @param  string|null  $driver  "like" or "fulltext". Defaults to the configured driver.
     */
    public function apply(Builder $query, string $column, ?string $term, ?string $driver = null): Builder
    {
        $groups = $this->parse($term);

        if ($groups === []) {
            return $query;
        }

        $driver = $this->driver($driver);

        return $query->where(static function (Builder $nested) use ($driver, $column, $groups): void {
            $driver->apply($nested, $column, $groups);
        });
    }

    /**
     * The default name of the index column.
     */
    public function column(): string
    {
        return $this->column;
    }

    /**
     * The normalizer used for both the index column and search terms.
     */
    public function normalizer(): Normalizer
    {
        return $this->normalizer;
    }

    /**
     * Split a term into words and build one group of variants per word.
     *
     * @return list<list<string>>
     */
    private function tokenize(string $term, bool $toArabic, bool $toEnglish): array
    {
        $words = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false) {
            // Not valid UTF-8: replace the broken bytes and try once more.
            $words = preg_split('/\s+/u', mb_scrub($term, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $groups = [];

        foreach ($words as $word) {
            $variants = $this->variants($word, $toArabic, $toEnglish);

            if ($variants === []) {
                continue;
            }

            $groups[implode("\0", $variants)] = $variants;

            if (count($groups) >= $this->maxTokens) {
                break;
            }
        }

        return array_values($groups);
    }

    /**
     * The normalized word followed by its normalized keyboard layout alternatives.
     *
     * Alternatives are computed on the raw word, before normalization, because
     * punctuation keys carry letters (the "]" key types "د").
     *
     * @return list<string>
     */
    private function variants(string $word, bool $toArabic, bool $toEnglish): array
    {
        $variants = [$this->normalizer->normalize($word) => true];

        if ($toArabic && preg_match('/[a-zA-Z]/', $word) === 1) {
            $variants[$this->normalizer->normalize(Keyboard::toArabic($word))] = true;

            // Caps lock and auto-capitalization turn plain keys into Shift letters (H types أ, h types ا).
            if (($lower = strtolower($word)) !== $word) {
                $variants[$this->normalizer->normalize(Keyboard::toArabic($lower))] = true;
            }
        }

        if ($toEnglish && preg_match('/[\x{0621}-\x{064A}]/u', $word) === 1) {
            foreach (Keyboard::toEnglish($word) as $reading) {
                $variants[$this->normalizer->normalize($reading)] = true;
            }
        }

        unset($variants['']);

        return array_map(strval(...), array_keys($variants));
    }

    /**
     * Which layout conversions apply right now.
     *
     * @return array{bool, bool} [latin words also tried as arabic, arabic words also tried as english]
     */
    private function directions(): array
    {
        return match ($this->mode) {
            self::MODE_OFF => [false, false],
            self::MODE_BOTH => [true, true],
            self::MODE_ARABIC => [true, false],
            self::MODE_ENGLISH => [false, true],
            default => $this->isArabicLocale() ? [true, false] : [false, true],
        };
    }

    private function isArabicLocale(): bool
    {
        $locale = strtolower(($this->locale)());

        foreach ($this->arabicLocales as $prefix) {
            if ($prefix !== '' && str_starts_with($locale, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function driver(?string $name): Driver
    {
        $name ??= $this->defaultDriver;

        return $this->drivers[$name] ?? throw new InvalidArgumentException(sprintf(
            'Unsupported Findex driver [%s]. Supported drivers: %s.',
            $name,
            implode(', ', array_keys($this->drivers)),
        ));
    }
}
