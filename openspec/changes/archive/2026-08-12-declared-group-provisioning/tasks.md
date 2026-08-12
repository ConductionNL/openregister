## Tasks

- [x] Add `lib/Service/Authorization/RbacGroupCollector.php` — array-based collection of declared group ids from a register/schema/property `authorization` block and from the OAS scope map; parse action lists and the `roles` map separately; do NOT walk a rule's `match` conditions; filter `admin`/`public`/blanks and de-duplicate preserving first-seen order.
- [x] Add `lib/Service/Authorization/GroupProvisioner.php` — `groupExists()`/`createGroup()` per declared group, create-only, never deleting and never adding members; per-group try/catch so one failing backend does not cost the rest; never throws.
- [x] Add `GroupProvisioner::inventory()` reporting `{exists, members}` per group, with `members` null (not 0) when `IGroup::count()` returns `false`.
- [x] Delegate `OasService::extractGroupFromRule()` to the shared collector so the advertised OAS scope set and the provisioned set cannot diverge on rule grammar; verify generated OAS output is unchanged.
- [x] Wire provisioning into `ImportHandler::importFromJson()` via a `setGroupProvisioner()` optional setter, called AFTER `computeDefinitionHash()` and BEFORE the version/hash skip return; persist the declared set to `IAppConfig` as `declared_groups_<appId>`; swallow and log all failures.
- [x] Register `GroupProvisioner` on the `ImportHandler` factory in `lib/AppInfo/Application.php`, wrapped in try/catch like the other optional dependencies.
- [x] Emit `components.securitySchemes.oauth2.flows.authorizationCode.scopes` from `ExportHandler::exportConfig()`, derived from the exported registers and schemas, with `admin` always present and `public` only when the configuration names it.
- [x] Add `lib/Service/Authorization/GroupReconciler.php` — union of live register authorization, live schema + property authorization, and stored `declared_groups_*` app-config entries; call `findAll()` with `_rbac: false, _multitenancy: false`; never throws.
- [x] Add `lib/BackgroundJob/GroupReconcilerJob.php` (`TimedJob`, 3600s) and register it in `appinfo/info.xml <background-jobs>`.
- [x] Unit tests for the collector: both rule forms; `match` conditions never become groups; `roles` keys are role names not groups; `public: true` is not an action list; property-level groups collected; reserved principals dropped; document unions authored scopes with the derived floor; empty result is earned, not the default.
- [x] Unit tests for the provisioner: creates only missing groups; never adds members; one failure does not stop the rest; an uncountable backend reports unknown rather than empty.
- [x] Unit tests for the export scope map, asserting through `fromScopeMap()` (NOT `fromDocument()`, which re-derives the same groups from the definitions and stays green against the lossiness it is meant to pin).
- [x] Record a positive control for each behaviour: breaking reserved-principal filtering fails 2 tests; disabling roles-map handling fails 1; reverting the export change fails 2.
- [x] Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan) and the full unit suite.
- [x] Run `openspec validate declared-group-provisioning --strict` and fix until clean.
- [x] Archive: sync the spec delta into `openspec/specs/rbac-scopes/spec.md` and repoint the `@spec` tags at the canonical path.

## Deferred (tracked, not in this change)

- [ ] Admin surface listing declared groups with their member counts, so a declared-but-empty group is visible rather than silently denying everyone (`inventory()` supplies the data).
- [ ] Hydra gate asserting every group named in an `authorization` block appears in the configuration's scope map (near-free — the collector already computes both sides).
- [ ] Repair-hook fixes for the six apps that declare no `<install>` block (opencatalogi, openconnector, softwarecatalog, procest, pipelinq, shillinq) and for launchpad's `SeedRolePermissions`, which is `<install>`-only and therefore never runs on upgrade. One PR per repo.

## Acceptance criteria

- A group named ONLY in a property-level `authorization` rule exists as a Nextcloud group after import, with zero members.
- No Nextcloud group named `public` or `admin` is ever created.
- A rule carrying `match` conditions provisions only its `group`, never a condition field name or literal value.
- A `roles` map provisions its VALUES (string or list) and never its KEYS.
- Re-importing an unchanged configuration whose declared group was deleted by hand re-creates that group.
- An app whose register import is declared only under `<post-migration>`, installed fresh so that step never runs, still has its declared groups after the reconciler's next tick.
- The reconciler enumerates registers and schemas with RBAC and multi-tenancy filtering disabled.
- Generated OAS output is unchanged by the rule-parsing delegation.
