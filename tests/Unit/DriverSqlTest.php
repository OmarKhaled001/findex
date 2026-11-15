<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteConnection;
use OmarKhaled\Findex\Findex;

/**
 * A connection that compiles SQL but can never reach a database server.
 *
 * @param  class-string<Connection>  $class
 */
function offline(string $class, string $driver): Connection
{
    $pdo = static fn () => throw new RuntimeException('These tests must not open a database connection.');

    return new $class($pdo, 'findex', '', ['driver' => $driver, 'name' => $driver]);
}

function search(Connection $connection, ?string $term, ?string $driver = null, array $config = [], string $locale = 'ar'): Builder
{
    $findex = new Findex($config, static fn (): string => $locale);

    return $findex->apply($connection->table('products'), 'search_index', $term, $driver);
}

describe('like driver', function () {
    it('compiles a single word', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'محمد');

        expect($query->toSql())->toBe('select * from `products` where (`search_index` like ?)')
            ->and($query->getBindings())->toBe(['%محمد%']);
    });

    it('ors the variants of a single group inside the one nested where', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'lpl]');

        expect($query->toSql())
            ->toBe('select * from `products` where (`search_index` like ? or `search_index` like ?)')
            ->and($query->getBindings())->toBe(['%lpl%', '%محمد%']);
    });

    it('ands the groups and only nests the groups that have alternatives', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'lpl] ١٢٣ iphone');

        expect($query->toSql())->toBe(
            'select * from `products` where ('
            .'(`search_index` like ? or `search_index` like ?)'
            .' and `search_index` like ?'
            .' and (`search_index` like ? or `search_index` like ?)'
            .')'
        )->and($query->getBindings())->toBe(['%lpl%', '%محمد%', '%123%', '%iphone%', '%هحاخيث%']);
    });

    it('uses plain wheres when no group has alternatives', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'red iphone 15', locale: 'en');

        expect($query->toSql())->toBe(
            'select * from `products` where (`search_index` like ? and `search_index` like ? and `search_index` like ?)'
        )->and($query->getBindings())->toBe(['%red%', '%iphone%', '%15%']);
    });

    it('compiles for every grammar', function (string $class, string $driver, string $sql) {
        expect(search(offline($class, $driver), 'lpl] iphone')->toSql())->toBe($sql);
    })->with([
        'sqlite' => [
            SQLiteConnection::class, 'sqlite',
            'select * from "products" where (("search_index" like ? or "search_index" like ?) and ("search_index" like ? or "search_index" like ?))',
        ],
        'pgsql' => [
            PostgresConnection::class, 'pgsql',
            'select * from "products" where (("search_index"::text like ? or "search_index"::text like ?) and ("search_index"::text like ? or "search_index"::text like ?))',
        ],
        'mariadb' => [
            MariaDbConnection::class, 'mariadb',
            'select * from `products` where ((`search_index` like ? or `search_index` like ?) and (`search_index` like ? or `search_index` like ?))',
        ],
    ]);

    it('leaves the query untouched for an empty term', function (?string $term) {
        $query = search(offline(MySqlConnection::class, 'mysql'), $term);

        expect($query->toSql())->toBe('select * from `products`')
            ->and($query->getBindings())->toBe([]);
    })->with([null, '', '   ', '!!!']);

    it('composes with the wheres already on the query', function () {
        $connection = offline(MySqlConnection::class, 'mysql');
        $findex = new Findex([], static fn (): string => 'ar');

        $query = $connection->table('products')->where('active', true)->orWhere('featured', true);
        $findex->apply($query, 'products.search_index', 'lpl]');

        expect($query->toSql())->toBe(
            'select * from `products` where `active` = ? or `featured` = ?'
            .' and (`products`.`search_index` like ? or `products`.`search_index` like ?)'
        )->and($query->getBindings())->toBe([true, true, '%lpl%', '%محمد%']);
    });

    it('never binds a like wildcard', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), '100% _off_ a\\b %_%', locale: 'en');

        expect($query->getBindings())->toBe(['%100%', '%off%', '%a b%']);
    });
});

