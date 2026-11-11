# Design: an app declares object access rather than guarding it

## D-1 · The fork: a new primitive, or a contract over the ones that exist?

**Decided: a contract.** No new matching primitive.

The measurement that settles it. dossiq's `CaseAccessGuard` asks whether
`case.assignee` equals the caller, and `CaseAccessPolicy` also asks whether the
caller is in `case.assignees`. Both are expressible today:

```json
{
  "read": [
    { "group": "authenticated", "match": { "assignee": "$userId" } },
    { "group": "authenticated", "match": { "assignees": { "$contains": "$userId" } } }
  ],
  "update": [
    { "group": "authenticated", "match": { "assignee": "$userId" } }
  ]
}
```

`ConditionMatcher::resolveDynamicValue()` resolves `$userId` on the PHP layer.
`MagicRbacHandler::buildContainsOperatorConditionSql()` emits `$contains` on
the SQL layer, and its docblock already records why both sides must agree: a
share honoured on `find` and dropped on a list is a share that half exists.

So the abstraction Ruben asked for is not a thing to build. It is a thing to
declare, to enforce as the only permitted way, and to migrate three apps onto.
Building a second matching engine here would produce the duplicate the apps
already have, one layer down.

## D-2 · What an undecidable decision means

`CaseAccessPolicy` returns true when OpenRegister is absent, when the schema is
unconfigured, and when the read throws. `CaseAccessGuard` returns false for the
same three. One app, one rule, two postures, and the docblock of the second
explains at length why it refuses to call the first.

That is not an app bug. Neither author had a platform answer to point at, so
each picked one, and the sharing surface picked the one that keeps the demo
working.

**Decided:** a schema declares `authorization.onUndecidable` as `closed` or
`open`. Absent means `closed`. An undecidable decision is logged with the
question that could not be answered, because the failure both apps were coding
around is invisible, not loud.

`open` stays available on purpose. There are registers where a read that cannot
be decided should still be served, and forcing them closed by fiat would push
the same fail-open logic back into an app where nothing can see it.

## D-3 · The issuer of a grant keeps their access

`CaseAccessPolicy::hasCreatedShareForCase()` reads a share row's `createdBy` and
treats it as standing evidence the user had access when they minted it.

The instinct is right and the mechanism is wrong. It infers a grant from a row
that exists for another purpose, so revoking the share does not revoke the
inference, and any schema that happens to carry a `createdBy` becomes an
access rule nobody declared.

**Decided:** `ObjectGrantResolver` records the issuer on the grant, and an
issuer holds the access they issued from, until the grant is revoked. Revoking
the grant revokes the issuer's derived access with it. The rule is then one
declared edge instead of an inference off a foreign column.

## D-4 · `TokenGrantValidator`

It exists, it is complete, it is tested, and no code in `lib/` calls it. That
is the orphan-auth shape: a validator nothing calls is indistinguishable from
no validation, and it reads as coverage to the next person.

**Decided:** wire it into the grant issue path, or delete it. This change picks
wiring, because its four refusals (no verbs, `manage`, a verb the issuer does
not hold, no end date) are each a real hole in grant issuance. Task 4.1 carries
it.

## D-5 · What dossiq deletes, and what it keeps

**Deletes:** `lib/Service/Sharing/CaseAccessPolicy.php` and
`lib/Service/CaseAccessGuard.php`, with their call sites replaced by the
ordinary OpenRegister read. A read that returns nothing is already a refusal, so
most call sites lose a branch rather than gain one.

**Keeps:** the property names. `assignee`, `assignees` and the case register and
schema ids are dossiq's configuration, written into the case schema's
`authorization` block by dossiq's installer. The admin bypass is
OpenRegister's `admin` principal and stops being dossiq code.

**Keeps, for now:** `InformatieobjectAccessGuard`, until
`classification-clearance-on-an-object` lands. Deleting it first would drop the
clearance check entirely, and a guard removed before its replacement exists is a
regression wearing a refactor's name.

## D-6 · Why this is not one change with the clearance lattice

The two are separable by what they need. This change needs no new matching
primitive and no migration: it declares a contract over enforcement that already
runs. The clearance lattice needs an ordered vocabulary, an ordinal comparison
on both layers, a principal-side clearance resolver and a write-side ceiling.
Bundling them would make the contract wait on the lattice, and the contract is
what stops the next app writing a fourth guard.
