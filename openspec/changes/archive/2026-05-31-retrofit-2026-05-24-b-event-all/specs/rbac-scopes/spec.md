## ADDED Requirements

### Requirement: Custom (non-canonical) action verbs MUST be resolvable via a voting event pair
When `PermissionHandler` evaluates an action that is NOT one of the canonical five (`read`, `create`, `update`, `delete`, `list`), it MUST dispatch a `CustomScopeEvaluatingEvent` so consuming apps that declare custom action verbs on a register can contribute a verdict. The verdict is first-vote-wins: the first listener to call `allow()` OR `deny()` decides, and subsequent votes are ignored so the outcome is deterministic regardless of listener registration order. When no listener votes, the handler MUST fall through to the standard rule chain. After a listener-driven verdict, a paired telemetry `CustomScopeEvaluatedEvent` MUST be dispatched for observers (audit, dashboards, analytics) without participating in the decision.

#### Scenario: Custom verb dispatches the evaluating event with full context
- **GIVEN** a register declares a custom action verb `approve` and a user `jan` (groups `["behandelaars"]`) attempts it on schema `besluiten`
- **WHEN** `PermissionHandler` evaluates the `approve` action
- **THEN** a `CustomScopeEvaluatingEvent` MUST be dispatched
- **AND** `getSchema()` MUST return the `besluiten` schema, `getAction()` MUST return `"approve"`, `getUserId()` MUST return `"jan"`, and `getUserGroups()` MUST return `["behandelaars"]`
- **AND** `getObject()` MUST return the target `ObjectEntity` when one was supplied, otherwise `null`

#### Scenario: First listener vote wins and short-circuits
- **GIVEN** two listeners are registered for `CustomScopeEvaluatingEvent`
- **WHEN** the first listener calls `allow()` and the second calls `deny()`
- **THEN** `getVerdict()` MUST return `true` (the first vote)
- **AND** `hasVerdict()` MUST return `true`
- **AND** the second listener's `deny()` MUST be ignored

#### Scenario: No listener votes falls through to the standard rule chain
- **GIVEN** no listener casts a verdict on the `CustomScopeEvaluatingEvent`
- **WHEN** evaluation completes
- **THEN** `hasVerdict()` MUST return `false` and `getVerdict()` MUST return `null`
- **AND** `PermissionHandler` MUST evaluate the action against the standard static rule chain
- **AND** no `CustomScopeEvaluatedEvent` MUST be dispatched (the standard rule-chain audit paths capture that outcome)

#### Scenario: Telemetry event reports the resolved verdict and its origin
- **GIVEN** a listener resolved a custom-scope evaluation to `true`
- **WHEN** the paired `CustomScopeEvaluatedEvent` is dispatched
- **THEN** `getVerdict()` MUST return `true` and `isFromListener()` MUST return `true`
- **AND** `getSchema()`, `getAction()`, and `getUserId()` MUST mirror the evaluating event's context
