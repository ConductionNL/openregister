# ADR-001: Information architecture — top-level navigation and spec placement

**Status**: accepted

**Date**: 2026-05-23

## Context

OpenRegister is a developer-and-admin platform for building registries: typed
Schemas, governed Registers, validated Objects, with a long tail of
capabilities — file attachment, audit trail, RBAC scopes + ABAC policy,
retention/AVG, OpenLineage, NLX, CDC, PII detection, NORA/BIO/Forum
Standaardisatie compliance, plus integrations with NC core apps and external
adapters (BAG/BRP/KvK, ZGW, DSO, OpenProject, xWiki).

The spec catalog now holds 114 specs. Without a discipline for placing each
spec on a navigation surface, the top-level menu would inflate every time a
new capability landed (one menu entry per ABAC engine, per lineage protocol,
per compliance matrix, per integration). That fails users on two axes:

- **Audience confusion** — platform admins, register owners, and integrators
  navigate the same surface; promoting every capability to the top hides the
  data primitives those users came to find.
- **Discoverability collapse** — a 25-item top menu is no better than no menu;
  users learn neither path.

The guiding principle is: **menu items are destinations users navigate to, not
features the app has.** An ABAC engine, a lineage protocol, a compliance
matrix — none of those are destinations. They are properties of destinations.

This ADR codifies the resulting information architecture so future specs land
in the right place without re-litigating placement every time.

## Decision

**Adopt an eight-item top-level navigation organised around data primitives,
with cross-cutting capabilities placed on tabs inside those primitives.**

The top-level menu is fixed at:

1. **Registers** — registry catalog (tenant-bounded collections of objects).
2. **Schemas** — type catalog (definitions, evolution, hooks).
3. **Objects** — cross-cutting data browser (search, validation, history).
4. **Integrations** — pluggable integration registry (NC + external).
5. **Audit** — audit/compliance forensics surface.
6. **Workflows** — workflow engine, scheduled actions, retention runs.
7. **Beheer** — platform-ops admin (tenants, auth, policies, environments,
   data, compliance, observability, system).
8. **API & Docs** — developer surface (REST/GraphQL/OAS/MCP, no-code, roadmap,
   URN, deep links).

The AI chat companion (`chat-ai`) is a global launcher (sidebar pill), not a
menu item — same pattern as Nextcloud unified search.

### Numbered rules

#### Rule 1 — Menu items are data primitives, not features

Registers, Schemas, Objects, Integrations, Audit, Workflows are *things you
navigate to*. ABAC, NLX, OpenLineage, PII detection, CDC, NORA are
*capabilities of those things*. Capabilities live on a tab inside a primitive
or inside Beheer; they never get their own menu item.

**Rationale.** Every capability promoted to the menu shrinks the headroom for
genuine destinations and forces all users to scan vocabulary that only one
audience cares about. Tabs let the audience that needs the capability find it
in context (e.g., an integrator finds NLX on the Integrations menu, not by
scanning the top bar).

**How to apply.** When proposing a new spec, ask: *would a user navigate here
as their first action of the day?* If no, it is a tab or sub-page under a
primitive — not a menu item. If the spec spans multiple primitives (e.g.
`linked-entity-types` touches Schemas and Objects), it gets a tab in each.

#### Rule 2 — Cross-cutting governance lives in Beheer, organised by topic

