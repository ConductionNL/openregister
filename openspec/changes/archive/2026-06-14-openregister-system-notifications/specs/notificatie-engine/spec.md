## MODIFIED Requirements

### Requirement: Notification rules MUST be sourced ONLY from the schema annotation, evaluated at dispatch time
The system MUST treat `configuration['x-openregister-notifications']` on the `Schema` as the single, authoritative source of notification rules. Because the schema is ALWAYS loaded whenever anything happens to an object, the dispatcher MUST evaluate the notification rules directly from the already-loaded schema annotation at dispatch time. The system MUST NOT persist notification rules in any separate rule table, and MUST NOT introduce a `NotificationRule` entity or `oc_openregister_notification_rules` table. (ADR-031: `x-openregister-notifications` is the declarative replacement for app-local notification service code.)

OpenRegister's **own system schemas** (`register`, `schema`, `configuration`, `source`, `synchronization`, `import`, `webhook`, `agent`) MUST also be able to declare `x-openregister-notifications` rules for operational events. The dispatcher MUST resolve rules for a system entity through the same annotation-sourced path it uses for stored register objects — either by representing the system entities as schema-backed objects whose schema carries the annotation, or by a system-schema rule source that returns the same rule shape — so that no separate notification-rule table is introduced for system schemas either.

#### Scenario: Dispatcher reads rules from the loaded schema, not a rule table
- **WHEN** an object lifecycle event fires for an object whose schema declares an `x-openregister-notifications` rule on `object.created`
- **THEN** the dispatcher MUST evaluate that rule from the schema annotation already loaded for the object
- **AND** the system MUST NOT query any notification-rule table (none exists)

#### Scenario: Editing the schema annotation changes dispatch behaviour immediately
- **WHEN** an administrator updates `x-openregister-notifications` on a schema to add a new `object.updated` rule and saves the schema
- **THEN** the next `object.updated` event on that schema MUST be evaluated against the new rule
- **AND** no rule-table row creation, migration, or rebuild step is required for the change to take effect

#### Scenario: A system schema declares rules sourced through the same annotation path
- **GIVEN** OpenRegister's `synchronization` system schema declares an `x-openregister-notifications` rule for its failure event
- **WHEN** a synchronization run fails
- **THEN** the dispatcher MUST resolve that rule through the annotation-sourced path (schema-backed object or system-schema rule source), NOT from any notification-rule table
- **AND** the system MUST NOT introduce a notification-rule table for system schemas

### Requirement: The notification engine MUST support event-driven trigger types beyond CRUD
Notifications MUST be triggerable by workflow events, threshold alerts, scheduled checks, and external triggers in addition to standard object CRUD events.

The `updated` trigger MUST additionally accept an optional non-numeric field-change `condition` block, evaluated against the old-versus-new object data the dispatch already supplies for `calculatedChange`. The block names a single `field` and one `operator`:

- `{"field": "status", "operator": "changed"}` — the rule fires only when the field's value differs between the old and new object data (old ≠ new).
- `{"field": "status", "operator": "equals", "value": "<target>"}` — the rule fires only when the new value equals `value`.
- `{"field": "status", "operator": "equals", "value": "<target>", "from": "<prior>"}` — the optional `from` additionally requires the old value to equal `<prior>`, so the rule fires only on the specific `<prior>` → `<target>` transition.

The evaluator MUST fail closed: when the old-versus-new object data is unavailable in the dispatch context, a `condition`-bearing `updated` rule MUST NOT fire — consistent with the existing `calculatedChange` behaviour. An `updated` rule that declares NO `condition` MUST continue to fire on every update (back-compatible). The non-numeric field-change condition is evaluated by a string-condition evaluator distinct from the existing numeric `calculatedChange` evaluator; numeric `calculatedChange` semantics are unchanged.

