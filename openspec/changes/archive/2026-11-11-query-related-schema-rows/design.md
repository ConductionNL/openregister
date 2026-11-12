# Design: related-schema row filter

## D-1: syntax

`?_related[caseProperty][case][propertyDefinition]=<uuid>&_related[caseProperty][case][value][gte]=100`
reads: objects for which a `caseProperty` row exists whose `case` references
the object, whose `propertyDefinition` is the uuid and whose `value` is at
least 100. Conditions inside one `_related` block apply to the same row.
Two blocks on the same schema are two `EXISTS` clauses, so "has property A
and has property B" is expressible.

## D-2: SQL

Each block becomes `EXISTS (SELECT 1 FROM objects r WHERE r.schema = ? AND
r.object->>'case' = o.uuid AND <conditions>)`, with the JSON path expressions
the field filter already produces. The foreign-key expression index that
referential-integrity maintains serves the correlation. MariaDB uses the same
shape with `JSON_EXTRACT`.

## D-3: RBAC on both sides

The related schema's read predicate is applied inside the subquery, so a
user who may not read `caseProperty` rows cannot infer their values by
filtering. This is the same predicate injection the main query uses.

## D-4: facets

`_facets[_related][caseProperty][case][value]` returns the value
distribution across matching rows, bounded by the facet limit. An index page
renders chips per `propertyDefinition` from it.

## D-5: search backend

With a Solr backend the block translates to a `{!join}` query when the
related schema is mirrored; otherwise the query falls back to the database
path for that request and says so in the response.

## D-6: kind

Code, in OpenRegister. Consuming apps use the syntax in config.
