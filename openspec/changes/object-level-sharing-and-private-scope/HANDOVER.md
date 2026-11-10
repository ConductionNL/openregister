# Handover — object-level sharing and the `private` scope

Written to survive a session compaction. Everything needed to pick this up cold.

---

## 1. Where things are

| | |
|---|---|
| **Worktree** | `/home/rubenlinde/gate19-worktrees/or-sharing-spec` |
| **Branch** | `docs/shared-credentials-and-flows-spec` (22 commits ahead of `development`) |
| **PR** | [openregister#2241](https://github.com/ConductionNL/openregister/pull/2241) — OPEN. Tests/lint/phpcs green; the four Code Quality jobs (psalm, phpstan, phpmd, Quality Report) fail **on `development` itself** since `d6172375a`, and a PR runs against the MERGE with the base, so this branch inherits them — see below |
| **Shared checkout** | `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister` — the LIVE bind-mount the running Nextcloud serves. **Other sessions have files modified here.** |
| **Live instance** | `http://localhost:8080` (admin/admin), containers `nextcloud` + `conduction-postgres` |

The PR carries TWO OpenSpec changes:

- `shared-credentials-and-flows` — credential sharing, **shipped and green**
- `object-level-sharing-and-private-scope` — **groups 2 and 3 shipped, plus group
  4's enforcement half**; the rest of group 4 (the write API and 4.3's test) is next

---

## 2. What is DONE (do not redo)

Credential sharing works end to end and is on the PR:

- **`$contains` RBAC operator** — `OperatorEvaluator` (PHP verdict) plus ONE
  platform-branched SQL builder called by BOTH list emitters, so they cannot
  drift. The QueryBuilder cannot express JSON containment on either platform, so
  it emits the same fragment via `createFunction()`.
- **Broker Guard 1c** — a share admits, within the tenant edge. Only ever
  ADMITS, so no pre-existing verdict changed.
- **Owner-only share API** — `GET/PUT /api/credentials/{id}/shares`,
  `GET /api/credentials/shared-with-me`.
- **Schema properties + `SharePrincipalDeriver`** on both registers.
- **ADR-004 Rule 4 amended** — the guard chain now documents three admit branches.

**Two pre-existing bugs fixed on the way**, both found by the parity matrix, not
by reading:

1. `MagicRbacHandler` kept a private token resolver recognising only BARE tokens,
   so every dotted form (`$user.groups`, `$user.uid`, `$organisation.<prop>`)
   resolved on `find` and fell through as a LITERAL STRING on `list`.
2. `MagicRbacHandler::hasPermission()` did not honour the `authenticated`
   pseudo-group, while both SQL emitters and `PermissionHandler` do — so
   `{"group":"authenticated","match":{…}}` was granted by `list` and denied on
   `find`.

---

## 3. All seven decisions are settled

| | decision |
|---|---|
| Q1 | **No share provider.** `IShareProvider`/`IShare` are Files-bound (`getNodeId(): int` non-nullable). Share the object's **NC folder** instead — every object has one on demand, and `ShareLinkService` already reads the six types that matter on it |
| Q2 | Mirror Files — `TYPE_EMAIL`, account-less link addressed to an email |
| Q3 | **Both** — schema default for new objects, object overrides |
| Q4 | **Amend ADR-006** — a link is a revocable, expiring *capability*, not a visibility flag |
| Q5 | Uniform core set on core's permission bitmask + per-schema verbs in `IShare`'s `IAttributes`, ADR-governed |
| Q6 | `read` separate from `use`; `use` implies `read` |
| Q7 | Collapse `scope` into `private` — **ACCESS half only**. `scope` also selects the vault owner; removing it makes every existing organisation secret unreachable |

Full reasoning in `design.md` (D1–D13 + Open Questions).

---

## 3b. Groups 2 and 3 are DONE — what they left behind

`private` is enforced on all four paths. The vocabulary, the PHP verdict and the
SQL predicate live once in **`lib/Service/Rbac/ObjectScopeResolver.php`**; every
path is a caller. Storage is the `scope` key of an authorization block (design
**D3a**), at object and schema level, object wins in both directions.

Proven on live Postgres by `tests/Db/PrivateScopeParityIntegrationTest.php` —
now 11 tests covering scope AND grants, and the positive control was run for
**each of the four paths independently**.

**Two consequences to carry forward.**

