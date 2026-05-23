# ADR-022: Apps Consume OpenRegister Abstractions

## Status
Accepted

## Date
2026-04-23 (proposed) — 2026-05-03 (accepted; promoted after the
OR-abstraction audit confirmed this is already operating policy and
that the per-app `adr-000` files in procest and pipelinq are the
duplicate-ADR pattern this ADR was written to prevent).

## Context

Conduction maintains ~13 Nextcloud apps (decidesk, docudesk, pipelinq, procest, opencatalogi, openconnector, mydash, larpingapp, shillinq/budgetq, zaakafhandelapp, nldesign, softwarecatalog, and the in-flight idea apps). Each app needs features that overlap heavily: objects with schemas, role-based access, audit trails, archival/retention policies, mapping/transformation, relation management, sidebar tabs with notes/tasks/files, dashboard widgets, integrations with NC-native and external services.

OpenRegister has grown into the **foundation** that provides these as shared abstractions: registers, schemas, objects, RBAC, audit-trail-immutable, archival-destruction-workflow, mappings, relations, object-interactions, and — with ADR-019 — a pluggable integration registry.

When a new app is built (or an existing app evolves), its authors face a choice: consume OR's abstraction, or build a parallel mechanism in-app. The "parallel mechanism" path is attractive at first — it's self-contained, it can be tweaked without coordinating with OR, and it avoids adding a dependency. But every instance observed so far has produced the same end state over time:

- **Duplicate data models** (an app-local Person vs OR contacts; an app-local AccessRule vs OR RBAC).
- **Drift** — app-local audit trails stop tracking things OR's audit does (replayable ordering, hash chains, retention-aware purge).
- **Missed features** — an app that rolled its own "linked files" sidebar never gets calendar/deck/polls/maps/collectives when OR adds them to the integration registry.
- **Impossible cross-app queries** — "show me all cases assigned to Jan across all Conduction apps" requires the contact linkage to be uniform.
- **Duplicate ADRs** — app-local ADRs restating what OR's already decided, then drifting.

ADR-019 codified the **mechanism** for one specific class of abstraction (integrations). This ADR codifies the **principle** that generalises: when OR has an abstraction that fits, apps consume it rather than reinvent.

## Decision

### Apps consume OpenRegister abstractions over local duplication

When an app needs functionality that OR already provides as an abstraction, the app MUST consume the OR abstraction. Rolling a parallel implementation in-app is not permitted unless explicitly justified (see "exceptions" below).

### What counts as an "OR abstraction"

Any capability exposed by OpenRegister that has a contract, a public API, and is documented as reusable. The current list (non-exhaustive):

| Abstraction | What it provides |
|---|---|
| **Registers + schemas + objects** | Versioned typed entities with validation, queries, events |
| **Authorization RBAC** | Role + scope + object-level permissions, per-schema and per-property |
| **Audit trail (immutable)** | Append-only hash-chained event log per object |
| **Archival + destruction workflow** | Retention classification, archival, purge — aligned with Archiefwet |
| **Mappings** | Cross-system transformation between source + target schemas |
| **Relations** | Typed links between OR objects |
| **Object interactions** (`object-interactions` spec) | Files, notes, tasks, tags, audit per object — the built-in part of the integration registry |
| **Integration registry (ADR-019)** | Pluggable NC-native + external integrations with tab+widget parity |
| **Audit hash chain** | Cryptographic verification of audit event order |
| **Content versioning** | Snapshot/restore of object states |
| **Deep link registry** | Cross-app navigation with stable object references |
| **TMLO metadata** | Dutch-gov metadata vocabulary compliance |
| **MCP discovery** | AI-agent discovery endpoint for all OR-backed capabilities |
| **Events + webhooks** | CloudEvents over NC's event dispatcher |
| **Schema declarative extensions** (`x-openregister-{lifecycle, aggregations, calculations, notifications, relations, widgets}`) | Behaviour declared as schema metadata in the app's register file instead of written as service classes — state machines, aggregations, derived fields, notifications, declarative relations, dashboard widgets. See ADR-031 for the keep-vs-migrate table and enforcement contract. |

New abstractions land in OR via its own openspec process. When they're merged, this ADR's list updates.

### The positive case — how to consume

1. **Use OR's PHP service via DI injection.** Don't wrap it in an app-local service that adds nothing. Thin adapters are fine; duplication isn't.
2. **Register for OR's extensibility points.** The integration registry takes DI-tagged providers (ADR-019). RBAC takes scoped role definitions. Audit takes event listeners. Apps extend through these points, not by building parallel machinery.
3. **Follow OR's schemas when OR has a schema.** If OR already defines a `contact` or `case` or `organisation` model, an app using those concepts MUST reuse the OR schema and its register — not a local copy with the same-ish fields.
4. **Call OR's REST API from the frontend via `@conduction/nextcloud-vue`.** The shared library wraps OR's API; apps that bypass it and call OR's raw endpoints re-solve problems the shared lib already solved.

### Anti-patterns

These have all been observed and should be treated as review-blocking:

