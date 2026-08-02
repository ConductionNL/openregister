# Handover — object-level sharing and the `private` scope

Written to survive a session compaction. Everything needed to pick this up cold.

---

## 1. Where things are

| | |
|---|---|
| **Worktree** | `/home/rubenlinde/gate19-worktrees/or-sharing-spec` |
| **Branch** | `docs/shared-credentials-and-flows-spec` (15 commits ahead of `development`) |
| **PR** | [openregister#2241](https://github.com/ConductionNL/openregister/pull/2241) — OPEN, 26/26 CI green |
| **Shared checkout** | `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister` — the LIVE bind-mount the running Nextcloud serves. **Other sessions have files modified here.** |
| **Live instance** | `http://localhost:8080` (admin/admin), containers `nextcloud` + `conduction-postgres` |

The PR carries TWO OpenSpec changes:

- `shared-credentials-and-flows` — credential sharing, **shipped and green**
- `object-level-sharing-and-private-scope` — **specified, all decisions settled, not started**

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

## 4. NEXT UNIT OF WORK — task groups 2 and 3

**The `private` principal across all four enforcement paths, plus the live-DB
parity matrix.** One coherent, reviewable chunk.

The four paths that must change together (a principal honoured by some and not
others is a silent access-control bug — that is how both bugs above happened):

| file | what it decides |
|---|---|
| `lib/Service/Object/PermissionHandler.php` | single-object verdict |
| `lib/Db/MagicMapper/MagicRbacHandler.php` → `hasPermission()` | relation-path verdict |
| `lib/Db/MagicMapper/MagicRbacHandler.php` → `buildSingleOperatorCondition()` | QueryBuilder list path |
| `lib/Db/MagicMapper/MagicRbacHandler.php` → `buildSingleOperatorConditionSql()` | raw-SQL search path |

Order that worked last time: PHP verdict → SQL builder → wire both emitters to
the ONE builder → unit tests → live-DB parity matrix → positive control.

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