Neither list emitter returns unfiltered any more — not on an unconditional
grant, and not on a schema with no authorization block at all. An OBJECT can
declare itself private on an otherwise open schema. Three existing bypass
assertions were updated accordingly (the grant is unchanged; its encoding is).

**Non-admins still cannot set `scope`.** `stripSelfInjectionFields()` strips
`authorization` from every non-admin write, so today only an admin can make an
object private. Task **4.0** is the `scope`-only carve-out, and it must stay
`scope`-only: unlike the action lists in the same block, `scope` can only narrow.

### Two findings, both recorded in tasks.md

1. **A pre-existing leak, now pinned by a passing test.** A per-object ACTION
   override in `_authorization` (`{"read": ["admin"]}`, live since Wave-12 Fix 5)
   is honoured by `PermissionHandler` and IGNORED by both list emitters and by
   `MagicRbacHandler::hasPermission()`. Such an object is **denied on `find` and
   returned by `list`**. Not fixed — pinned by
   `testPerObjectActionOverrideIsNotYetHonouredByTheListPaths`, which fails when
   somebody fixes it. Task 3.8.
2. A pre-existing `QueryBuilder::select()` "Undefined array key 0" warning on the
   RBAC-filtered list path with a session. Proven against baseline. Task 3.9.

---

## 3c. Group 4's ENFORCEMENT half is done too

`ObjectGrantResolver` resolves a caller's grants from core's shares on object
folders, read-through, memoised for ONE request. Composed into all four paths as a
single substitution (design **D3b**):

    owner OR ((notPrivate OR grantedToMe) AND rules)

A grant makes a private row behave as an ordinary one and the rules then decide,
so the schema stays the CEILING and there is no second admit path to keep in step.

Verified live: folder name == object UUID, a `TYPE_USER` folder share is creatable
and maps back, a FILE share inside the folder is NOT an object grant, a grant is
scoped to the object it names, a grant cannot widen past the schema, and revocation
denies on the next request. Positive control run for the SQL builder's grant
disjunct and both PHP fall-throughs, independently.

**Third bug found, and this one is FIXED.** `prepareObjectDataForTable()` was
destroying per-object `_authorization` on every save: the method strips
`authorization` from incoming metadata (deliberately — per-object RBAC is not
writable by ordinary create/update calls), but the field was ALSO in the
metadata-column map, whose loop resolves `$metadata[$field] ?? null`. The stripped
key came back as an explicit NULL and got written. So a private object became
visible again as soon as anything saved it — and resolving its folder does exactly
that, meaning **sharing an object was enough to un-private it**. The Wave-12 Fix 5
per-object action overrides have therefore never survived an update either. Fixed
by removing the field from the map; pinned by
`testAnUpdateDoesNotDestroyPerObjectAuthorization`.

### What is NOT done in group 4

| | |
|---|---|
| 4.0 | Owner may set their own object's `scope` — still admin-only |
| 4.1b | The owner-only grant/revoke API. `ShareLinkService::createShare()` CANNOT be reused: it needs a `$fileId` and rejects anything that is not a file inside the folder |
| 4.3 | Tenant edge is IMPLEMENTED but has NO test — needs a two-organisation fixture |
| 4.4 | Recipient cannot widen or re-share onward |
| 4.5 | The resolver CARRIES the permission bitmask; nothing consumes it, so every grant currently admits for `read` |

---

## 4. NEXT UNIT OF WORK — finish group 4

Start with **4.0** (the owner-may-set-`scope` carve-out), because without it the
capability is unreachable for a real user and the e2e in group 10 cannot be
written. Then 4.1b (the API), then 4.3's missing test, then 4.4/4.5.

The four paths that must change together for any new principal — a principal
honoured by some and not others is a silent access-control bug, and that is how
both of the bugs in §2 happened:

| file | what it decides |
|---|---|
| `lib/Service/Object/PermissionHandler.php` → `privateScopeVerdict()` | single-object verdict |
| `lib/Db/MagicMapper/MagicRbacHandler.php` → `hasPermission()` | relation-path verdict |
| `lib/Db/MagicMapper/MagicRbacHandler.php` → `applyRbacFilters()` | QueryBuilder list path |
| `lib/Db/MagicMapper/MagicRbacHandler.php` → `buildRbacConditionsSql()` | raw-SQL search path |

