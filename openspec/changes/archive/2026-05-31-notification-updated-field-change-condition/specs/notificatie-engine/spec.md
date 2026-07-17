## MODIFIED Requirements

### Requirement: The notification engine MUST support event-driven trigger types beyond CRUD
Notifications MUST be triggerable by workflow events, threshold alerts, scheduled checks, and external triggers in addition to standard object CRUD events.

The `updated` trigger MUST additionally accept an optional non-numeric field-change `condition` block, evaluated against the old-versus-new object data the dispatch already supplies for `calculatedChange`. The block names a single `field` and one `operator`:

- `{"field": "status", "operator": "changed"}` — the rule fires only when the field's value differs between the old and new object data (old ≠ new).
- `{"field": "status", "operator": "equals", "value": "<target>"}` — the rule fires only when the new value equals `value`.
- `{"field": "status", "operator": "equals", "value": "<target>", "from": "<prior>"}` — the optional `from` additionally requires the old value to equal `<prior>`, so the rule fires only on the specific `<prior>` → `<target>` transition.

The evaluator MUST fail closed: when the old-versus-new object data is unavailable in the dispatch context, a `condition`-bearing `updated` rule MUST NOT fire — consistent with the existing `calculatedChange` behaviour. An `updated` rule that declares NO `condition` MUST continue to fire on every update (back-compatible). The non-numeric field-change condition is evaluated by a string-condition evaluator distinct from the existing numeric `calculatedChange` evaluator; numeric `calculatedChange` semantics are unchanged.

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

#### Scenario: updated trigger with `changed` condition does not fire when the field value is unchanged
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "changed"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "open"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST NOT fire because the old value equals the new value

#### Scenario: updated trigger with `equals` condition fires only when the new value matches
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "equals", "value": "closed"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "closed"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST fire because the new value equals `closed`
- AND GIVEN instead a new object data of `{"status": "pending"}`, the rule MUST NOT fire

#### Scenario: updated trigger with optional `from` requires the prior value
- GIVEN an `updated` rule whose `trigger` declares `condition` `{"field": "status", "operator": "equals", "value": "closed", "from": "open"}`
- AND the dispatch context carries the old object data `{"status": "open"}` and the new object data `{"status": "closed"}`
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST fire because the new value equals `closed` AND the old value equals `open`
- AND GIVEN instead an old object data of `{"status": "pending"}`, the rule MUST NOT fire because the prior value does not equal `open`

#### Scenario: condition-bearing updated rule fails closed when old/new data is unavailable
- GIVEN an `updated` rule whose `trigger` declares any field-change `condition`
- AND the dispatch context does NOT carry the old and new object data (e.g. no previous object was available)
- WHEN the dispatcher evaluates the rule
- THEN the rule MUST NOT fire, matching the fail-closed behaviour of `calculatedChange`

#### Scenario: updated rule with no condition still fires on every update
- GIVEN an `updated` rule whose `trigger` declares NO `condition` block
- WHEN any update occurs on the object
- THEN the rule MUST fire on every update, preserving backwards compatibility with existing condition-less rules
