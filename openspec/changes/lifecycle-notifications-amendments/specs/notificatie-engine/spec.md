---
status: draft
---
# Notificatie-engine (delta — `x-openregister-notifications` annotation surface)

## Purpose

Graduate the notifications-annotation refinements from the
archived `2026-04-29-notifications-annotation` change directory
into the canonical `notificatie-engine` spec. Pins the channel
block format and throttle window grammar that are referenced
elsewhere (platform-capabilities catalog, hydra ADRs) but are
currently only documented inside frozen archive paths.

## ADDED Requirements

### Requirement: Schemas MAY declare notifications via `x-openregister-notifications`

A schema MAY include a top-level `x-openregister-notifications`
block: a map of notification name → spec. Each spec declares
`trigger` (type + parameters), `filter` (Mongo-style operators
against the triggering object), `recipients` (one or more
recipient blocks), `channels` (one or more channel blocks),
optional `throttle`, optional `audit: bool`. Schema-save
validation MUST verify every reference and reject malformed
annotations with HTTP 422.

#### Channel block format (normative)

Every entry in `channels[]` MUST be an object with exactly one
mandatory field — `kind` — whose value is one of
`nc-notification`, `email`, `webhook`, `talk`, `activity`. The
remaining fields are kind-dependent:

| `kind` | Required fields | Optional fields | Notes |
|---|---|---|---|
| `nc-notification` | (none) | `subjectKey`, `messageKey`, `iconUrl`, `link`, `priority` (low/normal/high) | i18n keys resolve via the existing `Notifier` template registry. |
| `email` | (none) | `subjectKey`, `bodyTemplateKey`, `replyTo`, `senderKey` | SMTP config comes from NC; templates are i18n keys. The annotation MUST NOT inline raw email bodies. |
| `webhook` | `webhookId` (UUID of an existing `Webhook` entity registered by an admin) | `mappingKey` (template override) | The target URL MUST come from the existing Webhook entity registry — non-admin schema authors MUST NOT be able to set arbitrary URLs in the annotation, to prevent SSRF. Schema-save validation MUST reject `url:` directly inline in a channel block with `{ code: "notification-channel-webhook-inline-url-forbidden" }`. |
| `talk` | `room` (NC Talk conversation id or token) | `messageKey` | Resolves via `OCA\Talk\Manager`. Validation MUST verify the room exists at install time (best effort — re-checks at delivery). |
| `activity` | (none) | `subjectKey`, `objectType`, `objectName` | Routes through `OCP\Activity\IManager`; the existing `activity-provider` integration consumes it. |

A schema MAY declare more than one channel block per notification
(e.g. send both email and an `nc-notification`). Validation MUST
reject unknown keys, missing mandatory fields, or unsupported
`kind` values with HTTP 422.

#### Scenario: Webhook channel with inline URL is rejected
- GIVEN a notification declares `channels: [{ kind: "webhook", url: "https://attacker.example.com/x" }]`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "notification-channel-webhook-inline-url-forbidden" }`

#### Scenario: Webhook channel referencing a registered entity is accepted
- GIVEN an admin has registered a `Webhook` entity with UUID `abc-123` and target URL `https://allowed.example.com/hook`
- AND a notification declares `channels: [{ kind: "webhook", webhookId: "abc-123" }]`
- WHEN the schema is saved
- THEN the save MUST succeed
- AND delivery MUST POST to the URL stored on the registered Webhook entity, NOT to a URL supplied by the schema author

### Requirement: Throttle window grammar (normative)

A notification's optional `throttle` block MAY declare
`perRecipient`, `perObject`, and / or `global` windows. Each
throttle value MUST match the regex
`^([1-9][0-9]*) per (second|minute|hour|day|week)$`
(count + literal `per` + unit). Whitespace between tokens is
exactly one ASCII space. Schema-save validation MUST reject any
other format with HTTP 422 and
`{ code: "notification-throttle-invalid-window", value: "<input>", expected: "{N} per {second|minute|hour|day|week}" }`.
ISO-8601 durations (`PT24H` etc.) are NOT accepted in v1 —
implementations MAY add ISO-8601 in v2 but MUST keep the v1
grammar working unchanged.

#### Scenario: Valid throttle window is accepted
- GIVEN a notification with `throttle: { perRecipient: "1 per day" }`
- WHEN the schema is saved
- THEN validation MUST accept it

#### Scenario: ISO-8601 duration is rejected in v1
- GIVEN a notification with `throttle: { perRecipient: "PT24H" }`
- WHEN the schema is saved
- THEN the save MUST fail with HTTP 422
- AND the response body MUST include `{ code: "notification-throttle-invalid-window", value: "PT24H", expected: "{N} per {second|minute|hour|day|week}" }`

### Requirement: Trigger types `created` and `updated` MUST be supported

The trigger registry MUST recognise `created` and `updated`
trigger types (in addition to `transition`, `scheduled`, and
`threshold` documented elsewhere).

#### Scenario: `created` trigger fires on object creation; filters see the new state only
- GIVEN a notification with `trigger: { type: "created" }` and `filter: { taskStatus: "open" }`
- AND a new action item is created with `taskStatus: "open"`
- WHEN `ObjectCreatedEvent` fires
- THEN the installer-mapped listener MUST evaluate the filter against the created object's payload (there is no "before" state)
- AND `$before.*` placeholder MUST resolve to `null` and validation MUST reject filters that require a non-null `$before`
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