Both emitters now take their predicate from `notPrivateSqlFor()`, which calls the
ONE builder on the resolver. **Extend that, do not add a second predicate.**

Order that worked twice now: PHP verdict → SQL builder → wire both emitters to
the ONE builder → unit tests → live-DB parity matrix → positive control per path.

---

## 5. TRAPS — each of these cost a false conclusion

**RBAC / testing**

- `applyRbacFilters()` **bypasses RBAC entirely** when there is no user and
  `PHP_SAPI === 'cli'` (documented, deliberate — occ/cron/repair). A CLI parity
  test with no session sees the list path return EVERYTHING and reads as a
  fail-open divergence that does not exist. **Create a real non-admin user and
  log it in.**
- RBAC ORs an `_owner = <uid>` condition into the filter, so fixtures owned by
  the session user are admitted regardless of the predicate — **own fixtures with
  someone else.**
- Admins bypass RBAC. Assert the test user is not one.
- `buildRbacConditionsSql()` (raw path) has NO CLI bypass; `applyRbacFilters()`
  does. Same schema, different posture — know which one a test exercised.
- A parity test that passes with one side stubbed proves nothing. **Always run the
  positive control** (temporarily disable one side; it must fail).
- **`searchAcrossMultipleTables()` needs MORE THAN ONE register/schema pair to take
  the UNION path.** With one pair it falls back to the SEQUENTIAL implementation,
  which uses the QueryBuilder emitter — so a "raw SQL vs QueryBuilder" parity test
  built on a single pair compares one implementation with ITSELF and reports
  perfect agreement. Cost me a test that proved nothing until the positive control
  showed the two columns moving together.
- **Feed the PHP paths objects READ BACK from the database.** Building them in
  memory beside the fixtures means a fixture in the shape the code expects, which
  cannot catch the code reading the wrong shape. Also assert the fixture's own
  column is really on disk — a NULL `_authorization` makes every private case look
  correctly denied for entirely the wrong reason.
- **A pre-existing warning surfacing in a NEW test is not a new warning.** The
  `QueryBuilder::select()` "Undefined array key 0" looked like mine until I ran the
  probe against the baseline `MagicRbacHandler` and it reproduced. Baseline-compare
  before attributing anything — and note that a `set_error_handler` inside a
  PHPUnit test does NOT fire, so that is not the way to get a trace.
- **Baseline-compare by FAILING TEST NAME, not by count.** 964 errors before and
  after looked identical; the failure count went 8 → 11 and the diff named exactly
  the three tests, all of them assertions about the bypass semantics I had
  deliberately changed. A count alone would have hidden which.
- A `docker exec` phpunit run that times out mid-script can leave BASELINE files
  deployed in the shared checkout. **Always re-verify which version is deployed
  after a timeout** — `diff -q` each file against the worktree.
- **After `git checkout --` cleanup in the shared checkout, the deployed TEST files
  are stale too.** Two "new" failures in `MagicRbacHandlerIntegrationTest` were the
  old assertions running against new code. Re-deploy tests, not just `lib/`, before
  believing a regression.

**CI attribution**

- ⚠️ **A PR's checks run against the MERGE with the base, not against your branch.**
  When `development` broke the four Code Quality jobs, this PR's runs went red with
  violations in files the branch never touched, at SHIFTED LINE NUMBERS. I wrongly
  concluded my commit had caused it, because the previous run — which merged with an
  older, still-green `development` — had passed. **Check the BASE branch's own run
  before attributing a CI regression to yourself.**
- The tell was line-number shifts in unchanged files: `FilterNode.php:134 → :148`
  with `git diff` showing that file untouched. Same violation, different line = the
  analysed source is not what you think it is.
- **phpmd here gates on a BASELINE, not a count.** Reproduce it exactly:
  `./vendor/bin/phpmd lib text phpmd.xml --baseline-file phpmd.baseline.xml` — a bare
  `phpmd` run lists ~300 baselined violations and tells you nothing. My first attempt
  compared violation COUNTS (177 vs 180) and drew a confident, wrong conclusion.
- psalm has `findUnusedBaselineEntry="true"`, so a baselined issue that stops
  occurring is itself an error.

**Object folders and shares**

- An object's NC folder is created in the storage of **whichever session asks for it
  first** (`/<uid>/files/Open Registers/<register>/<object-uuid>`). Core only lets a
  user share a node they can reach, so a grant fixture needs a REAL owner user and
  the folder must be resolved while logged in as them.
