---
status: draft
---
# Notifications Annotation (delta — notificatie-engine)

## Purpose
Add a declarative `x-openregister-notifications` schema annotation that auto-registers Webhook entities + notification rules through the implemented `notificatie-engine`'s machinery. No new dispatcher, no new channel adapters — the existing `WebhookEventListener` + `WebhookDeliveryJob` + `Notifier` + `RecipientResolver` chain does the actual delivery.

## ADDED Requirements

### Requirement: Schemas MAY declare notifications via `x-openregister-notifications`
A schema MAY include a top-level `x-openregister-notifications` block: a map of notification name → spec. Each spec declares `trigger` (type + parameters), `filter` (Mongo-style operators against the triggering object), `recipients` (one or more recipient blocks), `channels` (one or more channel blocks), optional `throttle`, optional `audit: bool`. Schema-save validation MUST verify every reference and reject malformed annotations with HTTP 422.

#### Channel block format (normative)

Every entry in `channels[]` MUST be an object with exactly one mandatory field — `kind` — whose value is one of `nc-notification`, `email`, `webhook`, `talk`, `activity`. The remaining fields are kind-dependent:

| `kind` | Required fields | Optional fields | Notes |
|---|---|---|---|
| `nc-notification` | (none) | `subjectKey`, `messageKey`, `iconUrl`, `link`, `priority` (low/normal/high) | i18n keys resolve via the existing `Notifier` template registry. |
| `email` | (none) | `subjectKey`, `bodyTemplateKey`, `replyTo`, `senderKey` | SMTP config comes from NC; templates are i18n keys. The annotation MUST NOT inline raw email bodies. |
| `webhook` | `webhookId` (UUID of an existing `Webhook` entity registered by an admin) | `mappingKey` (template override) | The target URL MUST come from the existing Webhook entity registry — non-admin schema authors MUST NOT be able to set arbitrary URLs in the annotation, to prevent SSRF. Schema-save validation MUST reject `url:` directly inline in a channel block with `{ code: "notification-channel-webhook-inline-url-forbidden" }`. |
| `talk` | `room` (NC Talk conversation id or token) | `messageKey` | Resolves via `OCA\Talk\Manager`. Validation MUST verify the room exists at install time (best effort — re-checks at delivery). |
| `activity` | (none) | `subjectKey`, `objectType`, `objectName` | Routes through `OCP\Activity\IManager`; the existing `activity-provider` integration consumes it. |

A schema MAY declare more than one channel block per notification (e.g. send both email and an `nc-notification`). Validation MUST reject unknown keys, missing mandatory fields, or unsupported `kind` values with HTTP 422.

#### End-to-end annotation example

```yaml
x-openregister-notifications:
  decisionPublished:
    trigger:
      type: transition
      transition: publish
    filter:
      visibility: { $eq: "public" }
    recipients:
      - kind: relation
        relation: stakeholders
      - kind: groups
        groups: [cabinet-readers]
    channels:
      - kind: email
        subjectKey: notif.decision.published.subject
        bodyTemplateKey: notif.decision.published.body
      - kind: nc-notification
        subjectKey: notif.decision.published.short
        link: "/apps/decidesk/decision/{{object.id}}"
    throttle:
      perRecipient: "1 per day"
    audit: true
```

#### Scenario: Trigger type referenced is in the supported set
- GIVEN a notification with `trigger: { type: "transition", transition: "publish" }`
- WHEN the schema is saved
- THEN validation MUST accept it (transition is a supported trigger type)
- WHEN a notification declares `trigger: { type: "blockchain-anchor" }` (not supported)
- THEN validation MUST fail with `{ code: "notification-trigger-unknown", type: "blockchain-anchor" }`

#### Scenario: Transition trigger requires an existing transition action
- GIVEN a notification with `trigger: { type: "transition", transition: "publish" }`
- AND the schema declares `x-openregister-lifecycle.transitions` containing `publish`
- WHEN the schema is saved
- THEN validation MUST resolve the transition reference and accept it
- WHEN the same notification references `trigger.transition: "teleport"` (not declared)
- THEN validation MUST fail with `{ code: "notification-transition-missing", transition: "teleport" }`

