## Context

OpenRegister already integrates with a cluster of Nextcloud "surface"
contracts for register objects: `OCP\Search\IProvider` (unified search,
`ObjectsProvider`), `OCP\Notification\INotifier`, the Activity provider
(`lib/Activity/Provider.php`), a comments listener, and a dashboard widget —
all wired in `lib/AppInfo/Application.php`. None of these feed the NC
Assistant / Context Chat RAG pipeline. `OCP\ContextChat` is Nextcloud's
platform contract for that: an app registers one or more
`IContentProvider`s (`getId`, `getAppId`, `getItemUrl($id)`,
`triggerInitialImport`) by listening for `ContentProviderRegisterEvent`, and
pushes/removes individual items via `ContentManager::submitContent()` /
deletion calls as content changes. The platform gates everything on
`isContextChatAvailable()` so callers never hard-depend on the
`context_chat` app.

OpenRegister's object lifecycle already dispatches `ObjectCreatedEvent`,
`ObjectUpdatedEvent`, `ObjectDeletedEvent` from `MagicMapper` — the same
events `ObjectChangeListener` (text extraction) and `ObjectMetricsListener`
(operational counters) already listen on. This is the correct, existing hook
point; no hot-path service needs modification.

The deep-link URL problem this feature also needs (an object's canonical
"open this" URL, per app, per (register, schema)) is already solved by
`DeepLinkRegistryService::resolveUrl()`, which `ObjectsProvider` uses
today for exactly this purpose, with a fallback to the OR-native
`openregister.objects.show` route.

## Goals / Non-Goals

**Goals:**
- Register OpenRegister as a Context Chat content provider, softly gated so
  a standalone instance without `context_chat` installed is unaffected.
- Submit/withdraw content on the existing object create/update/delete event
  flow.
- Give schema authors an explicit, default-OFF opt-in — indexing every
  object in every register into a third-party AI pipeline by default is not
  acceptable; this must be a deliberate per-schema decision.
- Never submit content a given searcher would not otherwise be allowed to
  see via the same published/RBAC predicate unified search already
  enforces.
- Reuse the existing deep-link mechanism for `getItemUrl`, not invent a
  second URL-templating surface.
- Provide both an automatic initial-import path (`triggerInitialImport`)
  and an operator-triggered occ command for backfill/repair.

**Non-Goals:**
- Per-user visibility filtering *within* Context Chat's own retrieval layer.
  The platform's `IContentProvider` contract does not expose a per-query
  "as this user" parameter to `submitContent()` — content is submitted once,
  app-wide, and Context Chat's own access-control layer (which shells out to
  each provider's `getItemUrl`/permission hooks per the platform docs) is
  what NC uses to decide whether a *searching* user may see a *retrieved*
  chunk. OpenRegister's contribution here is to never submit content that
  is not published/RBAC-visible to *at least the general population this
  schema is opted into* — see "Access-model decision" below. Full per-user
  ACL parity with unified search's live RBAC check is explicitly deferred
  (see Open Questions).
- Chunking/embedding strategy — that's Context Chat's own responsibility
  once content is submitted as plain text.
- Any change to `ObjectsProvider` (unified search) or the internal
  `chat-ai` RAG capability — both are unrelated, pre-existing capabilities.
- A new HTTP/REST surface. The only new entry point is an occ command.

## Decisions

### Registration: event listener, not a constructor-time IRegistrationContext call

Unlike `registerSearchProvider()` / `registerCalendarProvider()` (called
directly on `IRegistrationContext` at app boot), Context Chat provider
registration is event-driven: `context_chat` dispatches
`ContentProviderRegisterEvent` and every listening app calls
`$event->registerContentProvider($appId, $providerId, $providerClass)` on
it. This is the platform-mandated shape (verified against
docs.nextcloud.com developer_manual digging_deeper context_chat, July
2026) — there is no `IRegistrationContext::registerContentProvider()`
method to call directly. We register a small
`ContentProviderRegistrationListener` on `ContentProviderRegisterEvent` in
`registerEventListeners()`, mirroring the existing
`OcmResourceTypeListener` pattern (another event-advertised registration).

### Availability guard: `isContextChatAvailable()`, checked in the listener body

The registration listener only fires when `context_chat` dispatches the
event in the first place, which already implies the app is present — but
we additionally guard the listener body with an explicit
`isContextChatAvailable()` check (thin wrapper over
`IAppManager::isEnabledForUser('context_chat')`) before calling
`registerContentProvider()`, mirroring the existing
`class_exists('OCA\\Tables\\Event\\TableDeletedEvent')` guard used for the
optional Tables integration. Belt-and-braces: it costs nothing and protects
against any future platform change where the event fires unconditionally.