Beheer has exactly eight tabs: Tenants, Auth, Policies, Environments, Data,
Compliance, Observability, System. New governance specs join an existing tab
by topic; they do not earn a new tab unless a topic doesn't fit any existing
one (and even then, the bar is "this is a new governance domain", not "this
is a new spec").

**Rationale.** Admins look for "who can share what" or "what runs on a
schedule", not "spec X". Topic-grouping keeps the cognitive cost of the
admin surface constant as specs accrete. Eight tabs is the comfort limit for
horizontal tab strips at typical Nextcloud admin widths.

**How to apply.** A new compliance spec joins the Compliance tab. A new
observability spec joins Observability. If the spec sits at the platform-ops
level (defaults, quotas, system toggles), it goes into Beheer; if it is
instance-scoped (per-register, per-schema), it goes onto the primitive's
detail page.

#### Rule 3 — Every primitive gets a consistent tab template

Registers detail page is always: Overview, Schemas, Objects, Permissions,
Retention, Integrations, Lifecycle, Audit, Settings. Schemas detail page is
always: Definition, Evolution, Hooks, Validation, Read coercion, i18n,
Used-in, Cleanup. Objects detail page is always: Properties, History, Audit,
Lineage, Files, Interactions, Workflow.

**Rationale.** Consistency across primitives reduces cognitive load — users
learn the tab layout once and apply it to every register or schema they
visit. Tab order encodes priority (what they need most first).

**How to apply.** A new spec that scopes to a primitive lands on one of the
existing tabs. Adding a new tab requires the same bar as Rule 2: a new domain
of concern, not just a new feature.

#### Rule 4 — Specs that affect both an instance and an org-level policy appear in both places

`retention-management` appears per-register (Retention tab) and at org-level
(Workflows: Retention runs; Beheer: Tab Data for defaults). `rbac-scopes`
appears per-register (Permissions tab) and at org-level (Beheer: Tab Auth).
`computed-fields`, `datetime-input-handling`, `extended-field-types`,
`schema-driven-read-coercion`, `mock-registers`, `webhook-payload-mapping`
follow the same pattern.

**Rationale.** Different users approach these from different directions. A
register owner edits the binding from their register's tab; a platform admin
sets the default from Beheer. Forcing one audience through the other's
surface is a UX tax. The detail page edits the instance; Beheer sets the
defaults.

**How to apply.** When a spec has both per-instance configuration and
org-wide defaults, mention both placements in the spec's metadata and link
them in the UI (e.g., the per-register Retention tab shows "Inherits from
Beheer → Data" and links to it).

#### Rule 5 — Developer surface is its own menu, separate from Beheer

API & Docs (REST, GraphQL, OAS, OpenAPI, MCP, deep links, URN, no-code,
roadmap, NC API compat, ZGW mapping, webhook templates) is its own menu.
Beheer is for platform-ops (tenants, auth, policies, environments). Both
audiences require admin rights, but the work they do is different enough to
warrant the split.

**Rationale.** Integrators want generated specs, playgrounds, addressing
references, and roadmap visibility. Platform admins want quotas, policies,
audit, compliance matrices. Bundling them would either bury the developer
artefacts inside a governance console or pollute the governance console with
developer surfaces.

**How to apply.** A new spec aimed at people writing integrations (clients,
adapters, webhooks, OAS extensions, MCP tools, no-code) goes into API & Docs.
A new spec aimed at people running the platform goes into Beheer.

## Consequences

**Positive:**

- The top-level menu stays at 8 items with one slot of headroom — new
  capabilities never threaten the navigation budget.
- Spec placement is mechanical once the rules are internalised — proposers
  and reviewers stop arguing about menus and argue about capabilities.
- New audiences (integrators vs admins vs register owners) find their work in
  the same place each time, because the primitives are stable and the tabs
  are templated.
- The pluggable integration registry naturally absorbs new internal NC and
  external adapter specs without inflating the menu — a new adapter is a row
  on the Integrations list, not a new top-level item.

**Negative / trade-offs:**

- Specs that affect multiple primitives need two (or more) UI surfaces and a
  cross-link convention; reviewers must remember Rule 4. Mitigated by the
  explicit "instance vs default" framing.
- Compliance matrices (NORA / BIO / NEN-7510 / Forum Standaardisatie) live
  inside a Beheer tab and don't get their own menu — they may feel
  under-promoted to compliance-focused users. Mitigated by linking the
  Compliance tab from the Audit menu's landing page.
- The Integrations menu is large (32 placements). Mitigated by grouping
  into Internal / External / Protocol-level columns on the list view.

## Alternatives considered

- **Promote every capability to a menu item.** Rejected: 25+ menu items is
  no menu; it forces all audiences through every other audience's vocabulary.
- **Single "Admin" menu that absorbs Beheer + API & Docs.** Rejected:
  conflates platform-ops with integrator workflows; the audiences differ.
- **Capability-first navigation (Compliance, Security, Integrations as
  top-level peers).** Rejected: the platform's *unit of work* is the
  registry primitive, not the capability — admins start with "which
  register" before they start with "which policy".
- **Hide Beheer behind Instellingen** (mydash pattern). Rejected:
  openregister's admin surface is large enough (~28 specs) to warrant a
  dedicated menu rather than a settings tab; mydash's admin surface is much
  smaller.

## Source

Distilled from the cross-app information-architecture analysis at
`/tmp/ia-mydash-openregister.md` (openregister section, §B–§F), which
catalogues all 114 openregister specs against this 8-item menu and the
per-primitive tab templates above.

## Related

- mydash mirrors the same discipline with a 7-item menu (Dashboards,
  Templates, Reports, Comments, Beheer, Catalog, Instellingen) and the
  parallel rule that widget types are catalog entries, not menu items.
- Hydra ADR-022 (`apps-consume-or-abstractions`) — informs why Beheer for OR
  is platform-ops specifically (consuming apps don't re-implement the same
  governance).
- Hydra ADR-019 (`integration-registry`) — the Integrations menu is the
  surface for the cross-app pluggable integration registry.
