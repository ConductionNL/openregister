# Brief: openregister modularity work packages (cloud)

You are one cloud agent working ONE work package of the openregister modularity refactor. Read this whole brief and your package file (`packages/<ID>.md` on this branch) before you change anything. You open PRs; the coordinator merges. When your PRs are open you stop.

## The goal

The backend (`lib/`) is measured in ten functional areas, defined as file patterns in `sigrid.components.yaml` (generated from `scripts/component-map.csv` by `scripts/component-patterns.py`). The areas have one order, bottom to top (`tools/sig/area-order.json`):

Kernel, Core, Notifications, Search, Compliance, Files, Integrations, Processes, Configuration, Platform.

An area uses only areas below it. Read first on `origin/main`:
- `openspec/changes/functional-areas-depend-in-one-direction/` (proposal, design, spec, tasks)
- hydra ADR-119: https://github.com/ConductionNL/hydra/blob/development/openspec/architecture/adr-119-boundaries-inside-an-app.md (all 15 decisions)
- `docs/sig/component-analysis.md`, section "Wat dit betekent voor de beoordeling"

## Allowed ways to remove a reference against the order

1. **An event.** The lower area dispatches (an existing or new event, in Kernel or the lower area), the higher area listens. Synchronous at the same point, same failure behaviour.
2. **A port owned by the lower area.** An interface in `lib/Kernel/Contracts/<LowerArea>/` (it counts to the lower area); the higher area implements it and binds it in its own registrar. One port per capability, not one per class.
3. **The class counts to the area whose work it does**, by its csv row, without moving the file. Only when that is genuinely its work.
4. **An id or a Kernel value object** instead of an entity of a higher area.
5. **Wiring:** each area binds its own classes in its own registrar; a registrar binds only its own area's classes. Keep registrars thin, one hidden map per area per Nextcloud directory (`lib/Db`, `lib/Service`).

**Not allowed** (ADR-119 Decision 13): a csv row change only to hide a reference; moving a class into Kernel only to hide it; merging areas; an interface that copies a class method for method; `class_alias`; moving files out of their Nextcloud directory; renaming a class or event other apps use; a static service registry. Nothing internal goes into `lib/Contract/` (that is the published contract, gate 67; ADR-119 Decision 14).

## Decisions already taken (do not reopen)

