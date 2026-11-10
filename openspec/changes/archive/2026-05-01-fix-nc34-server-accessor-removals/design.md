## Context

Nextcloud 34 removed the legacy named accessors on `\OC\Server`. OpenRegister declares `max-version="34"` in `appinfo/info.xml` but still contains ~26 call sites to these removed methods. In production on NC 34 the app crashes on the first call into `OrganisationMapper` with `Call to undefined method OC\Server::getSystemConfig()`. Additional call sites will crash as soon as the corresponding code path runs (audit trail writes, notifications, GraphQL controller responses, migration execution, etc.).

This pattern has been cleaned up by hand multiple times already. It keeps returning because:
1. Nothing prevents it from being committed — PHPStan and Psalm both accept it.
2. Existing usages in the codebase serve as copy-paste templates for new code.
3. Reviewers miss individual occurrences in large PRs.

**Supported Nextcloud versions**: `appinfo/info.xml` declares `min-version="28"` and `max-version="34"`. `MigrationService::createInstance` in NC 28 through NC 34 resolves migration classes via `\OCP\Server::get($class)` with a `new $class()` fallback (`QueryException` in ≤32, `NotFoundExceptionInterface` from 33). Therefore migrations can use constructor DI on every supported version — no special-casing is needed.

**Current state audit** (from `grep -rn "OC::\$server->get[A-Z]" lib/`):

| File | Line(s) | Accessor | Target interface |
|---|---|---|---|
| `Db/OrganisationMapper.php` | 876 | `getSystemConfig` | `IConfig` |
| `Db/AuditTrailMapper.php` | 304, 323, 324, 1009, 1050, 1103, 1203 | `getUserSession`, `getRequest`, `getLogger` | `IUserSession`, `IRequest`, `LoggerInterface` |
| `Db/SearchTrailMapper.php` | 773, 1036 | `getLogger` | `LoggerInterface` |
| `Service/UserService.php` | 554, 604 | `getDatabaseConnection`, `getL10NFactory` | `IDBConnection`, `IFactory` |
| `Service/Object/ReferentialIntegrityService.php` | 352, 850 | `getDatabaseConnection` | `IDBConnection` |
| `Service/RetentionService.php` | 570 | `getDatabaseConnection` | `IDBConnection` |
| `Controller/DeletedController.php` | 81 | `getGroupManager` | `IGroupManager` |
| `Controller/GraphQLController.php` | 199, 208, 209 | `getURLGenerator`, `getContentSecurityPolicyNonceManager`, `getCsrfTokenManager` | `IURLGenerator`, `ContentSecurityPolicyNonceManager`, `CsrfTokenManager` |
| `BackgroundJob/DestructionCheckJob.php` | 172 | `getDatabaseConnection` | `IDBConnection` |
| `Notification/Notifier.php` | 135, 144 | `getURLGenerator` | `IURLGenerator` |
| `Command/SolrDebugCommand.php` | 256 | `getRegisteredAppContainer` | replace with injected collaborator |
| `Migration/Version1Date20250830120000.php` | 124 | `getDatabaseConnection` | `IDBConnection` |
| `Migration/Version1Date20250908180000.php` | 80 | `getDatabaseConnection` | `IDBConnection` |
| `Migration/Version1Date20250929120000.php` | 112 | `getDatabaseConnection` | `IDBConnection` |
| `Migration/Version1Date20251103120000.php` | 131 | `getDatabaseConnection` | `IDBConnection` |
| `Event/ToolRegistrationEvent.php` | 43 (docblock) | `get(MyCMSTool::class)` | update the example to `\OCP\Server::get()` since the docblock is external-facing |

## Goals / Non-Goals

**Goals**
- Restore app boot and runtime on Nextcloud 34.
- Eliminate every named-accessor call on `\OC::$server` from `lib/` by migrating each call site to constructor DI with an OCP interface.
- Prevent the pattern from returning via an automated PHPCS sniff that fails `composer check:strict`.
- Keep the change reviewable: one file per call site cluster, no surprise refactors.

**Non-Goals**
- Migrating the 446 existing `\OC::$server->get(X::class)` PSR-11 calls to constructor DI. They are separately trackable, they don't crash on NC 34, and touching them here blows up the diff.
- Changing routing, API surface, database schema, or feature behavior.
- Reorganising class responsibilities beyond adding constructor parameters.
- Adding new tests for code paths that don't change behavior. (Only existing tests that mock the affected constructors are updated.)
- Backfilling docblock examples in other files that reference `\OC::$server`.

## Decisions

### D1 — Constructor DI over service-locator

We inject OCP interfaces through the constructor rather than swapping `\OC::$server->getX()` for `\OCP\Server::get(X::class)`.

**Rationale:** ADR-004 mandates constructor DI. `\OCP\Server::get()` is functionally the same as `\OC::$server->get()` on NC 34+ — both are service locators. Cleaning up a service-locator call by replacing it with another service-locator call solves the NC 34 crash but leaves the anti-pattern in place, meaning every subsequent touch-up costs the same effort.

