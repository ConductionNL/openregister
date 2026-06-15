# Unified Search Provider

## Why

Every Conduction app stores its objects in OpenRegister, but Nextcloud's
unified search (the magnifier in the top bar) only surfaces those objects
through OR's own `ObjectsProvider` — a basic provider that returns a
flat, unlabeled "Open Register Objects" list with a literal
`// @todo: implement pagination` in `search()`, no excerpt of the
matched content, and no relationship to the app that actually owns the
object the user is looking for.

The feature re-evaluation of 2026-06-11
(`FEATURE-REEVALUATION-2026-06-11/`) suggested per-app search-provider
changes for pipelinq, procest, and planix. The product owner decided
against that direction: **unified search is provided centrally by
OpenRegister, NOT per leaf app**. One provider over the shared object
store means RBAC, multitenancy, the published predicate, schema opt-out,
and excerpt generation are enforced in exactly one place — instead of
N leaf apps each re-querying the same store with their own (drifting)
security filters.

> **Scope note:** this change replaces the previously-suggested
> per-app search-provider changes in **pipelinq**, **procest**, and
> **planix**. Those apps MUST NOT register their own
> `OCP\Search\IProvider`; they participate by registering deep-link
> patterns via the existing `DeepLinkRegistrationEvent` (see
> `openspec/specs/deep-link-registry/spec.md`), which this change
> extends with an optional display name for result labeling.

## What Changes

- Harden `lib/Search/ObjectsProvider.php` into the fleet-wide search
  surface:
  - **Access control**: results MUST only contain objects the searching
    user may read under OR RBAC (`_rbac: true`), the active tenant
    (`_multitenancy: true`), and the published predicate (published
    objects are readable; depublished/soft-deleted objects are never
    returned). Today `_rbac`/`_multitenancy` are passed but there is no
    regression spec or test pinning the contract.
  - **Schema opt-in/opt-out**: honour the existing `Schema.searchable`
    flag (column added by `Version1Date20250929120000`, default `true`)
    in the unified-search path. Schemas with `searchable = false` MUST
    NOT contribute results. The flag is already editable via the
    schemas API; this change makes it actually mean something for
    unified search.
  - **Per-app grouping/labeling**: each result entry is labeled with
    the owning app and register — rounded app icon from the deep-link
    registration, subline prefixed `{App} · {Register} · {Schema}`.
    Unclaimed (register, schema) pairs keep the OpenRegister label.
  - **Deep links**: result URLs resolve through the existing
    `DeepLinkRegistryService` to the owning app's object detail route,
    falling back to `openregister.objects.show`. No new declaration
    mechanism — apps keep using `DeepLinkRegistrationEvent` at boot.
  - **Excerpts**: the result subline contains an excerpt around the
    first match of the search term inside the object's searchable
    fields, falling back to `summary`/`description` when the match is
    in a non-string field.
  - **Pagination**: replace the `@todo` with real cursor pagination
    (`SearchResult::paginated()` with the offset cursor), so unified
    search "load more" works for registers with thousands of objects.
- Extend `DeepLinkRegistration` (DTO) and the
  `DeepLinkRegistrationEvent::register()` convenience with an optional
  `displayName` parameter used for result labeling (defaults to the
  app id, backward compatible).
- New capability spec `specs/unified-search-provider/spec.md` plus a
  delta on `deep-link-registry` for the `displayName` extension.

## Problem

1. **No traceable security contract** — `ObjectsProvider::search()`
   passes `_rbac: true, _multitenancy: true` to
   `searchObjectsPaginated()`, but no spec requirement or test pins
   that unified search respects RBAC scopes, tenant isolation, or the
   published predicate. Any refactor of the search pipeline can
   silently turn the top-bar search into an IDOR surface for every
   fleet app at once.
2. **`Schema.searchable` is dead config** — the column exists, the
   schemas API round-trips it, but nothing in the unified-search path
   reads it. Admins who untick "searchable" on a sensitive schema
   reasonably believe they removed it from the magnifier; they did not.
3. **Anonymous results UX** — every result says "Open Register
   Objects" with the OR icon. A user searching for a CRM client sees
   no clue that the hit belongs to pipelinq, and (before deep links)
   used to land in OR's generic object view.
4. **No pagination, no excerpts** — hard cap of 25 results with
   `SearchResult::complete()`, and the subline shows schema/register
   names + `updated` instead of *why* the object matched.
5. **Fleet drift risk** — without a central capability spec, leaf apps
   (pipelinq, procest, planix were already queued) start shipping their
   own providers, each re-implementing RBAC filtering against the same
   tables.

## Proposed Solution

Keep one provider class (`ObjectsProvider`) as the single fleet search
surface and close the five gaps in place. The deep-link registry stays
the only mechanism apps use to declare themselves to OR — it already
carries (appId, registerSlug, schemaSlug, urlTemplate, icon) and is
consumed by pipelinq and procest today; we add the optional
`displayName` so the registry also answers "what should this app's
results be called", not just "where do they link to".

The published-predicate, RBAC, and tenant rules are not re-implemented
in the provider; the provider delegates to
`ObjectService::searchObjectsPaginated()` and the spec pins the
delegation flags plus the observable behaviour (scenario-level
regression contract).

No database migration. No new events. No breaking change for existing
deep-link registrations.

## Out of scope

- Per-app search providers in leaf apps (explicitly rejected — see
  scope note above).
- Search across federated/remote OR instances (OpenCatalogi federation
  territory).
- A dedicated full-text relevance/ranking overhaul (Solr/vector search
  backends keep their own specs: `search-index`, `vector-embeddings`).
- Exposing unified search results to anonymous (not-logged-in) users —
  NC unified search is an authenticated surface; the published
  predicate here governs what *authenticated* users without explicit
  RBAC grants can see.
- Frontend changes in leaf apps (deep-link landing routes already
  exist where registrations exist).

## See also

- `openspec/specs/deep-link-registry/spec.md` — the boot-time
  registration mechanism this change reuses and extends.
- `openspec/specs/zoeken-filteren/spec.md` + `search-index` — the
  underlying search pipeline the provider delegates to.
- `openspec/specs/rbac-scopes/spec.md`,
  `row-field-level-security`, `saas-multi-tenant` — the access rules
  the provider must observably honour.
- `FEATURE-REEVALUATION-2026-06-11/` — pipelinq / procest / planix
  re-evaluations whose per-app search suggestions this change
  supersedes.
- Hydra ADR-022 (apps consume OR abstractions) — the principle behind
  the centralise-don't-duplicate decision.