### Submission hook: new listener on the existing object lifecycle events

A new `ContextChatSubmissionListener` listens on `ObjectCreatedEvent`,
`ObjectUpdatedEvent`, `ObjectDeletedEvent` — the same events
`ObjectMetricsListener` already consumes — rather than modifying
`ObjectService` or `MagicMapper` directly. This keeps the hot object-save
path untouched (ADR-022 territory: OR's own internal write path stays
service-agnostic of any single consumer) and keeps the feature fail-soft:
like `ObjectMetricsListener`, the listener catches `Throwable` and only
logs, so a Context Chat/`ContentManager` failure can never abort an object
write.

### Opt-in: `configuration['x-openregister-contextchat']`, default OFF

Follows the established `x-openregister-*` schema-configuration annotation
convention (`x-openregister-quality`, `x-openregister-object-source`, etc.)
rather than a new dedicated schema column (contrast with the existing
top-level `searchable` boolean, which governs unified search, not this
feature — the two must stay independently controllable: a schema can be
unified-searchable but not Context-Chat-indexed, or vice versa).
**Critical implementation detail**: `Schema::setConfiguration()` maintains
an explicit allow-list of recognised `x-openregister-*` keys and silently
drops anything not on it (this exact class of bug shipped twice before —
see the `x-openregister-processing` code comment in `lib/Db/Schema.php`
and the historical or#460/#462 writeOnly-boundary incident). Task list
includes adding `x-openregister-contextchat` to that allow-list as an
explicit, separately-verified step — "the key exists in my JSON payload"
is not sufficient evidence it round-trips.

### getItemUrl: reuse `DeepLinkRegistryService`, no new config surface

The proposal's originating brief suggested a bespoke
`contextchat_url_template` config key with `{register}/{schema}/{uuid}`
placeholders. Investigation found OpenRegister already has exactly this
mechanism, fleet-wide: `DeepLinkRegistryService::resolveUrl(registerId,
schemaId, objectData)`, which `ObjectsProvider` already calls for the
identical "what URL takes a user to this object" problem, with the same
`openregister.objects.show` fallback when no app has claimed a deep link
for that (register, schema). Inventing a second, OR-Context-Chat-specific
URL-template config would (a) duplicate a solved problem, (b) create two
sources of truth that could disagree, and (c) mean OpenBuild virtual apps
that already register a deep link for their schemas would need to register
a *second*, differently-shaped config to get the same behaviour in Context
Chat. `ContentProvider::getItemUrl($id)` therefore resolves the object by
uuid, then calls the same `resolveUrl()` / fallback pair `ObjectsProvider`
uses.

### Access-model decision (published/RBAC boundary)

Context Chat's `IContentProvider` contract, as documented, does not accept
a "for this user" parameter on `submitContent()` — a provider submits
content once, and the platform's own permission-checking hooks (evaluated
per retrieval) are what NC uses to decide whether a match may be shown to
a given querying user. This is coarser than OR's own live per-object RBAC
model (per-scope grants, tenant isolation, publish windows). Rather than
either (a) submitting everything and hoping Context Chat's own ACL layer
is a perfect proxy for OR's RBAC — which it structurally cannot be, since
it has no notion of OR scopes/tenants — or (b) blocking this feature
entirely on a full per-user-ACL-aware Context Chat contract that does not
exist today, we take the deliberately conservative middle path already
established for unified search's "published" concept: only ever submit
objects that satisfy the same published predicate `ObjectsProvider`
already enforces (`@self.published` set and in the past,
`@self.depublished` unset or in the future — see
`openspec/specs/unified-search-provider/spec.md`), on schemas an
administrator has explicitly opted in. This means: (1) unpublished /
soft-deleted / not-yet-published objects are NEVER submitted, regardless
of opt-in; (2) once published, an object is visible to the Assistant for
*any* user who can reach Context Chat at all — the same trust boundary NC
already applies to, e.g., globally-shared Talk conversations indexed for
the same user's Assistant. Tenant/organisation-scoped visibility nuances
(an object published within tenant A appearing in tenant B's Assistant
answers) are explicitly OUT of scope for this change and tracked as an
Open Question / follow-up issue — schema authors opting a multi-tenant
schema in must be aware submitted content is not tenant-partitioned by
Context Chat itself.

### Declarative-vs-imperative decision (ADR-031)