**Alternatives considered:**
- *Swap `\OC::$server->getX()` for `\OCP\Server::get(X::class)` in place.* Smallest diff, but leaves the service-locator pattern, and the PHPCS sniff (see D3) would be weaker.
- *Mixed approach: DI for controllers/services/mappers, service-locator for migrations and background jobs.* Rejected after verifying (via upstream `MigrationService::createInstance` in stable28 through stable33) that DI resolution for migration classes is present on every supported version.

### D2 — Migrations use constructor DI too

Migration classes receive their dependencies through the constructor.

**Rationale:** Nextcloud's `MigrationService::createInstance` has resolved migrations via `\OCP\Server::get($class)` since at least NC 28, with a `new $class()` fallback that is never hit when the class is autoloadable. Our migrations are all autoloadable (PSR-4 under `OCA\OpenRegister\Migration\`), so DI resolution always succeeds. Therefore `Version*.php` can declare `public function __construct(private readonly IDBConnection $connection)` and use `$this->connection` inside `preSchemaChange`/`changeSchema`/`postSchemaChange`.

**Alternatives considered:**
- *Keep migrations on service-locator.* Rejected: consistency matters, and the sniff (D3) is simpler without exceptions.
- *Bump `min-version` if older versions lacked migration DI.* Not needed — all supported versions already resolve migration classes through DI.

### D3 — Custom PHPCS sniff with no allowlist

A new sniff `CustomSniffs\Sniffs\Nextcloud\NoLegacyServerAccessorsSniff` flags any `\OC::$server` reference in `lib/` — named accessor, PSR-11 `get`, or bare property access — as an error.

