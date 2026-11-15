<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Drivers;

use Illuminate\Database\Query\Builder;
use Override;

/**
 * Prefix search through a MySQL / MariaDB FULLTEXT index, in boolean mode.
 *
 * Every group the index can answer goes into one MATCH ... AGAINST expression:
 *
 *   +(word* alternative*) +(word2*)
 *
 * Groups the index cannot answer (words shorter than the minimum token size,
 * stopwords, multi-word variants, variants containing a preserved character)
 * fall back to LIKE. On any other database the whole search falls back to LIKE.
 */
final readonly class FullTextDriver implements Driver
{
    /**
     * Connection drivers that compile whereFullText() to MATCH ... AGAINST.
     *
     * @var list<string>
     */
    public const array SUPPORTED = ['mysql', 'mariadb'];

    /**
     * The words InnoDB leaves out of every FULLTEXT index by default
     * (INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD, MySQL and MariaDB).
     *
     * A required term that is not in the index matches no row, so a search for
     * "the matrix" would find nothing if "the" were sent to the index.
     *
     * @var list<string>
     */
    public const array INNODB_STOPWORDS = [
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for',
        'from', 'how', 'i', 'in', 'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the',
        'this', 'to', 'und', 'was', 'what', 'when', 'where', 'who', 'will', 'with', 'www',
    ];

    /**
     * The longest word InnoDB stores (innodb_ft_max_token_size).
     */
    private const int MAX_TOKEN_LENGTH = 84;

    /** @var array<string, true> */
    private array $stopwords;

    /**
     * @param  list<string>  $stopwords  Normalized words the index does not store.
     */
    public function __construct(
        private LikeDriver $like = new LikeDriver,
        private int $minTokenLength = 3,
        array $stopwords = self::INNODB_STOPWORDS,
    ) {
        $this->stopwords = array_fill_keys($stopwords, true);
    }

    #[Override]
    public function apply(Builder $query, string $column, array $groups): void
    {
        if (! in_array($query->getConnection()->getDriverName(), self::SUPPORTED, true)) {
            $this->like->apply($query, $column, $groups);

            return;
        }

        $expressions = [];
        $fallback = [];

        foreach ($groups as $variants) {
            if ($this->supports($variants)) {
                $expressions[] = '+('.implode('* ', $variants).'*)';
            } else {
                $fallback[] = $variants;
            }
        }

        if ($expressions === []) {
            $this->like->apply($query, $column, $fallback);

            return;
        }

        $query->whereFullText($column, implode(' ', $expressions), ['mode' => 'boolean']);

        foreach ($fallback as $variants) {
            $this->like->group($query, $column, $variants);
        }
    }

    /**
     * A group is answered by the index when every variant is one plain word that
     * the index stores. Anything but letters, numbers and marks would be a word
     * boundary or a boolean operator (+ - < > ( ) ~ * " @) to the parser.
     *
     * @param  non-empty-list<string>  $variants
     */
    private function supports(array $variants): bool
    {
        foreach ($variants as $variant) {
            $length = mb_strlen($variant, 'UTF-8');

            if ($length < $this->minTokenLength
                || $length > self::MAX_TOKEN_LENGTH
                || isset($this->stopwords[$variant])
                || preg_match('/[^\p{L}\p{N}\p{M}]/u', $variant) === 1) {
                return false;
            }
        }

        return true;
    }
}
