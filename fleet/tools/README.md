# migrate-find

This script moves fleet calls of `ObjectService::find()` / `findSilent()` to the shape openregister PR #9116 introduced:

```php
// old
$this->objectService->find(id: $id, register: $r, schema: $s, _rbac: false, _render: false);
// new
$this->objectService->find(id: $id, options: new ObjectFindOptions(register: $r, schema: $s, render: false), _rbac: false);
```

The script is lane tooling and does not belong in any app repo.

## Use

Run it from the app's root, in your own clone of the app (never in a shared checkout):

```bash
python3 -I ~/fleet/tools/migrate-find.py lib tests           # dry run: prints CHANGE/SKIP file:line
python3 -I ~/fleet/tools/migrate-find.py --write lib tests   # rewrite in place
git diff --name-only | xargs -n1 php -l | grep -v '^No syntax'               # must print nothing
```

Then run phpcs on the changed files. A rewritten single-line call can go past 150 characters; wrap those by hand. On pipelinq this happened to 4 lines.

## What it does

- It touches only calls whose receiver names the object service: `$objectService`, `$this->objectService` or `$this->getObjectService()`. The match is case-insensitive on `objectService` / `objectOperations`. Mapper `find()` calls are never touched.
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
- Receivers named differently (`$this->objects`, `$service`, ...). The per-app counts in `../objectservice-find.txt` have the same blind spot, so treat them as a lower bound.

## Proven on

pipelinq, in a throwaway local clone on 2026-11-10: 107 calls changed in 83 files, 1 skipped (`SlaPolicyController.php:154` passes `uuid:` instead of `id:`, which already fails on today's signature). `php -l` was clean on every changed file. A second run gave 0 changed. The clone was deleted and nothing was pushed.