- The folder's `name` IS the object UUID — that is the reverse lookup. Do NOT use
  `FileMapper::findOwningObjectUuid()` for it: that resolves a file's PARENT, so on
  a folder share it returns the REGISTER folder's name, not the object's.
- **`ShareLinkService` cannot create an object grant.** `createShare()` takes a
  `$fileId` and rejects any node that is not a file inside the folder — it is the
  file-share concept, and the two must stay separate.
- `searchAcrossMultipleTables()` aside, note that a test asserting the CONSEQUENCE
  ("the object stays hidden") caught the `_authorization` wipe, while every test
  asserting the mechanism passed. Prefer asserting the outcome the user would notice.

**Register imports**

- Import is gated `force === false && version_compare(new, existing, '<=') → skip`,
  compared against the version **in the DATABASE**, not the previous file. The
  shipped `flow_register.json` said 1.1.0 while the DB was already 1.2.0, so it
  had been silently skipping. **Verify by reading the live schema's properties**,
  not by "the import ran".
- A machine reset rolled the DB back; the registers are at 1.1.0/1.2.0 again and
  need re-importing to test against.

**Shared checkout discipline**

- It is bind-mounted and **other sessions have files modified there**. Deploy only
  your own files, test, then `git checkout --` exactly those. Never `git add -A`,
  never `git clean`.
- `vendor` is a symlink target: `.gitignore` has `/vendor/` (a *directory*
  pattern), so a `vendor` **symlink** shows as untracked and would be committed by
  `git add -A`. Bind-mount it into the container instead.

**Other**

- Adding a REQUIRED constructor param is a **fatal**, not a test failure — PHP
  refuses to declare the class and every test in the file dies. `grep -rn "new
  ClassName("` for every construction site first. The broker had 8; the controller
  had 3, and one was in a file whose *other* construction ends identically.
- NC's `?v=` does not change on rebuild — a browser keeps serving the pre-fix
  bundle. `core.cachebuster` does not help for app assets; clear the browser cache
  via CDP.
- Backticks in a `git commit -F -` heredoc get **shell-expanded**. Words vanish
  from the message.
- Psalm 5.26 crashes on the container's PHP 8.5 for *unmodified* files. Let CI be
  the psalm check.

---

## 6. Command recipes

```bash
# Unit tests, no NC environment needed (fast)
V=/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/vendor
docker run --rm -v "$PWD":/app -v "$V":/app/vendor:ro -w /app nextcloud:34 \
  php vendor/bin/phpunit --bootstrap tests/bootstrap-standalone.php --no-configuration <file>

# Tests needing \OC::$server (DB group, fixtures) — deploy first, then:
docker exec -u www-data -w /var/www/html/custom_apps/openregister nextcloud \
  php -d memory_limit=-1 vendor/bin/phpunit -c phpunit.xml <file>

# phpcs EXACTLY as CI runs it (whole lib scope, errors only) — run before EVERY push
docker run --rm -v "$PWD":/app -v "$V":/app/vendor:ro -w /app nextcloud:34 \
  php vendor/bin/phpcs --standard=phpcs.xml --error-severity=1 --warning-severity=0

# CI: wait for checks to APPEAR before waiting for them to settle
until gh pr checks 2241 --repo ConductionNL/openregister --json name | grep -q '"name"'; do sleep 20; done
until [ -z "$(gh pr checks 2241 --repo ConductionNL/openregister --json name,bucket \
  --jq '.[] | select(.bucket=="pending") | .name')" ]; do sleep 30; done

# Push (the credential helper is not wired)
TOKEN=$(gh auth token)
git -c http.extraheader="AUTHORIZATION: basic $(printf 'x-access-token:%s' "$TOKEN" | base64 -w0)" push
```

---

## 7. Sequencing reminder

Groups 2–8 are additive and opt-in: an object with no `private` scope and no
grants is decided exactly as today.

**Group 9 (flows) is BREAKING and goes last.** Flows have `authorization = NULL`
and three unguarded run entry points (`flowRun#test`, `flowRun#retry`,
`FlowMcpToolProvider::runFlow()`), so restricting them removes access that exists
today — and it is the gate on `credentialIdentity: owner` from the sibling change,
which **must not ship before it**.
