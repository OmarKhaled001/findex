<?php

declare(strict_types=1);

use OmarKhaled\Findex\Drivers\FullTextDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Index column
    |--------------------------------------------------------------------------
    |
    | The name of the column that stores the normalized, searchable text of a
    | model. It is the column added by "$table->findex()" in a migration and
    | the column searched by "Model::findex($term)".
    |
    | A single model can use another column through the attribute:
    | #[FindexColumns(['name'], index: 'needle')]
    |
    */

    'column' => 'search_index',

    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | How the index column is searched. A call can pick another driver:
    | "Model::findex($term, driver: 'fulltext')".
    |
    | "like"      column LIKE '%word%'. Substring match, works on every
    |             database. Add a trigram index on PostgreSQL to keep it fast
    |             on large tables: "$table->findex(trigram: true)".
    |
    | "fulltext"  MATCH ... AGAINST in boolean mode. Prefix match (a word
    |             starting with the term), MySQL and MariaDB only, and needs
    |             "$table->findex(fullText: true)". Any other database falls
    |             back to "like" automatically.
    |
    */

    'driver' => env('FINDEX_DRIVER', 'like'),

    'fulltext' => [

        /*
        | The shortest word the FULLTEXT index stores. Keep it equal to the
        | server's "innodb_ft_min_token_size" (default 3; "ft_min_word_len"
        | for MyISAM, default 4). Shorter words are not in the index, so the
        | "fulltext" driver searches them with LIKE instead.
        */

        'min_token_length' => 3,

        /*
        | Words the FULLTEXT index does not store. A required word that is not
        | in the index matches nothing, so these are searched with LIKE too:
        | without this, "the matrix" would find no rows. The default is the
        | built-in InnoDB list (a, about, an, are, as, at, be, by, com, de, en,
        | for, from, how, i, in, is, it, la, of, on, or, that, the, this, to,
        | und, was, what, when, where, who, will, with, www).
        |
        | Use [] when the server runs with "innodb_ft_enable_stopword = OFF",
        | or list your own words when it uses a custom stopword table.
        */

        'stopwords' => FullTextDriver::INNODB_STOPWORDS,

    ],

    /*
    |--------------------------------------------------------------------------
    | Keyboard layout correction
    |--------------------------------------------------------------------------
    |
    | A word typed with the wrong keyboard layout is searched as typed AND as
    | the same keys would read on the other layout: "lpl]" also finds "محمد",
    | "اثممخ" also finds "hello". Layouts: Arabic (101 / PC) and US QWERTY.
    |
    | mode:
    |   "auto"  Follow the application locale (default).
    |           Arabic locale -> Latin words are also tried as Arabic.
    |           Other locale  -> Arabic words are also tried as English.
    |   "ar"    Always behave like an Arabic locale.
    |   "en"    Always behave like a non-Arabic locale.
    |   "both"  Try both directions on every search.
    |   "off"   Never try another layout.
    |
    | arabic_locales:
    |   A locale is Arabic when it starts with one of these prefixes, so "ar"
    |   covers "ar", "ar_EG" and "ar-SA". Only used by the "auto" mode.
    |
    */

    'keyboard' => [

        'mode' => env('FINDEX_KEYBOARD_MODE', 'auto'),

        'arabic_locales' => ['ar'],

    ],

    /*
    |--------------------------------------------------------------------------
    | Normalization
    |--------------------------------------------------------------------------
    |
    | The rules applied to the indexed text and to every search term, so both
    | always meet in the same form. Text is always lowercased, punctuation
    | always becomes a single space, invisible characters (zero-width and
    | directional marks) are always removed and the Persian letters ک and ی
    | are always folded into ك and ي.
    |
    | The index column stores the result of these rules. After changing any of
    | them, rebuild it: "php artisan findex:reindex YourModel".
    |
    */

    'normalization' => [

        // أ إ آ ٱ ٲ ٳ -> ا                     "أحمد" = "احمد"
        'alef' => true,

        // ة ۃ ۀ -> ه                           "مدرسة" = "مدرسه"
        'ta_marbuta' => true,

        // ى -> ي                               "مستشفى" = "مستشفي"
        'alef_maqsura' => true,

        // ؤ -> و and ئ -> ي (a standalone ء is kept)   "مؤمن" = "مومن"
        'hamza' => true,

        // Remove tashkeel, Quranic marks and tatweel   "مُحَمَّد" = "محـمد" = "محمد"
        'diacritics' => true,

        // ٠-٩ and ۰-۹ -> 0-9                   "١٢٣" = "123"
        'digits' => true,

        // é -> e, ß -> ss, æ -> ae, ø -> o ...  "Café" = "cafe"
        'latin_accents' => true,

        // Remove a leading "ال" from words that keep at least three letters:
        // "البيت" = "بيت". Off by default because it also strips the "ال" of
        // words where it is not the article, such as "الماس".
        'strip_definite_article' => false,

        // Characters kept inside words instead of becoming a space, for
        // example ['#', '+'] so "C#" and "C++" stay searchable as written.
        // The LIKE wildcards % and _ and the backslash are never kept.
        'preserve' => [],

    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum number of words
    |--------------------------------------------------------------------------
    |
    | Every word of a search term adds one condition to the query. Words after
    | this limit are ignored, which keeps a pasted paragraph from producing a
    | huge query.
    |
    */

    'max_tokens' => 10,

];
