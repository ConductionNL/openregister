# OpenRegister Platform Capabilities

**Read this before designing or building anything.** Every capability listed here is provided by OpenRegister or one of its in-flight changes; the consuming app does NOT reinvent it. This is the canonical answer to "is the platform already doing this?"

The file is the single source of truth read by:

- **Specter's `specter-prepare-context`** — when generating a context brief for a spec, the relevant rows are surfaced so the spec writer references the existing capability instead of prescribing bespoke code.
- **Hydra's builder + reviewer skills** (`team-backend`, `team-frontend`, `team-architect`) — when implementing or reviewing a spec, the builder reads this catalog to pick the right consumption mechanism.
- **Hydra's spec linter** (`scripts/lib/lint-spec-for-redundant-crud.py`) — when a spec references a capability listed here, the linter treats it as a positive signal (the spec is using the platform path) and does not flag it as a redundant wrapper.

When OpenRegister adds a new capability, the implementer updates this file in the same PR. A capability that doesn't appear here is not yet platform-provided.

---

## How to use this catalog

When you're scoping a feature, walk top-down through the buckets below:

1. **Schema annotations** — declarative capabilities you turn on by adding a key to the schema JSON. No PHP, no Vue.
2. **NC-app integration providers** — surface OpenRegister objects in Nextcloud Activity / Calendar / Contacts / Mail / Files / etc. without per-app glue.
3. **Object interactions** — notes, tasks, files, tags, audit, versioning. Free with every OpenRegister object.
4. **Infrastructure** — events, webhooks, RBAC, multi-tenancy. Apps consume; they don't write.

If a feature fits one of these buckets, the spec output is configuration (annotation, manifest, integration registration), not a controller / service / view.

---

## 1. Schema annotations

Each annotation lives at the schema's top level. Validation runs at schema-save time and rejects malformed annotations with HTTP 422.

| Annotation | Status | Spec | What it provides |
|---|---|---|---|
| `x-openregister` | implemented | (object metadata) | Register + schema reference on every object |
| `x-openregister-relations` | implemented | `nextcloud-entity-relations` | Typed relations between objects (one-to-many, many-to-many, with cardinality) |
| `x-openregister-seeds` | implemented | (seed data spec) | Sample objects on install for demos / testing |
| `x-openregister-archival` | implemented | `archival-destruction-workflow` | Retention classification + archival metadata (Archiefwet) |
| `x-openregister-lifecycle` | proposed | `lifecycle-annotation` change | Declarative state machines: `field`, `initial`, `transitions{action: {from, to, requires?}}`. Pre-save validation rejects invalid transitions; sugar `POST /api/objects/{id}/transition` endpoint; `ObjectTransitionedEvent` joins the existing event family. |
| `x-openregister-aggregations` | proposed | `aggregations-and-calculations` change | Declarative `count`/`sum`/`avg`/`min`/`max`/`count_distinct` queries with optional groupBy + time-bucket. `GET /api/objects/aggregations/{name}`. RBAC-scoped, cached, invalidates on writes. |
| `x-openregister-calculations` | proposed | `aggregations-and-calculations` change | Computed fields. Expression DSL over other properties (concat, if, arithmetic, comparison, date diff). Materialise at save (aggregatable) or compute on read (virtual). |
| `x-openregister-notifications` | proposed | `notifications-annotation` change | Declarative notification subscriber registration. Auto-creates Webhook entities; reuses `notificatie-engine` for delivery. Triggers: created/updated/transition/scheduled/threshold. Recipients: users/groups/field/relation/object-acl/expression. Channels: nc-notification/email/webhook/talk/activity. |

**Anti-pattern:** writing a `*Service::transition()` method, `*AnalyticsService::get*()` method, or `*NotificationService::notify*()` method against an OpenRegister object. The annotations above replace each of those classes wholesale.

---

## 2. NC-app integration providers

Each provider integrates OpenRegister objects with a Nextcloud app's native UI. Apps register implementations via DI tags; each provider follows the same `register / activate / deactivate` lifecycle.

