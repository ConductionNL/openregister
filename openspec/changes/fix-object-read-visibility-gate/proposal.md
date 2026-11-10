# Object read visibility — corrected diagnosis

> **This proposal replaces its own first version (#2251).** That version claimed
> the read path gated on `create` and that OpenRegister could not express "any
> authenticated user". Phase 0 disproved both. The original reasoning is
> preserved in `design.md` alongside the evidence that overturned it, because
> how the wrong conclusion was reached is the more useful artefact.

## What Phase 0 established

### The list gate is correct

Object listing is filtered in SQL by
`MagicRbacHandler::buildRbacConditionsSql(schema, action: 'read')`
(`lib/Db/MagicMapper/MagicRbacHandler.php:988`, called from
`MagicSearchHandler.php:579`). It asks for `read`. There is no `create` gate on
the live read path.

### The `create` gate is real but dead

`PermissionHandler::filterObjectsForPermissions()`
(`lib/Service/Object/PermissionHandler.php:896`) does gate visibility on
`action: 'create'` — and has **no production caller**. Repo-wide, the only
reference is `tests/Service/ObjectHandlersIntegrationTest.php:1436`, which
invokes it with `_rbac: false` and therefore never reaches the check.

Its sibling `filterUuidsForPermissions()` gates on `action: 'delete'`, which
looked like the same mistake and is not: its only caller is
`ObjectService::deleteObjects()` (`ObjectService.php:3693`). `delete` is the
correct action there. That concern is withdrawn.

### The `authenticated` pseudo-group already exists

`authenticated` is implemented in all three handlers
(`MagicRbacHandler.php:413` and `:495`, `PermissionHandler.php:594`,
`PropertyRbacHandler.php:786`) and is already specified in
`openspec/specs/rbac-scopes/spec.md:178` and `:205`. The claimed gap does not
exist; no new sentinel is needed.

## The actual root cause of the openbuild blackout

A **non-empty** authorization block that omits `read` deliberately fails closed.
`buildRbacConditionsSql` returns `bypass => true` only for an *empty* block
(`:1024`); a populated block with no `read` key produces `$rules = []` (`:1029`)
and falls through to the owner condition alone (`:1041`), so a non-owner matches
nothing and sees zero rows. The behaviour is intentional and commented as such
at `:1031`.

Every schema in `openbuild/lib/Settings/openbuild_register.json` carries

```json
"authorization": { "create": ["admin"], "update": ["admin"], "delete": ["admin"] }
```

— non-empty, no `read`. All six (Application, ApplicationVersion,
ApplicationTemplate, BuiltAppRoute, HelloMessage, exportJob) are therefore
owner-only for every non-admin caller. That is the whole of
Conduction/openbuild#76.

**The fix is one key in openbuild, not a change to OpenRegister.** Adding
`"read": ["authenticated"]` uses a facility OpenRegister already ships.

This also explains the anomaly the first proposal could not: adding
`read: ['rbac-editors']` flipped `rbac-editor` from 0 to 21 because it supplied
the missing `read` rule to the SQL gate. Nothing consulted the `create` gate; it
was never running.

## What still changes in OpenRegister

Only defects that survived verification, all minor:

1. **Dead code with a misleading action.** `filterObjectsForPermissions()` reads
   as the object-level read filter and is not wired to anything. Left as is, the
   next reader repeats this investigation — as this proposal did.
2. **Documentation that points at it.** `docs/features/organisation-roles.md:673`
   names `filterObjectsForPermissions()` as the List enforcement point, and
   `:670` names `hasPermission()` as the Read result-set filter. Neither is what
   runs; `MagicRbacHandler` is.
3. **An inconsistency to investigate, not yet a confirmed bug.**
   `PermissionHandler::resolveReadGroupIds()` (`:1608`) treats a missing `read`
   key as broadcast, where the list path treats it as owner-only. It feeds
   `getReadableByUsers()` (notification fan-out), so the two paths disagree about
   what a schema without a `read` key means. Whether that produces a user-visible
   wrong outcome has not been established, and must not be "fixed" on the
   strength of the asymmetry alone.

## Impact

- Conduction/openbuild#76 is unblocked by an openbuild-side change.
- No behavioural change to OpenRegister. Items 1–2 are cleanup; item 3 is an
  investigation.
- Blast radius is correspondingly small — the earlier framing, that a wrong read
  gate was silently mis-filtering every app on every register, was incorrect.