ADR-031 governs *consuming apps'* business logic on top of OR schemas —
whether decidesk/pipelinq/etc. write a bespoke PHP service where an
`x-openregister-*` schema extension already exists. This change is
different in kind: it is OpenRegister itself implementing a Nextcloud
*platform* integration contract (`OCP\ContextChat\IContentProvider`, an
event listener, an occ command) — there is no schema-extension mechanism
this could instead be expressed as, in the same way `ObjectsProvider`
(`OCP\Search\IProvider`), the notifier, and the activity provider are also
necessarily imperative PHP, not schema-declarative behaviour. This is a
valid ADR-031 exception under the "OR's extension is missing or
insufficient" category — more precisely, out of that ADR's scope entirely,
since ADR-031 does not govern OR's own platform-facing infrastructure. The
one piece of this change that *is* schema-declarative — the per-schema
`x-openregister-contextchat` opt-in flag — correctly follows the
`x-openregister-*` convention rather than, say, a global app-wide config
toggle, keeping the declarative surface where ADR-031 says it belongs.

## Seed Data

Unit tests need:
- One `Schema` fixture with `configuration['x-openregister-contextchat'] =
  true` (opted in) and one with the key absent/false (opted out, default),
  both belonging to the same `Register` fixture used elsewhere in
  `tests/Unit/`.
- Two `ObjectEntity` fixtures under the opted-in schema: one published
  (`@self.published` in the past, no `@self.depublished` or in the
  future) and one unpublished — to assert the predicate filter.
- One `ObjectEntity` fixture under the opted-out schema — to assert the
  opt-in filter independently of the publish filter.
- No database seed/migration data is required in `lib/Migration/` — the
  opt-in flag lives in the existing JSON `configuration` column, and no new
  table is introduced by this change.
- `triggerInitialImport`/occ-command integration exercised against the
  existing Postgres-backed dev instance (8080) with the standard
  `openregister:seed` fixtures plus one manually opted-in schema, per
  project convention of live-verifying platform integrations rather than
  trusting a green mocked test suite alone.

## Risks / Trade-offs

- [Context Chat's own ACL layer may not exist / may be coarser than
  documented] → Mitigation: the published-predicate + opt-in gate is
  enforced entirely on the OR side before `submitContent()` is ever called,
  so OR's guarantee does not depend on Context Chat's retrieval-time
  behaviour matching the docs.
- [Multi-tenant schemas opted in leak published content across tenants via
  the Assistant] → Mitigation: documented explicitly above as a known,
  deliberate limitation; schema authors must not opt in a tenant-scoped
  schema until a follow-up closes this gap (tracked as an Open Question).
- [`ContentManager::submitContent()` unavailable/slow blocks object saves]
  → Mitigation: listener runs post-persist (on `*Created`/`*Updated`, not
  `*Creating`/`*Updating`) and wraps the call in try/catch + log, matching
  `ObjectMetricsListener`'s fail-soft pattern — a Context Chat outage never
  blocks or fails an object write.
- [`Schema::setConfiguration()` allow-list silently drops the new key if the
  task is missed] → Mitigation: called out as its own task item with an
  explicit round-trip assertion in tests (write config, re-read schema,
  assert key survived) — not just "key accepted at save time".
- [Reindex command run against a very large register times out / OOMs] →
  Mitigation: batched, same batching approach as the existing
  `RematerialiseCalculationsCommand`.

## Migration Plan

- No database migration required (uses the existing `configuration` JSON
  column on `oc_openregister_schemas`).
- `x-openregister-contextchat` added to the `Schema` configuration
  allow-list is backward compatible — existing schemas without the key
  behave exactly as before (opted out).
- Rollout is opt-in per schema; no fleet-wide behaviour change on deploy.
  Registration itself only activates if `context_chat` is installed and
  enabled, so instances without it are entirely unaffected.
- Rollback: disable the schema-level flag (or revert the code); no data
  cleanup is required on the OR side. `context_chat`-side cleanup of
  already-submitted content is out of OR's control and follows whatever
  `context_chat`'s own uninstall/reset story is.

## Open Questions

- Should tenant/organisation scoping be modelled explicitly once Context
  Chat's own contract exposes a per-item ACL/audience hook (if/when the
  platform adds one)? Tracked as a follow-up; out of scope here.
- Should `x-openregister-contextchat` support a richer shape (e.g. field
  allow-list controlling which properties are submitted as content, versus
  "the whole object") in a later iteration? This change submits a flat
  text rendering of all non-writeOnly, non-relation scalar properties;
  richer per-field control is deferred.
