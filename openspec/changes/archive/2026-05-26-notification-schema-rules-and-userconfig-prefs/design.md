## Context

OpenRegister's `notificatie-engine` spec already establishes that schema-annotated notifications drive delivery, and `x-openregister-notifications` is already an accepted key in `Schema::ANNOTATION_VOCABULARY`. But the dispatch path is only half-wired:

- `lib/Service/Notification/NotificationsAnnotationInstaller.php` reads the annotation on schema-save and materialises ONLY the `webhook` channel (it upserts persistent `Webhook` entities so the existing `WebhookService` delivers them). There is no in-app/push dispatch from the annotation.
- `lib/Notification/Notifier.php::prepare()` has a single-case switch that only handles `configuration_update_available`. Any schema-declared in-app notification therefore renders nothing in the bell.
- An existing `NotificationSubscription` entity + `NotificationSubscriptionMapper` + `NotificationSubscriptionsController` implements a per-user subscribe/unsubscribe table — the kind of preference-persistence layer this change is explicitly removing.
- `NotificationService` + `Notifier` already use `OCP\Notification\IManager` / `INotifier` correctly. Push (browser-closed pop-ups) already works because `notify_push` auto-intercepts `IManager` notifications — no separate push code is needed.

The user has two hard architectural constraints that this design must honour: notification **rules** live ONLY in the schema annotation (no rule table; evaluate from the always-loaded schema at dispatch time), and user **preferences** are override-only Nextcloud per-user app-config values (no preference/subscription table; unknown keys fall through to the schema default with zero migration).

## Goals / Non-Goals

**Goals:**
- Close the in-app/push dispatch gap: evaluate `x-openregister-notifications` rules directly from the loaded `Schema` at dispatch time and fan out `nc-notification` / `push` via `IManager`.
- Extend `Notifier::prepare()` to render `object_created`, `object_updated`, and an assignment/transition subject with nl+en i18n and an object-detail action link.
- Add an override-only, user-config-backed preferences read/write API (effective-GET, single-pair override-PUT) under app `openregister`.
- Make the dispatcher consult `schema-default ⊕ user-override` per recipient before delivering in-app/push.
- Preserve the zero-migration fall-through invariant: new schemas and new notifications work without any backfill.
- Deprecate the `NotificationSubscription` table + controller and migrate existing rows to user-config overrides.

**Non-Goals:**
- The nextcloud-vue user-settings preferences pane (separate nc-vue change — consumes this API; replaces the `<p>User preferences will appear here.</p>` placeholder in `CnAppRoot.vue` ~line 217).
- Per-app `x-openregister-notifications` default declarations (separate config changes in pipelinq + procest).
- New notification channels beyond in-app/push/webhook, batching/digest, rate limiting, history/audit tables, threshold/deadline triggers — those remain in the broader `notificatie-engine` spec backlog and are untouched here.
- Any new OR schema or DB table (this is engine code).

## Decisions

### Decision 1: Evaluate rules from the loaded schema, not a rule table
The dispatcher (the `AnnotationNotificationDispatcher` seam named in the engine spec) reads `configuration['x-openregister-notifications']` off the `Schema` already loaded for the object at the moment a lifecycle event fires, and fans out the in-app/push channels. **Why over a rule table:** the schema is always loaded when anything happens to an object, so a rule table would be a redundant second copy that drifts and demands migration on every schema edit. ADR-031 makes the annotation the declarative source of truth. The existing webhook-materialisation path stays as-is because `Webhook` entities are legitimately persistent delivery infrastructure (HMAC, retry, dead-letter) — that is delivery state, not rule state.

### Decision 2: Preferences are override-only Nextcloud user-config values
Store one entry per `(schema, notification-key)` override under app `openregister` via `IConfig::setUserValue`. Resolution is `effective = user-override ?? schema-default`. **Why over a table:** an override-only user-config model gives the zero-migration fall-through property for free — unknown keys simply have no stored value and resolve to the schema default. A `NotificationPreference` table would need a row (or a migration) per user per notification and would break the moment a schema adds a notification. **Alternative considered — one JSON blob per user vs one key per pair:** deferred (see Open Questions); provisional default is one user-config key per `(schema, notification-key)` pair, namespaced so a blob migration stays possible later.

