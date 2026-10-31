# Proposal: array-element-filters

## Why

dossiq's case list filters on a case type's fields (CnRelatedFieldFilters). It reads them from `caseProperty` rows
through `_related[caseProperty][case][…]`, but a case keeps its answers in its own `properties[]` array
(`{ propertyDefinition, name, value }`). On :8080, 14 cases carry `properties[]` and there are 0 `caseProperty` rows,
so filtering on a case-type field matches nothing for any case type (DQ-REINT, 23 Oct). No filter could ask "one element
of this array carries these values".

## What changes

A query may carry `_element[<array property>][<field>]=<value>`: the object matches when ONE element of the array
carries every field of the block. Values are those of a nested-path filter (a value, a list, or an operator bag:
`gte`, `lte`, `gt`, `lt`, `in`, `notIn`, `ne`, `isnull`, `like`). Several blocks on one property are numbered
(`_element[properties][0][…]`, `_element[properties][1][…]`), each its own element. The SQL is one EXISTS per block over
the array's elements (`json_array_elements` on PostgreSQL, `JSON_TABLE` on MariaDB/MySQL).

A block on a property that is not an array, or that the caller may not read, matches nothing and is listed as an
ignored filter; an encrypted property refuses. The cross-schema UNION path refuses `_element` (as it refuses
`_related`), and a query carrying it takes the sequential path.

## Scope

MagicSearchHandler (QueryBuilder path), a new ArrayElementFilter, MagicMapper's UNION choice. No schema or API shape
change beyond the new query key.
