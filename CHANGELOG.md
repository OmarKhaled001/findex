# Changelog

All notable changes to `omarkhaled001/findex` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `Normalizer`: single-pass Arabic and Latin normalization (alef, ta marbuta, alef maqsura, hamza,
  diacritics, digits, Latin accents, optional definite article, preserved characters). Its output can
  never contain a LIKE wildcard.
- `Keyboard`: Arabic (101 / PC) and US QWERTY layout conversion in both directions, including the
  ambiguous `لا` (B, or G then H).
- `Findex` service and facade: `normalize()`, `parse()`, `toArabicLayout()`, `toEnglishLayout()`, `apply()`.
- Keyboard modes `auto`, `ar`, `en`, `both` and `off`. `auto` follows the application locale, read on every call.
- `Findexable` trait with the `findex()` scope and an index column kept in sync on save.
- `#[FindexColumns]` attribute: real columns, accessors, dot-notation relations, array / JSON paths and
  translatable attributes.
- `like` driver (all databases) and `fulltext` driver (MySQL / MariaDB boolean mode, with LIKE fallback
  for short words, stopwords and phrases, and for every other database).
- `whereFindex()` query builder macro and `findex()` schema blueprint macro with optional FULLTEXT
  (MySQL / MariaDB) and GIN trigram (PostgreSQL) indexes.
- `Reindexer` and the `findex:reindex` command to backfill existing rows.

[Unreleased]: https://github.com/OmarKhaled001/findex/commits/main
