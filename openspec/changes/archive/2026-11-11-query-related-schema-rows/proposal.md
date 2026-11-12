# Query: filter on rows of a related schema

## Why

Round 2 of the dossiq competitor analysis (row B15 in
`concurrentie-analyse/procest/_round2/compare/tier-b-and-sibling.md`, decision
D10): every competitor searches a case on the properties its case type
defines. Zaaksysteem filters on custom attributes per Zaaktype beside 55
system filters (`xxllnc-zaken/round2/search-anatomy.md`); GZAC declares
search fields on schema paths per case definition
(`valtimo/round2/pages/CaseDefinition-Dossierlijst.md`); OpenCase searches
fixed fields (`opencase/round2/pages/Search-Cases.md`).

dossiq stores a case's typed properties as rows of schema `caseProperty`
(case, propertyDefinition, value), because the property set differs per case
type. Its Cases filters reach the `case` fields only: a `caseProperty` row is
not filterable from the case list (M1 9.2). OpenRegister's query filters one
schema at a time; a filter over a related schema's rows is a query feature,
and the same shape serves every app that models typed attributes as rows.

## What changes

- The object query accepts a related-row filter: `_related[<schema>][<fk>]`
  with one or more field conditions, returning the objects for which at least
  one row of `<schema>` whose `<fk>` references the object matches every
  condition. Existing operators apply to the row's fields.
- The filter composes with the object's own filters and with the favourites
  and recent lenses, and honours RBAC on both schemas.
- Facets may be requested over a related schema's field with the same syntax,
  so an index page can offer chips from `propertyDefinition` values.
- The SQL is an `EXISTS` subquery on the related schema's table, so it is one
  round trip and index-backed on the foreign key.

## Who benefits

dossiq (Cases advanced search), zaakafhandelapp (zaakeigenschappen),
humaniq (custom employee fields), stackiq (component attributes),
opencatalogi.

## Impact

- Affected specs: zoeken-filteren (delta).
- Affected code: the query filter parser, the Postgres and MariaDB query
  builders, the facet builder, the Solr backend (translated as a join query
  where the backend supports it, database fallback where not).
- Backwards compatible: a query without `_related` is unchanged.