The engine MUST additionally be able to dispatch these trigger types for OpenRegister's **own system entities** (`synchronization`, `import`, `schema`, `configuration`, `source`, `agent`, `webhook`, `register`). A system-event bridge MUST route create/update/transition signals from the relevant system entities through the same `AnnotationNotificationListener` → dispatcher path used for stored register objects, populating the old-versus-new object data so the field-change `condition` block is available for system schemas as well. Operational notifications on system schemas MUST reuse the existing channels, recipient resolvers, rate-limiting, coalescing, per-user preference overrides, and bilingual (nl/en) i18n unchanged; only the rule source and event source are extended to cover system schemas.

#### Scenario: Workflow completion triggers notification
- GIVEN an n8n workflow `vergunning-beoordeling` completes with output `{"result": "goedgekeurd"}`
- AND a notification rule listens for event `workflow.completed` with condition `{"workflowName": "vergunning-beoordeling"}`
- WHEN the workflow completes
- THEN a notification MUST be sent to the assignee with message: `Vergunning {{object.title}} is goedgekeurd`

#### Scenario: Threshold alert triggers notification
- GIVEN a notification rule with trigger type `threshold`:
  - `schema`: `meldingen`
  - `condition`: `{"aggregate": "count", "operator": ">=", "value": 100, "period": "24h"}`
  - `template`: `Waarschuwing: {{count}} meldingen in de afgelopen 24 uur`
- WHEN the 100th melding is created within 24 hours
- THEN a threshold notification MUST be sent to the configured recipients
- AND the notification MUST include the actual count

#### Scenario: SLA deadline approaching triggers notification
- GIVEN a notification rule with trigger type `deadline`:
  - `schema`: `vergunningen`
  - `condition`: `{"field": "deadline", "operator": "before", "offset": "-48h"}`
  - `template`: `Vergunning "{{object.title}}" nadert deadline ({{object.deadline}})`
- WHEN a background job detects that object `vergunning-1` has a deadline within 48 hours
- THEN a notification MUST be sent to `object.assignedTo` with the deadline warning

#### Scenario: External system triggers notification via API
- GIVEN notification rule 15 is configured to accept external triggers
- WHEN an external system calls `POST /api/notification-rules/15/trigger` with payload `{"objectUuid": "abc-123", "message": "Externe update ontvangen"}`
- THEN a notification MUST be sent to the rule's recipients with the provided message

#### Scenario: updated trigger with `changed` condition fires only when the field value differs
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "changed"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "closed"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST fire because the old value (`open`) differs from the new value (`closed`)

#### Scenario: updated trigger with `equals` condition fires only when the new value matches
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "equals", "value": "closed"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "closed"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST fire because the new value equals `closed`
- AND GIVEN instead a new object data of `{"status": "pending"}`, the rule MUST NOT fire

#### Scenario: System synchronization failure dispatches an operational notification
- GIVEN OpenRegister's `synchronization` system schema declares an `x-openregister-notifications` rule that fires on the synchronization-failed event (via `transition`→`failed` or `updated`+`condition` `{"field":"status","operator":"equals","value":"failed"}`) with recipients `{"kind":"groups","groups":["admin"]}`
- WHEN a synchronization run transitions to `failed`
- THEN the system-event bridge MUST route the failure through the same listener/dispatcher path used for stored register objects
- AND a notification MUST be delivered to the configured admin/integration-ops group on the configured channel
- AND the subject MUST be metadata-only (no synchronization payload contents) and available in both `nl` and `en`

#### Scenario: System schema/configuration change dispatches an operational notification
- GIVEN OpenRegister's `configuration` system schema declares an `updated` rule with recipients `{"kind":"groups","groups":["admin"]}`
- WHEN a configuration record is updated
- THEN the system-event bridge MUST dispatch the rule through the existing dispatcher path
- AND a notification MUST be delivered to the admin group, reusing the existing rate-limiting, coalescing and per-user preference-override behaviour unchanged

#### Scenario: System source/agent health threshold dispatches an operational notification
- GIVEN OpenRegister's `source` system schema declares a `threshold` rule on consecutive failures (or an `updated`+`condition` rule on a health field)
- WHEN the source becomes unhealthy per the configured threshold/condition
- THEN a notification MUST be delivered to the configured integration-ops group
- AND the dispatch MUST reuse the existing threshold/condition evaluation, numeric `calculatedChange` semantics being unchanged
