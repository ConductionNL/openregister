# Design: add-approval-step-events

## Event Class Structure

All three event classes live under `OCA\OpenRegister\Event\` in `lib/Event/`. Each extends
`OCP\EventDispatcher\Event` (the Nextcloud base class that implements `IEvent`).

### `ApprovalStepInitiatedEvent`

```
ApprovalStepInitiatedEvent
  extends OCP\EventDispatcher\Event
  ─────────────────────────────────
  + __construct(
      ApprovalStep  $step,
      ApprovalChain $chain
    )
  + getStep(): ApprovalStep
  + getChain(): ApprovalChain
```

Dispatched when `initializeChain()` creates the first step in a chain and sets it to
`status: pending`. The event carries the newly persisted `ApprovalStep` (step order 1)
and the parent `ApprovalChain`. No actor is associated — the chain was created by the
service, not by a user decision.

### `ApprovalStepApprovedEvent`

```
ApprovalStepApprovedEvent
  extends OCP\EventDispatcher\Event
  ─────────────────────────────────
  + __construct(
      ApprovalStep  $step,
      ApprovalChain $chain,
      string        $userId,
      string        $comment = ''
    )
  + getStep(): ApprovalStep     // status is already 'approved'
  + getChain(): ApprovalChain
  + getUserId(): string
  + getComment(): string
```

Dispatched after `approveStep()` persists `status: approved`, `decidedBy`, `decidedAt` on
the step. The `$step` object returned by `getStep()` reflects the committed approved state.

### `ApprovalStepRejectedEvent`

```
ApprovalStepRejectedEvent
  extends OCP\EventDispatcher\Event
  ─────────────────────────────────
  + __construct(
      ApprovalStep  $step,
      ApprovalChain $chain,
      string        $userId,
      string        $comment = ''
    )
  + getStep(): ApprovalStep     // status is already 'rejected'
  + getChain(): ApprovalChain
  + getUserId(): string
  + getComment(): string
```

Dispatched after `rejectStep()` persists `status: rejected` on the step.

## Dispatch Timing in ApprovalService

Dispatch occurs **after** the persistence call returns and **before** `ApprovalService` returns
to its caller. The ordering within each method:

```
approveStep($stepId, $userId, $comment):
  1. Load ApprovalStep and ApprovalChain via their mappers
  2. Assert step status is 'pending' (throw on non-pending)
  3. Persist: step.status = 'approved', step.decidedBy = $userId,
              step.decidedAt = now(), step.comment = $comment
  4. Persist: advance next 'waiting' step to 'pending' (existing advance logic)
  5. $this->eventDispatcher->dispatchTyped(new ApprovalStepApprovedEvent($step, $chain, $userId, $comment))
  6. Return

rejectStep($stepId, $userId, $comment):
  1–3. Same pattern — persist 'rejected'
  4. (no next-step advance)
  5. $this->eventDispatcher->dispatchTyped(new ApprovalStepRejectedEvent($step, $chain, $userId, $comment))
  6. Return

initializeChain($chain, $objectUuid):
  1. Existing chain + step creation logic
  2. First step created with status 'pending'
  3. $this->eventDispatcher->dispatchTyped(new ApprovalStepInitiatedEvent($step1, $chain))
  4. Return
