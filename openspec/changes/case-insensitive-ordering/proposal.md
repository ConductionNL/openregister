---
kind: code
depends_on: []
---

# Proposal: case-insensitive-ordering

## Why

Row 6.27, "Sorting and matching ignore letter case throughout", is `partial` in our column (`baseline/openwoo.tsv`): OpenRegister's search matches case-insensitively (`ILIKE`), but ordering is whatever the database collation does, and nothing asserts it. On PostgreSQL with a C or byte-order collation, "aanvraag" sorts after "Zienswijze"; on MariaDB with a `_ci` collation it does not. The same list sorts differently per installation, and a citizen paging through publications alphabetically misses records.

## What changes

- `MagicSearchHandler` orders a string property by its lower-cased value first, then by the raw value, then by `_id`, on PostgreSQL and MySQL/MariaDB alike. The raw-value and `_id` tie-breaks make the order total, so paging is stable.
- Non-string properties (numbers, dates, booleans) keep their native ordering.
- On PostgreSQL the magic table index builder adds a `lower()` expression index beside each existing string index, so ordering stays indexable.
- Tests assert the generated SQL on both platforms and the resulting order on a mixed-case fixture.

## What does not change

- Matching, already case-insensitive.
- Accents: `search-accent-insensitive` (open, 0/7) owns accent-insensitive matching and, when it lands, ordering; this change does not touch accents.

## Dependencies

None.

## Wave and decision

Wave 1, size S. Closes 6.27.
