# Design: access-follows-membership

Read at openregister development d9a2f9df6b.

## Context

- Authorization rules are lists per action of group ids and conditional
  rules `{ group | user, match }`. `match` maps an object property to a
  literal, a dynamic token (`$userId`, `$user.<prop>`, `$organisation`,
  `$now`) or an operator object (`$eq`, `$ne`, `$gt`, `$gte`, `$lt`, `$lte`,
  `$in`, `$nin`, `$contains`, `$exists`).
- The match is interpreted in three places, and all three call one token
  resolver, `ConditionMatcher::resolveDynamicValue()`:
  - the QueryBuilder list path, `MagicRbacHandler::applyRbacFilters()` →
    `buildPropertyCondition()` (used by `MagicSearchHandler::searchObjects()`
    for both the page and `_count`);
  - the raw-SQL list path, `MagicRbacHandler::buildRbacConditionsSql()` →
    `buildPropertyConditionSql()` (UNION searches, related-row access, the
    row predicate);
  - the PHP verdict, `ConditionMatcher::objectMatchesConditions()`, used by
    `PermissionHandler::hasGroupPermission()`, `MagicRbacHandler::hasPermission()`,
    deny rules and `PropertyRbacHandler`.
- `$in` with an empty list is the impossible predicate on both SQL paths and
  `false` in `OperatorEvaluator`.
- A precedent reads another schema to decide authorization:
  `DepartmentMatrixPersonValues` reads the caller's person object with
  `_rbac: false, _multitenancy: false`, caches per user and source for the
  request, and re-checks the user match in PHP (`collect()`).

## D-1: one operator, resolved where the tokens are resolved

`$memberOf` is an operator inside a property's operator object.
`ConditionMatcher::resolveDynamicValue()` replaces it with
`$in: <the caller's set>`. Nothing downstream learns a new word: both SQL
emitters and `OperatorEvaluator` already handle `$in`, including the empty
list. This is the ADR-011 shape: a new operator is added to the shared
resolver, not to each interpreter, so list and find cannot drift.

If the same operator object also carries `$in`, the two lists are
intersected. Other operators beside it (`$ne`, `$exists`) still apply, AND.

## D-2: a materialised set, not a correlated subquery

The set is read in PHP once and sent as an `IN (...)` list. A subquery
(`t.administration_id IN (SELECT m.administration_id FROM <membership table> m
WHERE m.user_id = ...)`) was considered and rejected:

- the PHP verdict needs the set anyway, so a subquery would be a second
  implementation of the same rule, which is the list-versus-find divergence
  ADR-011 exists to prevent;
- the membership table's name, its column names, its soft-delete and
  validity semantics would have to be re-expressed in SQL on PostgreSQL and
  MariaDB, inside a predicate that is also used in UNION searches across
  tables;
- the set is small in every known use: a bookkeeper belongs to a handful of
  administrations.

Both ways put the predicate in the `WHERE` clause, so `LIMIT`/`OFFSET` and
`COUNT(*)` are computed over the admitted rows only. The count is exact.

The read is capped at 500 membership rows per caller and operand. Past the
cap the set is truncated and a warning is logged. Truncation can only hide
rows, never admit them.

## D-3: the membership read bypasses the membership schema's own rules

The set is read with `_rbac: false, _multitenancy: false`. A bookkeeper who
may not list `AdministrationMembership` still has memberships, and the
authorization block that names the schema is written by the register author,
who already decides who reads what. Reading with the caller's rights would
make the rule depend on a second rule and, for a schema whose rules use
`$memberOf` on itself, recurse.

The user match is checked again in PHP on every row, as
`DepartmentMatrixPersonValues::collect()` does: a filter the search layer did
not understand comes back as no filter, and every member's administration
would become the caller's.

## D-4: the operand

| key | required | meaning |
|---|---|---|
| `schema` | yes | slug or id of the membership schema |
| `register` | no | slug or id of its register; default: the first register holding the schema |
| `property` | yes | membership field whose values form the set (scalar or array) |
| `match` | no, default `userId` | membership field holding the caller's Nextcloud uid |
| `where` | no | static filters on membership fields: a literal (strict equality) or an operator object, evaluated by `OperatorEvaluator` |
| `current` | no | `{ "from": <field>, "until": <field> }`: a row counts when `from` is empty or on or before today and `until` is empty or on or after today, compared on the `Y-m-d` date |

`match` and `property` reuse the names of the department matrix's
`userSource`, which reads the same kind of record.

## D-5: everything that goes wrong denies

| situation | set | outcome |
|---|---|---|
| no caller (anonymous) | `[]` | `$in: []`, no row |
| operand not an object, `schema` or `property` missing, `where` or `current` malformed | unresolvable | the property resolves to `null`, which every interpreter already treats as deny (`1 = 0` in SQL) |
| schema or register not found, read throws | `[]`, warning logged | no row |
| caller has no membership rows | `[]` | no row |

An admin is unaffected: admins bypass RBAC before any match is read.

## D-6: per-request cache

`MemberOfResolver` keeps `uid + canonical JSON of the operand → set` for the
life of the service, which Nextcloud's container gives one request. The key
carries the uid because `ObjectService::runAs()` can change the subject inside
a request. A long-running process (a background job) shares one instance
across its work, so a membership written during that run is seen on the next
run; that is the same staleness the department matrix accepts.

## D-7: interaction with multitenancy

A conditional rule whose match names a field other than `_organisation`
lifts the organisation filter for the callers it admits
(`MagicRbacHandler::hasConditionalRulesBypassingMultitenancy()`). A
`$memberOf` rule does the same: the membership is the tenancy. A schema that
wants both adds `"_organisation": "$organisation"` beside it in the same
match.

## Declarative-vs-imperative decision

Declarative. The rule lives in the schema's authorization block (ADR-031);
the only code is the engine's resolver, in OpenRegister (ADR-023 Rule 1).
shillinq's `AdministrationContextService` keeps its job of choosing the
active administration for the UI; it stops being the place that decides
which rows a bookkeeper may read.

## Risks

- A typo in the operand denies silently to the user. Mitigated by the
  warning in the log; a save-time validator for match operands is a separate
  change, since none exists for the other operators either.
- Slug ambiguity: a schema slug that exists in two registers. Name the
  `register`, or use ids.
- A very large membership set makes a long `IN` list. The cap bounds it.