| Provider | Status | Spec | Integrates with | Surface |
|---|---|---|---|---|
| Activity provider | proposed | `activity-provider` change | NC Activity app | Object create/update/delete events appear in the standard activity stream + dashboard widget + email digest. No per-app integration. |
| Calendar provider | proposed | `calendar-provider` change | NC Calendar | Object date fields surface as read-only calendar events. Schemas declare which date field is the calendar source via the annotation. |
| Contacts actions | proposed | `contacts-actions` change | NC Contacts | `ContactsMenu\IProvider` adds actions to contact entries: jump to OR object, link to existing object, create new from contact. Matching by email / display name / org. |
| Mail sidebar | proposed | `mail-sidebar` change | NC Mail | Viewing an email shows OR objects linked to that email (uses existing `openregister_email_links`). |
| Mail Smart Picker | proposed | `mail-smart-picker` change | Mail / Talk / Text / Collectives | Reference Provider that lets users search + insert OR object references with rich-preview cards. |
| File actions | proposed | `file-actions` change | NC Files | File rename / copy / move / version on object-attached files. Extends the existing FileService. |
| Profile actions | proposed | `profile-actions` change | NC user profile | Self-service GDPR export, password change, API token management, notification preferences. |

**Anti-pattern:** hand-coding `<App>ContactsMenu.vue`, `<App>CalendarSync.vue`, `<App>MailSidebar.vue`. Use the providers; they ship the integration uniformly.

---

## 3. Object interactions (free with every object)

OpenRegister wraps Nextcloud's native subsystems behind a unified API. Every object gets these without per-app code.

| Interaction | NC subsystem | Spec |
|---|---|---|
| Notes | `OCP\Comments\ICommentsManager` | `object-interactions` |
| Tasks | CalDAV via NC Calendar | `object-interactions` |
| Files | `OCP\Files\IRootFolder` | `object-interactions` |
| Tags | NC system tag manager | `object-interactions` |
| Audit trail (immutable, hash-chained) | OR's own table + `audit-hash-chain` spec | `audit-trail-immutable` |
| Versioning (snapshot/restore) | OR's own table | `content-versioning` |
| Deep links (cross-app navigation) | `deep-link-registry` spec | `deep-link-registry` |

**Anti-pattern:** writing `<App>NotesService`, `<App>TasksService`, `<App>FilesService`. Use the unified API; the integration with NC's native subsystems is already wired.

---

## 4. Infrastructure (apps consume; never write)

| Capability | Status | Spec | What apps get |
|---|---|---|---|
| Event-driven architecture | implemented | `event-driven-architecture` | 39+ typed event classes (`ObjectCreatingEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`, etc.). `IEventDispatcher` registration. `StoppableEventInterface` for pre-mutation hooks. |
| Webhooks with payload mapping | implemented | `webhook-payload-mapping` | `WebhookService`, `WebhookDeliveryJob`, `CloudEventFormatter`. Twig template payload mapping. HMAC, retry/backoff, dead-letter queue, multi-tenancy. |
| Notification delivery | implemented (extending) | `notificatie-engine` | `Notifier`, `NotificationService`, INotificationManager integration, channel adapters, user preferences. |
| RBAC (per-object ACLs) | implemented | `authorization-rbac-enhancement` + `rbac-scopes` | Per-object permissions, scoped roles, RBAC-aware queries. |
| Multi-tenancy | implemented | `saas-multi-tenant` | Organisation scoping on every entity (`MultiTenancyTrait`). |
| Search + filter + facet | implemented | `zoeken-filteren` | `findObjects` with full-text search, faceted drill-down, multi-field sort, cursor + offset pagination. Backend-agnostic (Postgres / Solr / Elasticsearch). |
| Mappings (cross-system transformation) | implemented | (mappings spec) | Twig-based payload transformation between source + target schemas. |
| Geospatial metadata + map view | implemented | `geo-metadata-kaart` | Lat/long extraction, map sidebar, geo-search. |
| MCP discovery (AI agents) | implemented | `mcp-discovery` | AI-agent discovery endpoint exposing every OR-backed capability. |
| GraphQL API + SSE | implemented | `graphql-api` + `realtime-updates` | GraphQL surface over registers; subscriptions via Server-Sent Events. |
| OAS / OpenAPI generation | implemented | `openapi-generation` + `oas-validation` | Per-register OpenAPI 3 spec auto-generated from schemas. |
| Data import / export | implemented | `data-import-export` | Bulk import (CSV/JSON), bulk export (CSV/JSON/Excel/PDF). |
| Computed fields (basic) | proposed | `aggregations-and-calculations` | Declarative computed fields via `x-openregister-calculations`. |