#### Scenario: `created` trigger fires on object creation; filters see the new state only
- GIVEN a notification with `trigger: { type: "created" }` and `filter: { taskStatus: "open" }`
- AND a new action item is created with `taskStatus: "open"`
- WHEN `ObjectCreatedEvent` fires
- THEN the installer-mapped listener MUST evaluate the filter against the created object's payload (there is no "before" state; placeholder `$before.*` MUST resolve to `null` and validation MUST reject filters that require a non-null `$before`)
- AND the notification MUST dispatch to all resolved recipients

#### Scenario: `updated` trigger MAY filter on a field-diff (`only_if_changed`)
- GIVEN a notification with `trigger: { type: "updated", only_if_changed: ["assignee"] }`
- AND an existing action item is updated, changing `assignee` from `alice` to `bob`
- WHEN `ObjectUpdatedEvent` fires
- THEN the listener MUST compare the listed fields between before/after state
- AND fire the notification (because `assignee` changed)
- WHEN the same item is later updated, changing only `description`
- THEN the listener MUST NOT fire (no listed field changed)
- AND when `only_if_changed` is omitted, the trigger fires on every update
- AND consecutive saves of the same object within 1 second SHOULD be deduplicated (the existing throttle store with implicit `perObject: 1 per second` baseline is the intended mechanism)

### Requirement: The installer MUST create Webhook entities at schema-save time
On schema save, the implementation MUST create or update a Webhook entity (using the existing `WebhookMapper`) per declared notification. The webhook's `events` field MUST be derived from the trigger type (transition → `ObjectTransitionedEvent` for the matching action; created → `ObjectCreatedEvent`; etc.). The webhook's `mapping` reference MUST be filled in with the template's i18n key. The webhook MUST be tagged with the notification name + schema id for idempotent re-installs.

#### Scenario: Saving a schema with notifications creates webhooks
- GIVEN a schema `decision` with one notification `decisionPublished` (trigger: transition publish)
- WHEN the schema is saved
- THEN exactly one Webhook entity MUST exist with `(name: "decisionPublished", schemaId: <decision-id>, events: ["object.transitioned"])`
- AND the webhook's `mapping` reference MUST point at the i18n template key declared in the annotation

#### Scenario: Re-saving the same schema is idempotent
- GIVEN a schema with `decisionPublished` already installed (one webhook exists)
- WHEN the schema is saved again with no annotation changes
- THEN the webhook count MUST remain exactly 1 (the installer recognises it by name+schema id and skips)

#### Scenario: Removing a notification from the annotation removes the webhook
- GIVEN a schema where `decisionPublished` was declared and is now removed
- WHEN the schema is saved without the annotation
- THEN the webhook tagged with that name+schema id MUST be deleted

### Requirement: Recipients MUST be resolvable through the existing services
The installer MUST translate recipient blocks into uid lists at delivery time using existing OpenRegister + Nextcloud services:

| Recipient kind | Source |
|---|---|
| `users` | literal `uids` array from the annotation |
| `groups` | `IGroupManager::getUsersFromGroup` for each named group |
| `field` | the named field on the triggering object (must be a uid string) |
| `relation` | walk the existing `x-openregister-relations` graph to find related objects, then take their owners (or a named field on the related object) |
| `object-acl` | existing OpenRegister per-object ACL lookup, filtered by the named permission |
| `expression` | a DI-tagged service implementing `RecipientExpressionInterface` |

#### Scenario: Group recipient resolves to group members
- GIVEN a notification with `recipients: [{kind: "groups", groups: ["cabinet"]}]`
- AND the `cabinet` NC group has 5 members
- WHEN the trigger fires for an object
- THEN delivery MUST be attempted to all 5 members
- AND duplicate members across multiple recipient blocks MUST be deduplicated before delivery