**Rationale:** Recurrence is the driver of this change, not the one-time crash. A pattern that is expensive to fix and cheap to reintroduce guarantees regression. The sniff is the cheapest enforcement mechanism we have and follows the precedent set by `NamedParametersSniff` (the project already ships custom sniffs, so there's no new tooling surface). A tight rule (no allowlist, no file exceptions) is viable because the 446 existing PSR-11 `get` calls are already scheduled for later cleanup and this change is the forcing function.

**Alternatives considered:**
- *Psalm / PHPStan baseline entry that tightens gradually.* Rejected: static analysers already "accept" `\OC::$server` because `OC\Server` has `__call` and PHPDoc stubs; they cannot detect the violation without a custom plugin. Custom PHPStan rules are more expensive to write and maintain than a PHPCS sniff.
- *Soft allowlist that matches current remaining usages (PSR-11 `get`).* Rejected: a soft allowlist becomes permanent; hard failure on the first usage is the whole point. Instead the sniff is enabled only after all ~26 named-accessor calls are migrated in this change, and the 446 PSR-11 calls become a follow-up that the sniff will drive.
- *CI grep check outside PHPCS.* Rejected: adds a second enforcement surface and is easier for developers to bypass locally. PHPCS integrates cleanly with `composer check:strict`.

### D4 — Scope: named accessors only, PSR-11 `get` deferred

The PHPCS sniff in this change flags **both** `->getX()` and `->get(...)` against `\OC::$server`, but the migration work in this change only addresses the ~26 named-accessor call sites. The 446 PSR-11 `->get(X::class)` sites remain, and the sniff would fail. To reconcile this, the sniff is implemented in a separate commit from its registration: the migration commit lands first (clean tree for named accessors), the sniff is added next, and the sniff is **registered in `phpcs.xml` only after** a follow-up change addresses the PSR-11 call sites.

Wait — this decision has to be reconciled with the proposal, which lists sniff registration as in-scope. Resolution:

**Revised D4:** Sniff registration lands in this change, but the sniff is scoped to **named accessors only** (`\OC::$server->getSomething(` where the first char after `get` is uppercase). The PSR-11 `->get(ClassName::class)` pattern is explicitly NOT flagged. The 446 PSR-11 calls can be migrated in a follow-up, and the sniff will be tightened to forbid PSR-11 `get` in that follow-up change.

This keeps the sniff useful today (catches the exact pattern we just cleaned up) while staying compatible with the 446 call sites we're deferring. The spec requirement "Static-Analysis Enforcement" is marked against named accessors for this change; the PSR-11 tightening is captured as a spec scenario for a later change.

**Alternatives considered:**
- *Full-strength sniff (matches PSR-11 too) with an allowlist of existing call sites.* Rejected: allowlists rot, and we'd have to maintain a list of 446 file:line pairs.
- *Defer the sniff entirely to the follow-up change.* Rejected: the entire point of this change is preventing recurrence; the sniff is the mechanism.

### D5 — `SolrDebugCommand::getRegisteredAppContainer`

The one call site of `getRegisteredAppContainer('openregister')` is in `SolrDebugCommand`, which uses it to look up services for debug output. We resolve this by injecting the specific services the command needs (identified by reading the immediate usages of the container lookup) rather than injecting the container itself.

**Rationale:** Injecting `IAppContainer` would re-introduce a service-locator through the back door and defeat the PHPCS sniff's intent. Reading the call site shows a small number of services resolved — each can be a constructor parameter on the command.

**Alternatives considered:**
- *Inject `IAppContainer` and keep `get()` calls.* Rejected — same as above, service-locator through a different door.

### D6 — Tests

Each class that gains a constructor parameter needs its existing unit tests' constructors updated to pass a mock of the new interface. We don't add new behavioral tests — no behavior changes — but we add coverage for the new sniff (a PHPUnit test under `tests/Unit/CustomSniffs/` that feeds sample PHP into the sniff and asserts errors are reported, matching what already exists for `NamedParametersSniff`).

**Rationale:** ADR-009 (mandatory test coverage) expects the sniff class to have its own test. Existing tests for the modified `lib/` classes need to compile; the mock additions are mechanical.

## Risks / Trade-offs

- **[Risk] Unit tests across 13+ classes need constructor mock updates; the diff is mechanical but touches many files.** → Mitigation: per-file commits grouped by class so review is tractable; run `composer check:strict` and PHPUnit after each file.
- **[Risk] Migration classes gaining constructor parameters could fail on exotic Nextcloud deployments not covered by stable28-34 mainline.** → Mitigation: verified via upstream source that all supported stable branches resolve migrations via `\OCP\Server::get($class)`. Fallback `new $class()` only triggers on `NotFoundExceptionInterface`, which requires DI resolution to fail — it won't for an autoloadable PSR-4 class.
- **[Risk] PHPCS sniff false positives on string literals that happen to contain `\OC::$server`.** → Mitigation: sniff uses token-based matching (`T_STRING`, `T_DOUBLE_COLON`, `T_VARIABLE`) rather than regex; no string-token inspection. Mirror the approach of `NamedParametersSniff`.
- **[Risk] `\OC::$server` inside PHPDoc examples or comments could appear as a regression if the sniff is overly aggressive.** → Mitigation: token-based matching ignores `T_DOC_COMMENT` and `T_COMMENT` by default. The one such docblock example (`Event/ToolRegistrationEvent.php:43`) is also updated as part of this change.
- **[Trade-off] Leaving 446 `->get(X::class)` sites in place is a known debt.** → Accepted: fixing them here makes the diff unreviewable. Follow-up change `migrate-psr11-to-constructor-di` is listed as deferred work in the proposal and the sniff can be tightened to cover them later.
- **[Trade-off] Some classes already have very large constructors (e.g. `OrganisationMapper` with 4 deps, `AuditTrailMapper` with more).** → Accepted: adding 1–3 more dependencies does not push them past common Nextcloud-app thresholds (existing mappers in other Conduction apps have similar sizes). Future refactoring (constructor-heavy classes → split into smaller classes) is orthogonal.

## Migration Plan

1. Branch from master.
2. **Phase 1 — Fix the immediate crash.** Migrate `OrganisationMapper` (`getSystemConfig` → `IConfig`). Update `OrganisationMapperTest`. Commit. Deploy candidate is now runnable on NC 34 for the reported crash path.
3. **Phase 2 — Migrate remaining call sites.** One commit per class (or per tightly-coupled group). Update corresponding unit tests in the same commit.
4. **Phase 3 — Add the PHPCS sniff.** Create `NoLegacyServerAccessorsSniff.php` under `phpcs-custom-sniffs/CustomSniffs/Sniffs/Nextcloud/`. Add `<rule ref>` to `phpcs.xml`. Add `tests/Unit/CustomSniffs/NoLegacyServerAccessorsSniffTest.php`. Run `composer phpcs` to verify clean.
5. **Phase 4 — Run `composer check:strict` and the full PHPUnit suite.** Fix any PHPStan baseline deltas that arise from constructor changes.
6. **Phase 5 — Open PR.** Reference this change's artifacts and the originating crash report.

**Rollback**: each phase is an independent commit. If phase N breaks something, `git revert` that commit alone. The PHPCS sniff commit is entirely additive and can be reverted without affecting the code migration.

## Open Questions

- Should the sniff also flag `\OC::$server->query(...)` (deprecated alias for `get()`)? Proposed answer: yes, same token pattern, trivially covered. Listed as spec scenario.
- Are there classes outside `lib/` that should also be sniffed (e.g. `tests/` for `setUp()` helpers)? Proposed answer: no — the sniff is scoped to `lib/` in phpcs.xml because tests legitimately bootstrap container state via `\OC::$server` in a controlled way. To be revisited in the PSR-11 follow-up.
- Does `SolrDebugCommand` actually need `IAppContainer`, or is there a smaller injection? Confirm by reading the call context before Phase 2 implementation.
