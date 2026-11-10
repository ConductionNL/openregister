# migrate-find

This script moves fleet calls of `ObjectService::find()` / `findSilent()` to the shape openregister PR #9116 introduced:

```php
// old
$this->objectService->find(id: $id, register: $r, schema: $s, _rbac: false, _render: false);
// new
$this->objectService->find(id: $id, options: new ObjectFindOptions(register: $r, schema: $s, render: false), _rbac: false);
```

The script is local tooling and does not belong in any repo.

## Use

Run it from the app's root, in your own clone of the app (never in a shared checkout):

```bash
python3 -I ~/memcap-work/sig/fleet/tools/migrate-find.py lib tests           # dry run: prints CHANGE/SKIP file:line
python3 -I ~/memcap-work/sig/fleet/tools/migrate-find.py --write lib tests   # rewrite in place
git diff --name-only | xargs -n1 php -l | grep -v '^No syntax'               # must print nothing
```

Then run phpcs on the changed files. A rewritten single-line call can go past 150 characters; wrap those by hand. On pipelinq this happened to 4 lines.

## What it does

- It touches only calls whose receiver is the object service (see *Receivers* below). Mapper `find()` calls are never touched.
- It never touches a call inside a PHP string (single, double, heredoc, nowdoc), a comment or inline HTML. learniq's `FindAllConfigScopeTest` keeps PHP source in a nowdoc; those calls stay as they are.
- `register`, `schema`, `_extend`, `files`, `_render` and `_audit` move into `options: new ObjectFindOptions(...)` as `register`, `schema`, `extend`, `files`, `render` and `audit`. Values equal to the default (`[]`, `false`, `true`, `true`) are dropped.
- Positional arguments are mapped by the old parameter order and written out as named arguments.
- `_rbac` and `_multitenancy` stay as named arguments.
- It adds `use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;`, but only when the file now builds one.
- It is idempotent. Calls that already pass `options:`, or that need no change (`find($id)`, `find($id, _rbac: false)`), print nothing. A second run reports `0 changed`.

## What it skips (printed as `SKIP file:line reason`)

- a comment inside the argument list
- spread arguments (`...$args`)
- a positional argument after a named one, or more than the 9 old positions
- an unknown parameter name, for example `find(uuid: ...)`, which already breaks on today's signature
- a call without an `id` argument
- a file where another class is in scope as `ObjectFindOptions`

Fix skipped calls by hand.

## Not covered

- Test doubles: `->with(...)`, callbacks typed with the old parameter list, `willReturnMap` rows. openregister's `tests/Unit/Support/FindCallShape.php` in #9116 shows how to adapt them.
- Local variables and parameters with other names (`$service`, `$objects`). The per-app counts in `../objectservice-find.txt` miss typed receivers too, so treat them as a lower bound.

## Receivers (both scripts)

A call is a candidate when its receiver is one of:

- a name containing `objectService` / `objectOperations` (case-insensitive): `$objectService`, `$this->objectService`, `$this->getObjectService()`;
- `getOpenRegisters()` (on any object: integriq's `$this->objectService->getOpenRegisters()`), `$openRegister`, `$openRegisters`;
- `$this->objects`, unless the file declares `$objects` with another native type (`private ObjectMapper $objects`);
- `$this-><prop>` where the same file types `<prop>` as openregister's `ObjectService` or `ObjectServiceInterface`: a property declaration, a promoted constructor parameter, or a plain constructor parameter assigned with `$this-><prop> = $param;`;
- `$this-><method>()` where the same file declares `<method>()` with that return type (shillinq's `$this->objects()`).

A `$this-><prop>` receiver whose property the file declares with a class from outside openregister (integriq's `SourceMappingService $objectService`, a test's `MigrationSimulatingObjectService $objectService`) is never rewritten, whatever its name; it prints `SKIP ... receiver is typed X, not openregister`. PHPUnit mock types do not count as outside.

The type is resolved through the file's `use` lines and namespace, so an alias (`use ...\ObjectServiceInterface as Objects;`) counts and an app's own class named `ObjectService` does not. Local variables other than `$objectService` and chained calls (`$this->objects->setRegister(...)->find(...)`) are not candidates.

## Positional arguments that may follow another order (`WARN`)

Both scripts map positional arguments by the old openregister parameter order. Some callers followed an app stub with another order: integriq's `CompositeFanoutRule.php:127/:184` pass `saveObject($body, $register, $schema)`, which would put register and schema into `extend`. A call is therefore left alone and printed as `WARN file:line ...` when

- a positional argument lands in `extend` (`_extend` for find) with any value but `[]`, or
- a positional value that looks like a register or schema (`$register`, `$schemaId`, `$this->registerSlug`, `self::REGISTER`) lands in another parameter.

Named arguments are never warned about, whatever their order: `saveObject(register: $r, schema: $s, object: $o)` is mapped by name. Read each WARN call against the stub it was written for and fix it by hand.

## Proven on

pipelinq, in a throwaway local clone on 2026-11-10: 107 calls changed in 83 files, 1 skipped (`SlaPolicyController.php:154` passes `uuid:` instead of `id:`, which already fails on today's signature). `php -l` was clean on every changed file. A second run gave 0 changed. The clone was deleted and nothing was pushed.

# migrate-save

This script moves fleet calls of three `ObjectService` write methods to their value-object shapes:

| method | PR | new shape |
|---|---|---|
| `saveObject()` | openregister #9123 | `saveObject($object, options: new ObjectSaveOptions(...), _rbac:, _multitenancy:)` |
| `deleteObject()` | openregister #9099 | `deleteObject(deletion: new ObjectDeletion(...), _rbac:, _multitenancy:, _retentionSweep:)` |
| `saveObjects()` | openregister #9108 | `saveObjects($objects, options: new BulkSaveOptions(...), _rbac:, _multitenancy:)` |

```php
// old
$this->objectService->saveObject(object: $o, extend: [], register: $r, schema: $s, uuid: $id, currentUser: $u, _rbac: false);
// new
$this->objectService->saveObject(
    object: $o,
    options: new ObjectSaveOptions(
        target: new ObjectFindTarget(register: $r, schema: $s),
        uuid: $id,
        actor: new SaveActor(currentUser: $u)
    ),
    _rbac: false
);
```

Like migrate-find, it is local tooling and does not belong in any repo.

## Use

Run it from the app's root, in your own clone of the app (never in a shared checkout):

```bash
python3 -I ~/memcap-work/sig/fleet/tools/migrate-save.py lib tests           # dry run: prints CHANGE/SKIP file:line method
python3 -I ~/memcap-work/sig/fleet/tools/migrate-save.py --write lib tests   # rewrite in place
git diff --name-only | xargs -n1 php -l | grep -v '^No syntax'               # must print nothing
```

`--only=saveObject,deleteObject,saveObjects` limits the run to the methods whose PR has landed. Run it once per PR, after that PR merges into openregister, so an app never calls a shape openregister does not have yet. Then run phpcs on the changed files.

## What it does

- Same receiver filter as migrate-find (see *Receivers*). App services with their own `saveObject()` are never touched.
- Same as migrate-find, it never touches a call inside a string, a comment or inline HTML.
- `saveObject`: `register`/`schema` go into `target: new ObjectFindTarget(...)`; `uuid`, `extend` and `uploadedFiles` go into `ObjectSaveOptions`; `silent`, `_validation`, `failIfExists` and `_dedupOverride` into `policy: new SavePolicy(...)` (as `silent`, `validation`, `failIfExists`, `dedupOverride`); `currentUser`, `_owner` and `_unowned` into `actor: new SaveActor(...)` (as `currentUser`, `owner`, `unowned`).
- `deleteObject`: `uuid`, `register`/`schema` (as `target`), `currentUser` and `permanent` go into `deletion: new ObjectDeletion(...)`.
- `saveObjects`: `register`/`schema` go into `defaults: new BulkBatchDefaults(...)`; `validation`, `events` and `_audit` (as `audit`) into `BulkSaveOptions`. `deduplicateIds` and `enrich` are dropped: openregister never read them, and #9108 removed them.
- Values equal to the old default (`[]`, `null`, `false`, and `true` for `_validation` / `_audit`) are dropped, so an empty value object is never written.
- Positional arguments are mapped by the old parameter order and written out as named arguments. The first argument (`$object`, `$objects`) keeps its own form.
- `_rbac`, `_multitenancy` and `_retentionSweep` stay as named arguments, so every guard bypass stays visible at the call site.
- A multi-line call stays multi-line; a one-line call stays on one line unless it would pass 150 characters (tabs counted as 4), then it is wrapped. A nested value object that would pass 150 characters is wrapped too.
- It adds a `use OCA\OpenRegister\Service\Object\<Class>;` for each class the file now builds, and no other.
- It is idempotent. Calls that already pass `options:` / `deletion:`, pass a `new ObjectSaveOptions(...)` positionally, or need no change (`saveObject($o)`, `saveObject(object: $o, _rbac: false)`) print nothing. A second run reports `0 changed`.

## What it skips (printed as `SKIP file:line method reason`)

- a comment inside the argument list
- spread arguments (`...$args`)
- a positional argument after a named one, or past the old positions
- an unknown parameter name (`_extend:` on saveObject, for example), which already breaks on today's signature
- a call without its first argument (`object`, `objects` or `uuid`)
- a positional second (save) or first (delete) argument named like `$options` / `$deletion`, which may already be the new shape
- a `deduplicateIds` / `enrich` value with a call in it, since dropping it would drop a side effect
- a migrated call nested inside another migrated call
- a file that imports or declares a non-openregister class named like an object service (`InMemoryObjectService`, ...): the receiver may be that fake
- a file where another class is in scope under one of the new short names

Fix skipped calls by hand.

## Not covered

- Test doubles and stubs that declare the old signatures, for example pipelinq's `tests/Stubs/Service/ObjectService.php` and `tests/Stubs/Contract/ObjectServiceInterface.php`, shillinq's `InMemoryObjectService`. Update them by hand in the same commit, or the migrated calls hit `Unknown named parameter $options`.
- `->with(...)` expectations, callbacks typed with the old parameter list, `willReturnMap` rows. The openregister PRs show how to unpack the value object in a callback.
- Local variables and parameters with other names (`$service`, `$objects`).

## Proven on

pipelinq (`e4d420d42`) and shillinq (`f1e580b05`), in throwaway local clones with the origin removed, on 2026-11-10:

| app | saveObject | deleteObject | saveObjects | files | skipped |
|---|---|---|---|---|---|
| pipelinq | 140 changed | 16 changed | 0 | 113 | 0 |
| shillinq | 103 changed | 3 changed | 0 | 71 | 7: 6 comment inside the arguments (`lib/Repair/InitializeSettings.php`), 1 spread (`lib/Service/RuleTestDataSeedRun.php:604`) |

`php -l` was clean on every changed file, no added line passed 150 characters, and a second run gave 0 changed. Neither app calls `saveObjects()` through an object-service receiver, so that path was proven on a synthetic file only. The clones were deleted and nothing was pushed.

## Re-proven with string skipping, typed receivers and WARN

On 2026-11-10, in throwaway local clones (nothing pushed, clones deleted), both scripts on `lib tests`; before = the scripts as first published:

| app | find before → after | saveObject before → after | deleteObject before → after | skipped / warned after |
|---|---|---|---|---|
| pipelinq (`e4d420d42`) | 107 → 110 | 140 → 141 | 16 → 16 | 1 skip (find `uuid:`, `SlaPolicyController.php:154`) |
| learniq (`e9dd0d0f`) | 110 → 124 | 142 → 148 | 11 → 11 | 1 skip (comment inside the arguments, `GradeRollupHandler.php:443`) |
| integriq (`cd844692c`) | 158 → 156 | 211 → 214 | 10 → 11 | find 7 skips (6 on a fake typed `MigrationSimulatingObjectService`); saveObject 17 skips, 2 WARN (`CompositeFanoutRule.php:127/:184`) |

The added calls are on `$this->objects`, a typed property, `getOpenRegisters()` or `$openRegister(s)`. On learniq 2 saveObject calls inside a nowdoc in `tests/Unit/FindAllConfigScopeTest.php` are no longer rewritten; on integriq 6 find calls on a test fake and the 2 WARN calls are no longer rewritten. `php -l` was clean on every changed file (190 on learniq, 145 on pipelinq, 132 on integriq) and a second run gave 0 changed. migrate-find still writes one-line calls past 150 characters (42 on learniq, 4 on pipelinq); wrap those by hand.
