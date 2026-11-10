## Context

Two mechanisms already exist and neither needs changing:

- **`RegisterResolverService`** reads each app's `<context>_register` / `<context>_schema` `IAppConfig` keys. `ResolverListCommand` already prints them for a named app.
- **`ConfigurationService::importFromApp(string $appId, array $data, string $version, bool $force = false)`** performs the import. Its `force` parameter is not decoration: `ImportHandler` short-circuits at two sites (`ImportHandler.php:879` and `:1000`) on `$force === false && version_compare($data['version'], $existingVersion, '<=') === true`. Passing `force: true` is what makes a re-import write anything when the versions already match.

What is missing is the join between them: nothing enumerates *declaring apps* (as opposed to *resolved registers*), and nothing calls `importFromApp` outside a Repair step.

The descriptor itself is a file in the declaring app — `lib/**/[*_]register.json`, path varying by app. 18 apps ship one. The shipped version is the descriptor's own `version` field; the installed version is the version recorded on the imported configuration.

ADR-005 constrains this: OR-owned registers are materialised by idempotent Repair steps, and Rule 2 requires a second `occ upgrade` to be a no-op. This change does not touch that. It adds a second caller of the same idempotent import, which Rule 2 already guarantees is safe to invoke repeatedly.

## Goals / Non-Goals

**Goals:**

- Enumerate declaring apps, not resolved registers — so an app whose seed never ran appears.
- Report `absent` / `behind` / `current` as three distinct states.
- Re-import with `force: true` and report imported / unchanged / failed with a reason.
- Expose the inventory through occ as well as HTTP.

**Non-Goals:**

- Moving seeding out of Repair steps. ADR-005 stands.
- Per-app admin buttons in the other 17 apps.
- A diff-and-confirm flow before a forced re-import (see Decisions).
- Importing a descriptor from anywhere but the declaring app's own shipped file. No upload, no URL.

## Decisions

### Enumerate from the shipped descriptors, not from the resolver keys

The resolver keys record what an app *resolved to*, which an app with a failed import may never have written. Walking installed apps for a shipped descriptor file is the only source that can report an app whose import never happened — and that row is the requirement the capability exists for.

The resolver keys are still read, to establish which register slug a given app's descriptor claims, so the lookup for "is it present" is by slug rather than by guessing.

**Alternative rejected:** enumerate `openregister_configurations` rows. Same defect as the resolver keys — a descriptor that never imported has no row, so the interesting case is exactly the invisible one.

### `force: true` always, never `force: false`

A re-import triggered by an administrator is by definition a case where the version counter is not to be trusted: either the register is absent while the counter says current, or the write failed silently. An unforced re-import short-circuits in precisely those cases. Offering the unforced variant would produce a button that reports success and does nothing, which is a worse failure than the one being fixed.

### No diff-and-confirm before the forced import

A forced re-import rewrites the base schema. **An extending schema refers to its base rather than copying it** — `Schema::getAllOf()` returns "Array of schema IDs, UUIDs, or slugs" — so the extension continues to resolve against the updated base and customisation survives.

This is a property of the current implementation, not a law, so it is pinned by a test rather than trusted: an extension materialised as a copy at import time would silently revert somebody's customisation *through the button offered as a repair*, which is the worst possible place for that defect to live.

**Alternative rejected:** show a diff and require confirmation. It buys nothing while extensions are by-reference, and it makes the common case — an absent register, where there is nothing to diff against — require a confirmation of an empty diff.

### The outcome is a value, not a log line

`importFromApp` runs inside `SystemOperationContext::run()` and the Repair steps that call it swallow failures deliberately. The controller therefore does not rely on exceptions propagating: it reports `imported` / `unchanged` / `failed` derived from the handler's own return, and surfaces the reason on failure.

The distinction that matters: `unchanged` must mean *the import ran and found nothing to write*, never *the import was skipped*. With `force: true` the skip path is unreachable, which is what makes the three-value outcome honest.

### An occ command alongside the endpoint

The condition being diagnosed — a register that never seeded — is most likely on an instance mid-setup or mid-repair, where the admin UI may itself depend on the broken thing. A diagnosis reachable only through the surface under repair is not reliably reachable.

Both surfaces read the same service method so they cannot drift; the command is a formatter, not a second implementation.

## Declarative-vs-imperative decision

Not applicable. This change introduces no lifecycle, aggregation, calculation, notification, relation or widget behaviour on any OR object. It is an administrative read of app metadata plus an invocation of an existing import service — there is no object whose behaviour could be declared in a schema register. ADR-031's declarative default does not reach it.

## Seed Data

Not applicable. This change introduces no OpenRegister schema and therefore has no objects to seed. Its subject is the descriptors other apps ship, which already exist in those apps' repositories.

## Risks / Trade-offs

- **A forced re-import is a write to schema definitions instance-wide.** Mitigated by administrator-only access on both endpoints, and by the extension-survives test. Accepted: this is the operation the capability exists to offer, and Repair steps already perform it unattended on every upgrade.
- **The inventory walks installed apps and reads a file per app.** On an instance with many apps this is not free. Accepted: it is an admin-settings read, not a hot path, and the alternative (caching) would let the panel report a stale state — for a panel whose purpose is reporting true state.
- **Descriptor file paths vary across the 18 apps** (`lib/Settings/*_register.json` in most, other paths in some). A discovery rule that is too narrow silently omits apps — reproducing the invisibility this change exists to fix, one level up. The discovery must be verified against a count of the 18 known declaring apps, and the count asserted in a test, so a missed app fails loudly rather than shrinking the list.
- **`unchanged` could become a euphemism** if a future change reintroduces a skip path under force. The test that a version-matched re-import reports `imported` is what keeps that honest.