#### Scenario: Object-ACL recipient honors per-object permissions
- GIVEN a notification with `recipients: [{kind: "object-acl", permission: "write"}]`
- AND a meeting object with 3 users having `write` permission and 12 having `read` permission
- WHEN the trigger fires
- THEN delivery MUST go to the 3 write-permission users only

### Requirement: Scheduled triggers MUST run via Nextcloud BackgroundJob
For `trigger.type: "scheduled"`, the installer MUST register a `BackgroundJob` (via NC's `IJobList`) that runs on the declared cron expression, executes the trigger's `filter` as a `findObjects` query, and dispatches the notification per matching object.

#### Scenario: Scheduled trigger runs daily and notifies overdue items
- GIVEN a notification with `trigger: { type: "scheduled", cron: "0 9 * * *", filter: { taskStatus: {$ne: "completed"}, dueDate: {$lt: "$now"} } }`
- AND 3 action items match the filter at 09:00 UTC
- WHEN the cron job fires at 09:00 UTC
- THEN the recipient resolver MUST run once per matching object
- AND the notification MUST be dispatched per (object, recipient) pair through the existing `Notifier`

### Requirement: Threshold triggers MUST subscribe to aggregation cache invalidation
For `trigger.type: "threshold"`, the installer MUST register a listener on the aggregation cache invalidation event (declared by `aggregations-and-calculations`); when the referenced aggregation crosses the declared threshold (op + value), the notification fires.

#### Scenario: Threshold notification fires when aggregation crosses
- GIVEN a notification with `trigger: { type: "threshold", aggregation: "totalOverdue", op: ">", value: 10 }`
- AND the `totalOverdue` aggregation reads `8` (below threshold)
- WHEN a write fires the aggregation cache invalidation
- AND the recomputed value is `11` (above threshold)
- THEN the notification MUST fire exactly once for the crossing event
- AND a subsequent invalidation that keeps the value above 10 MUST NOT fire again (only crossings, not steady-state)

### Requirement: Throttling reuses the existing notificatie-engine throttle store
A notification's optional `throttle` block (`perRecipient`, `perObject`, `global`) MUST be enforced through the existing throttle store in `notificatie-engine`. The annotation installer MUST translate the declared windows into the existing throttle entry shape.

**Throttle window grammar (normative).** Each throttle value MUST match the regex `^([1-9][0-9]*) per (second|minute|hour|day|week)$` (count + literal `per` + unit). Whitespace between tokens is exactly one ASCII space. Schema-save validation MUST reject any other format with HTTP 422 and `{ code: "notification-throttle-invalid-window", value: "<input>", expected: "{N} per {second|minute|hour|day|week}" }`. ISO-8601 durations (`PT24H` etc.) are NOT accepted in v1 — implementations MAY add ISO-8601 in v2 but MUST keep the v1 grammar working unchanged.

#### Scenario: PerRecipient throttle suppresses duplicates within the window
- GIVEN a notification with `throttle: { perRecipient: "1 per day" }`
- AND user `alice` was notified 2 hours ago
- WHEN the same notification fires for the same object for `alice` again
- THEN the existing throttle store MUST suppress the duplicate
- AND the audit trail MUST record `{ status: "suppressed-by-throttle", reason: "perRecipient" }`

### Requirement: Audit trail MUST record dispatch outcomes
With `audit: true` (default), every dispatch MUST write one entry to OpenRegister's audit trail per (notification, object, recipient, channel) with `{ sentAt, status: "delivered" | "failed" | "suppressed-by-throttle" }`. This makes "who got notified about what" queryable.

#### Scenario: Successful dispatch logs to audit trail
- GIVEN a notification fires for `alice` over the `email` channel
- AND the email is accepted by the SMTP relay
- WHEN the dispatch completes
- THEN exactly one audit-trail entry MUST exist with `{ notification, object, recipient: "alice", channel: "email", status: "delivered", sentAt: <iso> }`