describe('fulltext driver', function () {
    it('puts every group into one boolean mode match', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'lpl] iphone', 'fulltext');

        expect($query->toSql())
            ->toBe('select * from `products` where (match (`search_index`) against (? in boolean mode))')
            ->and($query->getBindings())->toBe(['+(lpl* محمد*) +(iphone* هحاخيث*)']);
    });

    it('compiles a single word', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'محمد', 'fulltext');

        expect($query->toSql())
            ->toBe('select * from `products` where (match (`search_index`) against (? in boolean mode))')
            ->and($query->getBindings())->toBe(['+(محمد*)']);
    });

    it('works on mariadb', function () {
        $query = search(offline(MariaDbConnection::class, 'mariadb'), 'محمد علي', 'fulltext');

        expect($query->toSql())
            ->toBe('select * from `products` where (match (`search_index`) against (? in boolean mode))')
            ->and($query->getBindings())->toBe(['+(محمد*) +(علي*)']);
    });

    it('falls back to like for words shorter than the minimum token length', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'iphone 15', 'fulltext', locale: 'en');

        expect($query->toSql())->toBe(
            'select * from `products` where (match (`search_index`) against (? in boolean mode) and `search_index` like ?)'
        )->and($query->getBindings())->toBe(['+(iphone*)', '%15%']);
    });

    it('falls back to like for a group when any of its variants is too short', function () {
        // "hg" is two characters, its arabic alternative "ال" as well.
        $query = search(offline(MySqlConnection::class, 'mysql'), 'lpl] hg', 'fulltext');

        expect($query->toSql())->toBe(
            'select * from `products` where (match (`search_index`) against (? in boolean mode)'
            .' and (`search_index` like ? or `search_index` like ?))'
        )->and($query->getBindings())->toBe(['+(lpl* محمد*)', '%hg%', '%ال%']);
    });

    it('falls back to like for multi-word variants', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'SKU-123 laptop', 'fulltext', locale: 'en');

        expect($query->toSql())->toBe(
            'select * from `products` where (match (`search_index`) against (? in boolean mode) and `search_index` like ?)'
        )->and($query->getBindings())->toBe(['+(laptop*)', '%sku 123%']);
    });

    it('falls back to like for variants that contain a boolean operator', function () {
        $config = ['normalization' => ['preserve' => ['+', '-', '#']]];
        $query = search(offline(MySqlConnection::class, 'mysql'), 'c++ node-js developer', 'fulltext', $config, 'en');

        expect($query->toSql())->toBe(
            'select * from `products` where (match (`search_index`) against (? in boolean mode)'
            .' and `search_index` like ? and `search_index` like ?)'
        )->and($query->getBindings())->toBe(['+(developer*)', '%c++%', '%node-js%']);
    });

    it('uses only like when no group can use the index', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'tv 4k', 'fulltext', locale: 'en');

        expect($query->toSql())
            ->toBe('select * from `products` where (`search_index` like ? and `search_index` like ?)')
            ->and($query->getBindings())->toBe(['%tv%', '%4k%']);
    });

    it('respects the configured minimum token length', function () {
        $config = ['fulltext' => ['min_token_length' => 2]];
        $query = search(offline(MySqlConnection::class, 'mysql'), 'tv 4k x', 'fulltext', $config, 'en');

        expect($query->toSql())->toBe(
            'select * from `products` where (match (`search_index`) against (? in boolean mode) and `search_index` like ?)'
        )->and($query->getBindings())->toBe(['+(tv*) +(4k*)', '%x%']);
    });

    it('falls back to like for the words innodb never indexes', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'The Matrix with Neo', 'fulltext', locale: 'en');

        expect($query->toSql())->toBe(
            'select * from `products` where (match (`search_index`) against (? in boolean mode)'
            .' and `search_index` like ? and `search_index` like ?)'
        )->and($query->getBindings())->toBe(['+(matrix*) +(neo*)', '%the%', '%with%']);
    });

    it('falls back to like when a layout alternative is a stopword', function () {
        // "فاث" is what the keys of "the" type on an arabic layout.
        $query = search(offline(MySqlConnection::class, 'mysql'), 'فاث', 'fulltext', locale: 'en');

        expect($query->toSql())
            ->toBe('select * from `products` where (`search_index` like ? or `search_index` like ?)')
            ->and($query->getBindings())->toBe(['%فاث%', '%the%']);
    });

    it('accepts a custom stopword list, compared in normalized form', function () {
        $config = ['fulltext' => ['stopwords' => ['Café', 'فِي', 'مدرسة']]];
        $query = search(offline(MySqlConnection::class, 'mysql'), 'the cafe مدرسه laptop', 'fulltext', $config, 'ar');

        expect($query->getBindings())->toBe(['+(the* فاث*) +(laptop* مشحفخح*)', '%cafe%', '%وشبث%', '%مدرسه%']);
    });

    it('sends every word to the index when stopwords are disabled', function () {
        $config = ['fulltext' => ['stopwords' => []]];
        $query = search(offline(MySqlConnection::class, 'mysql'), 'the matrix', 'fulltext', $config, 'en');

        expect($query->getBindings())->toBe(['+(the*) +(matrix*)']);
    });

    it('falls back to like for words longer than innodb stores', function () {
        $long = str_repeat('a', 85);
        $query = search(offline(MySqlConnection::class, 'mysql'), $long.' '.str_repeat('b', 84), 'fulltext', locale: 'en');

        expect($query->getBindings())->toBe(['+('.str_repeat('b', 84).'*)', '%'.$long.'%']);
    });

    it('counts characters, not bytes, for the minimum token length', function () {
        // "في" is two characters but four bytes.
        $query = search(offline(MySqlConnection::class, 'mysql'), 'في', 'fulltext', locale: 'ar');

        expect($query->toSql())->toBe('select * from `products` where (`search_index` like ?)');
    });

    it('falls back to like on databases without match against', function (string $class, string $driver, string $sql) {
        $query = search(offline($class, $driver), 'lpl] iphone', 'fulltext');

        expect($query->toSql())->toBe($sql)
            ->and($query->getBindings())->toBe(['%lpl%', '%محمد%', '%iphone%', '%هحاخيث%']);
    })->with([
        'sqlite' => [
            SQLiteConnection::class, 'sqlite',
            'select * from "products" where (("search_index" like ? or "search_index" like ?) and ("search_index" like ? or "search_index" like ?))',
        ],
        'pgsql' => [
            PostgresConnection::class, 'pgsql',
            'select * from "products" where (("search_index"::text like ? or "search_index"::text like ?) and ("search_index"::text like ? or "search_index"::text like ?))',
        ],
    ]);

    it('can be the configured default driver', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'laptop', null, ['driver' => 'fulltext'], 'en');

        expect($query->toSql())
            ->toBe('select * from `products` where (match (`search_index`) against (? in boolean mode))');
    });

    it('can be overridden per call', function () {
        $query = search(offline(MySqlConnection::class, 'mysql'), 'laptop', 'like', ['driver' => 'fulltext'], 'en');

        expect($query->toSql())->toBe('select * from `products` where (`search_index` like ?)');
    });
});

it('rejects an unknown driver', function () {
    search(offline(MySqlConnection::class, 'mysql'), 'laptop', 'elastic');
})->throws(InvalidArgumentException::class, 'Unsupported Findex driver [elastic]. Supported drivers: like, fulltext.');
