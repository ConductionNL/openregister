# Notifications v2 — delta on `notificatie-engine`

This delta extends the `notificatie-engine` capability with the v2 features carved out of the v1 `notifications-annotation` change shipped 2026-04-29.

### Requirement: Schemas MAY declare a `scheduled` notification trigger

A schema MAY include a notification with `trigger: { type: "scheduled", cron: "<5-field cron>", filter: <findObjects-shape> }`. When present, the platform MUST register a `BackgroundJob` keyed on `(schemaId, notificationName)` at schema-save; the job MUST run on the cron schedule, execute the filter as a `findObjects` query, and dispatch the notification once per matching object.

#### Scenario: scheduled trigger fires per matching object on cron
- GIVEN a Meeting schema with `meetingReminderDaily: { trigger: {type:"scheduled", cron:"0 9 * * *", filter:{lifecycle:"scheduled"}}, recipients:[{kind:"field", field:"chair"}], channels:["nc-notification"], subject:"Reminder: {{title}} starts soon" }`
- AND three meetings have `lifecycle === "scheduled"`
- WHEN the BackgroundJob runs on its scheduled cycle
- THEN each meeting's chair receives one `nc-notification` with the interpolated subject

### Requirement: Schemas MAY declare a `threshold` notification trigger

A schema MAY include a notification with `trigger: { type: "threshold", aggregation: "<name>", op: "gt"|"gte"|"lt"|"lte"|"eq", value: <number> }`. The platform MUST subscribe to the aggregation cache invalidation event; when the referenced aggregation crosses the threshold (per op + value) compared to the previous cached value, the notification MUST fire once with `context: { aggregation, previousValue, newValue }`.

#### Scenario: threshold fires only on crossing edge
- GIVEN a notification `tooManyOverdue: { trigger: {type:"threshold", aggregation:"totalOverdue", op:"gt", value:10}, recipients:[{kind:"groups", groups:["admin"]}], channels:["nc-notification"], subject:"{{newValue}} overdue items" }`
- AND `totalOverdue` is currently 8
- WHEN a save makes `totalOverdue` recompute to 12
- THEN the notification fires exactly once
- AND `subject_rich._text` resolves to `12 overdue items`
- AND a subsequent save that recomputes `totalOverdue` to 11 does NOT fire (still above threshold; no crossing)

### Requirement: Schemas MAY declare a persistent webhook channel

A schema MAY include a notification with `channels: ["webhook"]`, `webhook.persistent: true`, `webhook.events: [...]`. When `persistent === true`, the platform MUST upsert a `Webhook` entity at schema-save (idempotent on schema name + notification name); subsequent dispatches MUST go through the existing `WebhookService::dispatchEvent` pipeline (which already provides retry, HMAC, dead-letter, multi-tenancy).

The v1 fire-and-forget HTTP POST path remains the default when `webhook.persistent !== true`.

### Requirement: Schemas MAY declare `object-acl` and `expression` recipient kinds

`recipient.kind: "object-acl"` MUST resolve to every uid in the object's ACL holding the declared `permission` value. `recipient.kind: "expression"` MUST resolve via a DI-tagged `RecipientResolverInterface` implementation registered by an app.

#### Scenario: object-acl recipient walks per-object ACL
- GIVEN an object with ACL { alice: read, bob: manage, charlie: read }
- AND a notification with `recipients: [{kind:"object-acl", permission:"read"}]`
- WHEN the notification fires
- THEN alice, bob, AND charlie each receive it (manage implies read)

#### Scenario: expression recipient resolves via DI tag
- GIVEN an app registers `decidesk.notif.escalation` as a `RecipientResolverInterface` implementation that returns `[supervisor1, supervisor2]` for any meeting object
- AND a notification with `recipients: [{kind:"expression", resolver:"decidesk.notif.escalation"}]`
- WHEN the notification fires
- THEN supervisor1 and supervisor2 each receive it

### Requirement: Schemas MAY declare a `talk` channel

A schema MAY include `channels: ["talk"]` and `talk: { token: "<conversation-id>" }`. The platform MUST POST the rendered subject as a single chat message to `/ocs/v2.php/apps/spreed/api/v1/chat/{token}` per dispatch (not per recipient). When the Talk app is not enabled, the dispatch MUST silently skip the talk channel.
