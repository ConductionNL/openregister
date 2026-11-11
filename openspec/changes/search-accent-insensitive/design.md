# Design: search-accent-insensitive

Read at openregister development c53dd0685c.

## D-1: one helper for every comparison

A new `lib/Db/MagicMapper/SearchFolding.php` answers two questions for a
platform and a mode (`folded` or `exact`):

- `column(string $quotedColumn, bool $nullSafe): string`, the expression to
  compare;
- `pattern(string $quotedPattern): string`, the expression it is compared to;

and a third, `matchOperator(): string`. The three places that build search
comparisons today call it instead of writing their own SQL:

| place | today |
|---|---|
| `MagicSearchHandler::columnMatchSql()` (`lib/Db/MagicMapper/MagicSearchHandler.php:1354-1378`), reached by plain and boolean leaves through `buildSearchLeafSql()` (`:1224`) | `ILIKE` on PostgreSQL, `LOWER(CAST(...)) LIKE LOWER(...)` elsewhere |
| `MagicSearchHandler::applyFullTextSearch()` (`:3020-3116`) | `LOWER(t.col) LIKE` (`:3088-3103`), plus `similarity(t._name::text, term)` for fuzzy (`:3111`) |
| the relevance score and the fuzzy ordering (`:367`, `:3226`) | `similarity(t._name::text, term)` |
| `MagicFacetHandler` search condition (`lib/Db/MagicMapper/MagicFacetHandler.php:1950-1970`) | `LOWER(col) ILIKE LOWER(...)` or `LIKE` |

The facet path moving onto the helper is what keeps a facet count equal to the
number of results behind it.

## D-2: PostgreSQL

A migration modelled on the `pg_trgm` bootstrap
(`lib/Migration/Version1Date20260706110000.php`) runs
`CREATE EXTENSION IF NOT EXISTS unaccent` and creates an immutable wrapper:

```sql
CREATE OR REPLACE FUNCTION openregister_fold(text) RETURNS text
  LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
  AS $$ SELECT lower(public.unaccent('public.unaccent', $1)) $$;
```

`unaccent()` itself is only STABLE, so an index cannot use it; the
dictionary-qualified call in an IMMUTABLE wrapper is the standard way to index
folded text. A failure is logged and leaves the setting unavailable, exactly as
the `pg_trgm` migration degrades. Availability is checked once per request the
way `hasPgTrgmExtension()` does (`MagicSearchHandler.php:236-262`), by looking
for the function.

Folded comparison: `openregister_fold(col::text) LIKE openregister_fold(pattern)`
(`LIKE`, because both sides are lowered). Fuzzy:
`similarity(openregister_fold(t._name::text), openregister_fold(term))`.

The trigram indexes that `MagicMapper` creates for search
(`lib/Db/MagicMapper.php:3497` for `_name`, `:3596-3610` for searchable
properties) are created on `openregister_fold(col::text)` when folding is
available, so the folded comparison keeps the index path the raw one has.

## D-3: MariaDB and MySQL

Nextcloud creates tables that may be `utf8mb4_bin`, which is why the current
code lowers both sides (`MagicSearchHandler.php:1370-1372`). Folded comparison:
`CAST(col AS CHAR) COLLATE utf8mb4_unicode_ci LIKE pattern COLLATE utf8mb4_unicode_ci`.
`utf8mb4_unicode_ci` is accent- and case-insensitive for `LIKE` and exists on
every MariaDB and MySQL version Open Register supports (`mariadb-ci-matrix`). No
extension is needed, so the setting is always available there. An index does
not help a leading-wildcard `LIKE` on either platform today, so nothing is lost.

## D-4: the setting and the parameter

- `GET /api/settings/search-backend` (`appinfo/routes.php:274`) reports
  `accentInsensitive: {enabled, available, reason}`; `PATCH` on the same route
  accepts `accentInsensitive: true|false`. Enabling it where it is unavailable
  answers 400 naming the reason (for example "the unaccent extension could not
  be created").
- Default: enabled when available.
- `_accents=exact` on an object search uses the old comparison for that
  request; any other value is ignored.
- A new `SearchConfiguration.vue` in `src/views/settings/sections/` shows the
  state and the switch.

## Declarative-vs-imperative decision

Not applicable. This changes how a search compares text, not lifecycle,
aggregations, calculations, notifications, relations or widgets.

## Risks

- Performance (openregister ADR-009): on PostgreSQL the folded expression is
  indexable through the wrapper (D-2); without the index a folded scan costs a
  function call per row, which the builder measures on the fleet's largest
  register before enabling by default. The acceptance below names the number.
- Wider results: a search now returns records it did not before. That is the
  purpose, and `_accents=exact` gives the old answer.
- Extension rights: `unaccent` is a trusted extension from PostgreSQL 13, so a
  database owner can create it; on an instance where it cannot be created the
  setting says so rather than pretending.
- Schema sync: the existing trigram indexes are kept on plain columns partly
  so Doctrine introspection of a magic table stays safe
  (`lib/Db/MagicMapper.php:3596-3601`). An expression index is a new shape
  there. The builder confirms the table update path neither drops nor
  recreates it on every sync; if it does, the folded index is created by a
  repair step instead, and the acceptance measurement is taken without it.