### Decision 3: Render object subjects in the existing `AnnotationNotifier` (the actual handler), keep the two notifiers mutually exclusive by subject
The object-lifecycle subjects (`object_created`, `object_updated`, `object_transitioned`) are rendered in `lib/Notification/AnnotationNotifier.php` — localised via `IFactory::get('openregister', <languageCode>)`, with a primary action to the object detail route and the OR icon. **Why not `Notifier`:** OpenRegister already registers TWO `INotifier`s — `Notifier` (via `appinfo/info.xml`, handling `configuration_update_available`) and `AnnotationNotifier` (via `registerNotifierService`, the catch-all the dispatcher's notifications actually reach). Nextcloud's `Manager::prepare()` runs EVERY registered notifier sequentially over the same notification, so putting object-subject rendering in `Notifier` as well would let both notifiers touch the same notification (duplicate parsed subjects / actions). Instead the two are made **mutually exclusive by subject**: `AnnotationNotifier` owns object subjects (and anything carrying a pre-rendered `_text`) and raises `UnknownNotificationException` for everything else; `Notifier` keeps only `configuration_update_available`. The schema author's custom per-locale `subject` (passed as `_text`) wins; otherwise the canonical localised string is rendered. This is order-independent and avoids the collision a single-notifier assumption would have caused.

### Decision 4: Push needs no code, only a declared channel
The `push` channel is satisfied by `notify_push` auto-intercepting the same `IManager::notify()` call. So "support push" means: declare the channel in the annotation and route it through the same in-app delivery, gated by the same per-recipient merged preference. No push-specific service.

### Decision 5: Deprecate-then-remove the NotificationSubscription layer
Mark `NotificationSubscriptionsController` `@deprecated`, add a one-shot repair/migration that translates any existing `NotificationSubscription` rows into equivalent user-config overrides, and schedule table/mapper/controller removal. **Why not hard-remove now:** existing deployments may have rows + external callers; a deprecation window avoids data loss and lets the migration prove out. (Recorded as a DEFERRED_QUESTION — human-judgment call.)

## Declarative-vs-imperative decision (ADR-031)

| Concern | Declarative or imperative? | Rationale |
|---|---|---|
| **Notification rules** (who, on which event, which channels, recipient resolvers) | **Declarative** — `x-openregister-notifications` on the schema | ADR-031 names `x-openregister-notifications` as the declarative replacement for app-local NotificationService code. This change makes the annotation the ONLY rule store and evaluates it at dispatch time from the always-loaded schema. No `NotificationRule` service/table. |
| **Per-app rule content** (pipelinq new-lead → sales group; procest case-assigned → assignedTo) | **Declarative** — schema register patches in those repos | Per ADR-031 these are config changes (`kind: config`) in pipelinq/procest, NOT code here. |
| **The dispatcher + `Notifier::prepare()` rendering** | **Imperative (engine code) — justified exception** | This is the OR engine that *interprets* the declarative annotation and bridges to `IManager`/`INotifier`. ADR-031 §"What apps SHOULD still write in PHP" covers exactly this: the schema engine itself is PHP that the declarative metadata runs on. Rendering localised NC notifications + action links is framework-integration glue, not per-app business logic. |
| **The user-config preference merge** | **Imperative (engine code) — justified exception** | Resolving `schema-default ⊕ user-override` against `IConfig` is engine plumbing, not declarable behaviour. It belongs in OR so every consuming app inherits it uniformly (ADR-022). |

This change is `kind: code` (not `mixed`): the centre of mass is OR engine code (dispatcher, AnnotationNotifier, preferences API, deprecation migration). It declares no per-app schema content itself — that lands in the downstream pipelinq/procest config changes.

## Seed Data section (ADR-001)

**This change introduces NO new OpenRegister schemas and NO new DB tables**, so there is no `lib/Settings/{app}_register.json` seed-data addition and no seed-data implementation task. Instead, below are the canonical SHAPES this engine consumes — for documentation and test fixtures only. All values are SAFE placeholders (nil UUID `00000000-0000-0000-0000-000000000000`, `YOUR_*_HERE`); no realistic-looking secrets or UUIDs.

**Example `x-openregister-notifications` rule shape (lives on a schema's `configuration`, declared by the consuming app):**
```jsonc
{
  "x-openregister-notifications": {
    "object_created": {
      "event": "object.created",
      "enabled": true,                       // schema default
      "channels": ["nc-notification", "push"],
      "recipients": { "groups": ["YOUR_GROUP_HERE"] },
      "subject": {
        "nl": "Object \"%s\" aangemaakt in register \"%s\"",
        "en": "Object \"%s\" created in register \"%s\""
      }
    },
    "object_assigned": {
      "event": "object.updated",
      "enabled": true,
      "condition": { "field": "assignedTo", "operator": "changed" },
      "channels": ["nc-notification"],
      "recipients": { "field": "assignedTo" },
      "subject": {
        "nl": "Object \"%s\" aan je toegewezen",
        "en": "Object \"%s\" assigned to you"
      }
    }
  }
}
```

**Example user-config override key shape (app `openregister`, per-user, override-only):**
```
app:        openregister
userId:     YOUR_USER_ID_HERE
configKey:  notification_pref/<schemaSlug>/<notificationKey>
configValue (JSON):  {"enabled": false}                       // on/off only
            or:      {"enabled": true, "channels": ["nc-notification"]}  // optional channel narrowing
```
- Example concrete key: `notification_pref/meldingen/object_created` → `{"enabled": false}`.
- Schema reference in the key uses the schema slug (stable, human-readable); the nil-UUID `00000000-0000-0000-0000-000000000000` stands in wherever a UUID-shaped placeholder is needed in fixtures.
- **Absence of a key = use the schema default.** This is the zero-migration fall-through: no row, no migration, no backfill needed for new schemas or new notification keys.

## Risks / Trade-offs

- **Per-recipient preference resolution adds reads on the dispatch hot path** → Resolve via `IConfig::getUserValue` (already cached by NC) and read each recipient's override at most once per dispatch; only consult for the in-app/push channels (webhook is unaffected). Profile if a bulk event fans out to many recipients.
- **Schema slug as the key component is sensitive to slug renames** → A schema slug rename would orphan existing overrides (they fall through to default — fail-safe, never fail-open). Acceptable; documented. Keying by immutable schema UUID is the alternative captured in Open Questions.
- **Deprecating `NotificationSubscriptionsController` may break external callers** → Deprecation window + a one-shot migration of existing rows to user-config; removal scheduled, not immediate. Recorded as a DEFERRED_QUESTION.
- **Channel-level override semantics are under-specified** → Whether overrides may select channels (in-app vs push vs both) or only on/off is a DEFERRED_QUESTION; provisional design supports an optional `channels` field that narrows, defaulting to on/off when absent.
- **`x-openregister-notifications` rule schema is broader than this change implements** → This change wires in-app/push dispatch + the preference gate; advanced rule features (digest, threshold, rate-limit) remain in the engine backlog and are explicitly out of scope, so a rule declaring them must degrade gracefully (ignore unimplemented fields) rather than error.

## Migration Plan

1. Land the dispatcher in-app/push path + `Notifier::prepare()` subjects behind the existing annotation (no schema declares them in OR itself; downstream apps opt in).
2. Add the user-config preferences service + controller (effective-GET, override-PUT).
3. Wire the per-recipient merged-preference gate into the dispatcher.
4. Add a one-shot repair/migration translating any existing `NotificationSubscription` rows → user-config overrides; mark the controller `@deprecated`.
5. **Rollback:** the change is additive on the dispatch side (schemas must opt in via annotation) — disabling the dispatcher in-app/push path reverts to today's behaviour. The deprecation migration is read-then-write into user-config and can be re-run idempotently; the original rows are left in place during the deprecation window.

## Open Questions

See the DEFERRED_QUESTIONS list reported with this change. In brief: (1) deprecate vs hard-remove the `NotificationSubscription` table + controller; (2) one JSON blob per user vs one user-config key per `(schema, notification-key)` pair; (3) whether overrides may change the channel (in-app/push/both) or only on/off; (4) key by schema slug vs immutable schema UUID.