**Anti-pattern:** writing `<App>EventListener` whose only job is to forward to an in-app handler (use existing event listeners directly), `<App>WebhookService` (use the platform's), `<App>RbacService` (use OR's RBAC), `<App>SearchService` (use `findObjects` with filter), per-app `Mapper` classes wrapping `ObjectService` (use the service directly).

---

## 5. Frontend abstractions (`@conduction/nextcloud-vue`)

The companion frontend library; same anti-duplicate principle.

| Capability | What it provides |
|---|---|
| `useObjectStore` | Canonical Pinia store consuming `findObjects` + CRUD endpoints. |
| Manifest renderer (`CnAppRoot`, `CnPageRenderer`, `CnAppNav`) | Declarative app shell from `src/manifest.json`. Tier 4 = full manifest-driven app. |
| `appSettings.fields[]` (proposed) | Manifest-declared settings form. `CnAppSettingsForm` reads/writes IAppConfig. |
| `dashboard.layout[]` (proposed) | Manifest-declared dashboard. KPI grid + list + tile + chart + custom widget bailout. |
| `pages[].config.actions[]` (proposed) | Manifest-declared detail-page action buttons. `kind: transition / endpoint / external / route`. |
| `objectStore.transition(type, id, action)` (proposed) | Companion to `x-openregister-lifecycle`. |
| `objectStore.aggregate(type, name, params?)` (proposed) | Companion to `x-openregister-aggregations`. |
| `CnIndexPage`, `CnDetailPage`, `CnDashboardPage` | Schema-driven pages: list / detail / dashboard. |
| `CnDataTable`, `CnFilterBar`, `CnFacetSidebar`, `CnPagination` | List-view building blocks. |
| `CnFormDialog`, `CnAdvancedFormDialog`, `CnSchemaFormDialog` | Schema-driven create/edit forms. |
| `CnObjectDataWidget`, `CnObjectMetadataWidget` | Object detail widgets. |

---

## When to write app-side code

After walking the catalog top-down, code is justified only for genuinely-bespoke domain logic:

- **LLM / orchestration / template generation** that isn't covered by `notificatie-engine` rendering (decidesk's minutes draft generation; pipelinq's email autocomposition).
- **External system clients / bidirectional sync** beyond what the existing integration providers cover (custom SMS provider; ICP scoring against a third-party API).
- **Bespoke UI** that the manifest layout vocabulary can't express (drag-to-customize dashboards; visual form builders; real-time DAG editors).
- **Workflow orchestration** with parallel branches, joins, conditional cascades — beyond a single state-machine table (pipelinq's automation engine).

Every other feature fits one of buckets 1–5 above.

---

## Catalog maintenance

This file is updated as part of every change that adds, modifies, or removes a platform capability. The change's `tasks.md` MUST include a step to update the catalog. CI checks for changes to capability-providing specs that don't update this file.

When adding a row:

- **Status** = `implemented` once the spec moves from `changes/` to `specs/`; `proposed` while still in `changes/`.
- **Spec** = the spec slug (no path; the catalog is in the same tree as both `changes/` and `specs/`, so consumers can resolve relative paths).
- **What it provides** = one sentence; full detail lives in the linked spec.

The catalog is intentionally short. Detail belongs in the specs.