```

**Why after persistence:** listeners receive the committed state. A listener that calls
`GET /api/approval-steps/{id}` sees `status: approved`, not `status: pending`. This is the
same pattern used by OR's object lifecycle events (`ObjectCreatedEvent` etc.) and is required
by ADR-008 scenario test contracts.

## Payload Shape — PHP Class Diagram (prose)

Each event is a simple immutable value object:

- Constructor sets all fields.
- No setters — events are read-only after construction.
- No serialisation — events are PHP objects passed in-process to Nextcloud's `IEventDispatcher`.
  Consumers wanting persistence use OR's audit-trail or their own storage.
- `$comment` defaults to `''` (empty string) when the caller passed none. Consumers check
  `$event->getComment() !== ''` to detect a meaningful comment.

The `ApprovalStep` entity exposed via `getStep()` is the same mapper-fetched instance updated
by the persistence call. It is not cloned. If a listener modifies it, the modification is
visible to subsequent listeners. By convention, listeners MUST NOT call setters on the event
payload objects (read-only contract).

## ADR References

- **ADR-019** (Integration Registry) — OR's `IEventDispatcher` dispatch is the canonical
  integration point for consumers reacting to OR state changes. These three events register
  the `approval-step-events` capability in the integration registry, enabling consuming apps to
  declare their dependency via OR's `capabilities` manifest.
- **ADR-022** (Apps Consume OR Abstractions) — the event API replaces the polling fallback
  documented in the procest and docudesk migration designs. Consuming apps register
  `IEventListener` in their own `Application.php`; they do NOT copy OR's event classes.
- **ADR-008** (Testing Contract) — each event dispatch is covered by a unit test asserting
  the dispatcher was called with the correct event instance and payload; see Seed Data section
  for test fixtures.

## Seed Data

Per ADR-001 (Data Layer), seed data for events takes the form of test fixtures — representative
event payload instances used in unit and integration tests.

### Fixture 1 — ApprovalStepInitiatedEvent (chain start)

```json
{
  "event": "ApprovalStepInitiatedEvent",
  "step": {
    "id": 1,
    "chainId": 42,
    "objectUuid": "voorstel-abc",
    "role": "procest-teamleiders",
    "order": 1,
    "status": "pending",
    "decidedBy": null,
    "decidedAt": null,
    "comment": ""
  },
  "chain": {
    "id": 42,
    "name": "Parafeerroute Burgerzaken",
    "objectUuid": "voorstel-abc"
  }
}
```

### Fixture 2 — ApprovalStepApprovedEvent (step approved with delegation meta)

```json
{
  "event": "ApprovalStepApprovedEvent",
  "step": {
    "id": 1,
    "chainId": 42,
    "objectUuid": "voorstel-abc",
    "role": "procest-teamleiders",
    "order": 1,
    "status": "approved",
    "decidedBy": "jvandenberg",
    "decidedAt": "2026-05-11T09:30:00Z",
    "comment": "{\"text\":\"Akkoord\",\"_meta\":{\"actorType\":\"delegate\",\"onBehalfOf\":\"teamleider-a\",\"mandate\":\"M-2026-003\"}}"
  },
  "chain": {
    "id": 42,
    "name": "Parafeerroute Burgerzaken",
    "objectUuid": "voorstel-abc"
  },
  "userId": "jvandenberg",
  "comment": "{\"text\":\"Akkoord\",\"_meta\":{\"actorType\":\"delegate\",\"onBehalfOf\":\"teamleider-a\",\"mandate\":\"M-2026-003\"}}"
}
```

### Fixture 3 — ApprovalStepRejectedEvent (step rejected with plain comment)

```json
{
  "event": "ApprovalStepRejectedEvent",
  "step": {
    "id": 2,
    "chainId": 42,
    "objectUuid": "doc-xyz",
    "role": "docudesk-signers-a",
    "order": 1,
    "status": "rejected",
    "decidedBy": "mstoker",
    "decidedAt": "2026-05-11T10:15:00Z",
    "comment": "Niet akkoord met de inhoud"
  },
  "chain": {
    "id": 43,
    "name": "Ondertekeningsverzoek doc-xyz",
    "objectUuid": "doc-xyz"
  },
  "userId": "mstoker",
  "comment": "Niet akkoord met de inhoud"
}
```

These fixtures are used directly in `ApprovalStepApprovedEventTest`, `ApprovalStepRejectedEventTest`,
`ApprovalStepInitiatedEventTest`, and the `ApprovalServiceTest` dispatch assertions.

## Cross-References

- `procest/openspec/changes/migrate-parafering-to-or-approval-workflow` — procest's
  `ParaferingNotificationService` subscribes to `ApprovalStepApprovedEvent` and
  `ApprovalStepRejectedEvent` to send Nextcloud notifications.
- `docudesk/openspec/changes/migrate-signing-to-or-approval-workflow` — docudesk's
  `SigningService` subscribes to `ApprovalStepInitiatedEvent` and `ApprovalStepApprovedEvent`
  to invoke the configured signing provider when a step becomes actionable.
- `hydra/openspec/changes/consume-or-approval-workflow-fleet-wide` — umbrella policy spec
  that references "the `ApprovalStep approved` event" throughout; this change fulfils that
  reference.
