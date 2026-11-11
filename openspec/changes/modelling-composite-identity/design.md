# Design: modelling-composite-identity

Read at openregister development 0ca409ee04.

## D-1: identity is a flag on an existing uniqueness constraint

`UniqueConstraintEvaluator::constraints()` (`lib/Service/Schemas/UniqueConstraintEvaluator.php`)
already reads `configuration.uniqueConstraints` as `{name, properties, action}` and
drops a malformed entry rather than guessing. The identity flag rides on that entry:
`{"name": "zaaksleutel", "properties": ["gemeentecode", "zaaknummer"], "action": "refuse", "identity": true}`.

Only a `refuse` constraint may be the identity, because a `report` constraint lets a
duplicate through and a key that can match two records is not a key. A second
identity constraint on one schema, or `identity` on a `report` constraint, is refused
at schema save with a 400 naming the constraint.

Uniqueness itself needs no new code: `UniqueConstraintListener` refuses the duplicate
on create and update already.

## D-2: a resolver, not a second read path

`ObjectKeyResolver::resolve(Register, Schema, array $values): array` builds equality
filters on the identity properties and calls `ObjectService::findAll()` with
`limit: 2`, the same way `MatchResolver::resolve()` does (`lib/Service/Import/MatchResolver.php:116-140`).
The controller then hands the single uuid to the existing `show`, `update`, `patch`
or `destroy` method. RBAC, multitenancy, read logging and rendering stay in the one
path they already live in.

A row that carries no value for one of the identity properties matches nothing, as
in `MatchResolver`. The controller turns that into a 400 before the lookup.

## D-3: routes before the generic `{id}` routes

`appinfo/routes.php:1175-1176` already notes that a longer path must be declared
before `objects#show` because `{id}` matches `[^/]+`. The four `by-key` routes go in
that block. `by-key` cannot collide with a uuid or a slug in practice, but the
declaration order makes it impossible.

## D-4: identity values do not drift

The save path compares the identity properties of the stored object with the
incoming write. A change is refused with 422 naming the property. The schema
migration planner (`lib/Service/Schema/SchemaMigrationPlanner.php`) stays the one
audited way to rewrite values in bulk.

## D-5: an index backs the lookup

On a magic table, `MagicMapper::createTableIndexes()` (`lib/Db/MagicMapper.php:3402`)
creates a composite index over the identity columns, next to the facetable and
relation indexes it already creates (:3552, :3580-3584). The sync path
`MagicTableHandler::updateTableIndexes()` (`lib/Db/MagicMapper/MagicTableHandler.php:473`)
adds it to an existing table.

## Declarative-vs-imperative decision

Declarative: the identity is a flag in the schema's configuration, read by the
evaluator that already reads uniqueness. No per-app code.

## Risks

- Enumeration: a by-key lookup must not reveal that a record exists when the caller
  may not read it. The resolver runs under the caller's RBAC, so an unreadable record
  is simply not found (404).
- Legacy duplicates: a schema that gains an identity over data that already breaks
  it gets 409 on the affected keys. The schema save warns with the count of breaches,
  using the evaluator's report mode, before the flag takes effect.