- **Parallel link tables.** An app creating its own `{app}_email_links` / `{app}_contact_links` table when OR's integration registry already provides the equivalent via `openregister_*_links`. (Observed via decidesk's initial CalDAV plan using `X-DECIDESK-*` properties duplicating OR's `X-OPENREGISTER-*` mechanism.)
- **App-local schema validators.** An app writing its own JSON schema validation when OR already validates against the schema it owns.
- **Home-grown audit trails.** An app writing to a private events table instead of OR's audit trail for actions on OR-owned objects.
- **App-local RBAC on OR objects.** An app defining its own role/permission scheme for objects that live in OR's register.
- **Duplicate sidebar tab systems.** An app registering its own object-sidebar tabs outside the integration registry (ADR-019).
- **App-local "linked bookmarks/files/notes/..." that mirror an OR integration.** If OR has an integration for it, the app consumes it.
- **Duplicate ADRs.** An app-local ADR restating an org-wide ADR. The stale copies of `adr-004-frontend.md` in app repos (removed 2026-04-19) are the canonical example.

### Exceptions (when an app may build a parallel mechanism)

A parallel mechanism is acceptable only when one of the following is true, **and documented in an app-local ADR that references this ADR and justifies the divergence**:

1. **Fundamentally different domain requirements.** The app's use-case has constraints OR can't satisfy (e.g., sub-millisecond latency, append-only write with no read, special encryption-at-rest keys per tenant).
2. **OR is blocked on a dependency the app can't wait for.** Time-sensitive delivery where adding the feature to OR would push out 3+ months, and the app ships its own interim solution with an explicit migration plan.
3. **Prototype / spike.** Temporary local code with a written sunset date (max 90 days) and an owner.

Every exception requires an app-local ADR. "We didn't know OR had this" is not an exception.

### Enforcement

- **Code review gate.** Reviewers reject PRs that duplicate an OR abstraction without an explicit ADR-backed justification.
- **Specter's spec generation** surfaces applicable OR abstractions in each app's context brief (ADR-019 already flows in via `generate_spec_content.py`). The expectation is that feature specs reference the OR abstraction they consume.
- **Hydra quality gate (future).** A mechanical gate that flags common anti-patterns — parallel link tables, duplicate ADR files, schema-validator reinvention, local RBAC code acting on OR objects. Tracked as a follow-up to this ADR; implementation issue to be opened separately.
- **This ADR list updates when OR adds an abstraction.** Keeping the list current is the OR team's responsibility; when a new abstraction becomes stable, it goes in this table via a small PR against this file.

## Consequences

### Positive

- **One source of truth per capability.** Features of files/notes/tasks/calendar/mail/contacts/etc. evolve in OR; every app benefits.
- **Cross-app consistency.** "Jan is the applicant on this case" means the same thing in procest, pipelinq, and zaakafhandelapp.
- **Smaller apps.** Each app ships less code because it consumes more. A new app in 2026 should be mostly schemas + app-specific business logic; the plumbing is OR.
- **Uniform audit/RBAC/retention.** Government compliance (Archiefwet, AVG, Woo, BIO) has one implementation to verify, not 13.
- **The integration registry compounds.** When OR adds the `integration-calendar` leaf, every app using OR objects gets meeting linkage without any per-app work.

### Negative

- **App authors need to learn OR's contracts.** The onboarding curve for a new Conduction developer includes understanding OR's schemas, RBAC model, audit trail, and integration registry. Mitigated by OR's docs + this ADR list.
- **OR becomes a bottleneck for shared changes.** If a capability needs a fix, OR has to ship it. Mitigated by keeping OR fast-moving + prioritising the long-tail abstractions that unblock multiple apps.
- **Exception discipline matters.** Without rigorous review of the app-local ADR justifications, exceptions become the norm. Mitigated by the code-review gate and the explicit sunset date on prototype exceptions.

### Migration

Apps currently in violation (openconnector's bespoke linked-entity handling, decidesk's X-DECIDESK-* CalDAV properties, app-local audit copies) are not required to migrate immediately. Each gets a tracked "consume-OR-abstraction" issue with a target date. See the openregister integration registry umbrella ([openregister#1307](https://github.com/ConductionNL/openregister/issues/1307)) for the calendar/email/deck/contacts/talk migration pattern.

## Related

- **ADR-019** — Integration Registry Pattern (the first concrete instance of this principle).
- **ADR-031** — Schema-declarative business logic over service classes (the schema-engine dual to this ADR — when the abstraction is a schema extension, it's consumed in the schema register, not in a service class).
- **Openregister spec** — `openregister/openspec/changes/pluggable-integration-registry/` (the implementation that made the integration class of abstractions consumable).
- **Stale-duplicate incident 2026-04-19** — app repos carried stale copies of `adr-004-frontend.md` that drifted from the hydra master; removed across all app repos. The lesson that seeded this ADR.

## Ownership

- The OR team owns the list of abstractions in this ADR.
- Each app's maintainers own applying it inside their repo.
- Hydra reviewers enforce it at code-review time.
