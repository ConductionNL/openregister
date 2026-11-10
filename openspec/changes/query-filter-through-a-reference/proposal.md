---
kind: code
depends_on: []
---
# Proposal: query-filter-through-a-reference

## Summary

"Give me the requests whose applicant lives in Zuiddrecht." The request references the applicant; the place lives on the applicant. OpenRegister can filter records by rows that point at them (`query-related-schema-rows`), and can filter on which object a reference holds (`_relations.<field>=<id>`). It cannot filter on a field of the object a reference points to. This change adds that direction.

## Row this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | api-filter-related | Filter records by a field of a linked record. | partial |

Delivered change: `query-related-schema-rows` (8 of 9 tasks ticked, the remaining one a note), the reverse direction. The row's evidence: "Only reverse direction (rows pointing AT the record); no filter on a field of a forward-referenced record".

## What is there

- `_related[<schema>][<fk>][<field>]=<value>`: objects for which a row of another schema, pointing at the object through `<fk>`, matches (`lib/Service/Query/RelatedRowFilterParser.php`, `RelatedRowQueryApplier.php`, applied in `lib/Db/MagicMapper/MagicSearchHandler.php:548,563`). The parser refuses a malformed block instead of dropping it.
- `_relations.<field>=<id>`: objects whose reference `<field>` holds `<id>` (`MagicSearchHandler.php:677-678`, `applyRelationFieldFilters`).

## What changes

- The object query accepts `_ref[<property>][<field>]=<value>` and `_ref[<property>][<field>][<operator>]=<value>` with the operators the object's own filters take. It returns the objects whose reference property `<property>` points to at least one object that matches every condition in the block.
- The referenced schema is the one the property declares. A property that is not a reference, or a field the referenced schema does not have, is refused with HTTP 400 naming it.
- Conditions are evaluated only over referenced objects the caller may read, so a filter cannot be used to probe values the caller cannot see.
- One level deep. A chain (`applicant.address.city`) is refused with a message saying one level is supported.
- Facets over a referenced field with the same syntax, so an index page can offer the applicant's place as chips.

## Consumers

dossiq (cases by the applicant's municipality), pipelinq (leads by the organisation's sector), learniq (enrolments by the course's term), and every `CnIndexPage` filter over a relation.

## ADRs

- ADR-022: filtering is OpenRegister's query; apps do not post-filter.
- ADR-005: the referenced side honours RBAC and multitenancy.
- hydra ADR-058: one round trip, index-backed, no per-row lookups.

## Impact

- Extends `zoeken-filteren`.
- Affected code: a parser beside `RelatedRowFilterParser`, the query appliers for the Postgres and MariaDB builders in `lib/Db/MagicMapper/`, the facet builder, the Solr path (join where supported, database fallback otherwise).
- Backwards compatible: a query without `_ref` is unchanged.
- Size: M.

## Out of scope

- Chains of more than one reference.
- Sorting on a referenced field.
