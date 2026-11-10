# Design: query-filter-through-a-reference

No screen is part of this change; it is a query feature. OpenRegister is not one of the canvas apps.

## D-1: wire format

`_ref[<property>][<field>]=<value>`, with operators as a third level: `_ref[aanvrager][woonplaats]=Zuiddrecht`, `_ref[cursus][startdatum][gte]=2026-09-01`. One block per reference property; several fields in one block must hold on the same referenced object. Repeating a block with a numeric suffix (`_ref[aanvrager:2]`) asks for a second, independent referenced object, mirroring `_related`.

A different prefix from `_related` keeps the two directions apart at the first glance: `_related` names a schema (rows pointing here), `_ref` names one of this schema's properties (where this points).

## D-2: refuse, never drop

As `RelatedRowFilterParser` does: an unknown property, a property that is not a reference, a field the referenced schema lacks, an unknown operator and a chain each give HTTP 400 naming the part. A dropped filter answers the unfiltered set.

## D-3: SQL

An `EXISTS` subquery on the referenced schema's magic table, joined on its uuid against the reference column. A reference property holding a list joins through the list (JSON containment on Postgres, `JSON_CONTAINS` on MariaDB); a property holding one uuid joins by equality. The referenced schema's own RBAC and multitenancy conditions are added inside the subquery, the same conditions a read of that schema adds.

## D-4: the referenced schema

Taken from the property's `$ref` (or `objectConfiguration.schema`), resolved through the same lookup the render path uses for `_extend`. A property whose referenced schema is in another register works the same way.
