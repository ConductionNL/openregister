# Tasks: property-filter-like

## 1. Operator

- [x] 1.1 `LikeOperator`: terms, escaping, SQL per platform (PostgreSQL, MySQL/MariaDB, SQLite).
- [x] 1.2 `like` in `COMPARISON_OPERATORS` and in all four `MagicSearchHandler` condition builders.
- [x] 1.3 `like` in the `DbalObjectSourceProvider` listing filter.

## 2. Tests

- [x] 2.1 `LikeOperatorTest`: SQL per platform, escaping, real matches on SQLite (bound and quoted forms).
- [x] 2.2 `MagicSearchHandlerLikeOperatorTest`: both paths, object fields and metadata, PostgreSQL and MariaDB.
- [x] 2.3 `DbalObjectSourceProviderTest`: substring and literal wildcards against a real SQLite table.

## 3. Docs

- [x] 3.1 Document `like` beside the other operators in `docs/Features/search.md`.
