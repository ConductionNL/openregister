# Cleanup: Remove LinkedEntityService::TYPE_COLUMN_MAP

## Problem

The umbrella (`pluggable-integration-registry`) marks `LinkedEntityService::TYPE_COLUMN_MAP` as `@deprecated` and moves the authoritative list of integration ids into the `IntegrationRegistry`. This cleanup change removes the constant once the built-in providers have stabilised and no caller references it directly.

## Context

- **Depends on:** `pluggable-integration-registry` and on all 5 built-in-provider migrations being merged (files, notes, tasks, tags, audit-trail). Those live inside the umbrella tasks.md — this cleanup leaf runs **after** the umbrella is archived.
- **Schedule intent:** Ship the constant-removal as a separate small change so that the umbrella can go out without waiting for full stabilisation, and so a rollback (if needed) has minimal blast radius.
- **Consumers of the constant:** expected to be zero outside OR core. The constant was never documented public API. Pre-removal a final grep + issue sweep confirms.

## Proposed Solution

1. Grep the entire ConductionNL org for any remaining references to `LinkedEntityService::TYPE_COLUMN_MAP` or `VALID_LINKED_TYPES`. If any exist, open issues to migrate the callers to `IntegrationRegistry::listIds()` and wait for them to merge.
2. Remove the constant from `LinkedEntityService.php`.
3. Remove `Schema::VALID_LINKED_TYPES` if still present (parallel deprecation).
4. Update any remaining docblocks or developer docs that reference the constants.
5. Run full PHPCS / PHPMD / PHPStan / Psalm strict to confirm no latent references.

## Scope

### In scope

- Delete `LinkedEntityService::TYPE_COLUMN_MAP` constant
- Delete `Schema::VALID_LINKED_TYPES` constant
- Update developer docs / READMEs that reference either
- Pre-removal org-wide grep + issue sweep
- Tests confirming the registry-driven path is unchanged

### Out of scope

- Any code-behaviour change (behaviour was already unified to use the registry in the umbrella)
- Moving built-in providers into an app (they stay in core OR)
- Renaming the constant's successor API

## Acceptance criteria

- [ ] Grep of ConductionNL org confirms zero remaining references to either constant
- [ ] Constants removed from source
- [ ] Strict checks pass
- [ ] Backwards-compat snapshot tests on `CnObjectSidebar` and `Schema::validateLinkedTypesValue()` still pass