- Entity methods that construct Core classes (`Schema::validateAuthorization()` and similar): deprecate them, move every openregister caller to a Core service, keep the methods working.
- `ObjectsController` endpoints that are another area's work may move to a controller of that area, keeping URL and HTTP verb in `appinfo/routes.php` (the route name may change; nothing reads `objects#export`).
- Files: `lib/Service/File/*` and `FileService` are file-storage work; first a Core-owned port for what Core needs, then rows may follow.
- Events other apps read keep their class name and namespace; change their payload only to a Kernel contract the old object implements, and only after checking every fleet app (GitHub code search for the class name, and the apps listed in `tools/sig/fleet_checkout.sh`).
- `ActionRightsController` stays in Core.
- `Schema::setConfiguration()` validation moves to the Core write path, after a golden test pins today's behaviour; a setter cannot be deprecated.
- `Controller/Support/*` bags whose only users are the controllers of one higher area count to that area (csv row), as done for Processes, Integrations, Compliance and Configuration.
- Core `Db`/`Event` classes that name a Configuration, Application or Source entity use the Kernel contract (`ConfigurationInterface`, `ApplicationInterface`, `SourceInterface`, see #10689) instead.
- Core services that call Search get a Core-owned port per capability, like the presence and action-rights ports (#10675, #10688).
- Schema declaration checks are a positioned collection (#10685): Compliance and Integrations contribute their checks through `SchemaDeclarationChecksEvent`, in their existing slot, never reordering.
- Job handles: each area registers its own job handles (collection event or Core port), no area lists another area's jobs.

## Behaviour stays identical

Access checks keep their order and position. Public signatures towards other apps only widen. Concrete classes keep their names and directories. Every changed or added class has unit tests. Tests whose fake container is keyed by a concrete class are retargeted to the contract.

## Traps

- Write each interface signature on one line and import only what is used (the duplication gate reads 6-line clones).
- Internal contracts in `lib/Kernel/Contracts/...`, never `lib/Contract/`.
- Helpers take at most two parameters; shared state on a value object (ADR-011, ADR-120).
- Name classes after what they do, never after a procedure or law.
- A new PHP file needs its csv row (area = whose work it is), then `python3 scripts/component-patterns.py && python3 tools/sig/deptrac_areas.py`. A contract under `lib/Kernel/Contracts/<Area>/` is assigned to `<Area>` automatically.
- Do NOT use a git worktree with a symlinked `vendor/`: Composer then loads `lib/` of the other checkout. Work in your one checkout.
- A green PHPUnit run exits 1 without a coverage driver: use `--no-coverage` and read the `Tests:` / `Failures:` lines.
- phpstan that did not finish can print "0 errors": check its exit code.
- Adding or removing a listener changes two pins: `listener-order.json` and the count and hash in `ListenerRegistrationOrderGoldenTest`. Never reorder the listener list.
- Hand-built `SchemaMapper` tests need `ProcessDeclarationChecksDispatcher`, or the contributed checks do not run.
- A merge conflict in `scripts/component-map.csv`: keep both sides' rows, then regenerate `sigrid.components.yaml` and `tools/sig/deptrac/areas.yaml`. Never `git reset --soft` after a fetch moved main.

## How you work

1. `git fetch origin main && git switch -c cloud/mod-<ID>-<topic> origin/main`.
2. `composer install --no-interaction --prefer-source` in the FOREGROUND (timeout 600000 ms); `composer --working-dir=vendor-bin/deptrac install --no-interaction --prefer-source`. Nobody will wake you: never end your turn while waiting; no background jobs or monitors.
3. Do the package. At most about 15 files per PR, one kind of change per PR; up to 4 PRs per package, each from fresh `origin/main` (or stacked on your previous PR, say so in the body).
4. Verify per PR, in this order:
   - `php -l` on changed files; `vendor/bin/phpcs`, `vendor/bin/phpstan analyse --no-progress` and `vendor/bin/psalm --no-cache` on changed lib files: no NEW findings.
   - `python3 tools/sig/modularity_check.py --check`: NO new reference against the order. Gone entries are good. A newly exposed file or a new Kernel file is allowed only when it is the deliberate contract of your change: list it in the PR body with the reason.
   - `tools/sig/deptrac.sh areas --check`: no new violating class pair.
   - `vendor/bin/phpunit -c phpunit.xml --no-coverage tests/Unit/Architecture` (all of it), the tests of every touched class and every test that names one.
   - Once per package, on your last PR: all of `tests/Unit`, per directory if needed. Known failures on main that are not yours: DbalConnectionFactoryBehaviourTest (5 or 6), MappingSlugFallbacksTest::testAMissingTransliteratorKeepsTheName, ExampleSetTalkStoreBehaviourTest::testTalkIsUnavailableWhenItsServicesDoNotLoad.
   - Do NOT commit `tools/sig/modularity-baseline.json` or `tools/sig/deptrac/areas.baseline.json`; the coordinator records them at merge.
5. Commit: Conventional Commits (`refactor(architecture): ...`), single trailer `Assisted-by: ClaudeCode:claude-opus-5-5`. No Co-Authored-By, no Signed-off-by. Never rebase a pushed branch, never force-push, never skip hooks.
6. Open the PR against `main` via REST (`gh pr` may be blocked): `gh api repos/ConductionNL/openregister/pulls -f title=... -f head=<branch> -f base=main -f body=...`. Title and body must not contain the words "SIG", "sig", "Sigrid". Body: package id, what moved and why it is the right dependency, references against the order before/after (from `modularity_check.py`), newly exposed files if any, exactly what ran and the result, and "Written with Claude Code (claude-opus-5-5)". Do not merge.

## Finish

End your session with: the package id, the PR URLs, references removed per PR, what ran, and what you left with the reason. If a reference cannot be removed without breaking behaviour or using a forbidden move, leave it and say why: that is a finding, not a failure. Do NOT create routines, reminders or follow-up tasks.
