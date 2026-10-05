## ADDED Requirements

### Requirement: Flows send through the notification subsystem, never beside it

The engine SHALL provide three step node types —
`openregister.send-notification`, `openregister.send-email` and
`openregister.send-talk-message` — registered through
`RegisterFlowNodesEvent` like every other built-in, each declaring a config
form.

Every send SHALL go through the SAME channel machinery the ADR-031
declarative subsystem uses: the channel senders behind
`AnnotationNotificationDispatcher` (`nc-notification`, `email`, `talk`), its
recipient resolution, and its guardrails. The flow node is an
orchestration-time INVOKER of those units; it SHALL NOT carry its own mailer,
its own Talk client, or its own recipient resolver. One channel
implementation, two callers — a schema annotation answering "when X happens"
and a flow node answering "at this point in the process".

Message templating SHALL use the notification dialect's placeholder syntax,
evaluated against the flow item, so a body reads the same whether it is
declared on a schema or on a node. The node SHALL NOT introduce a second
placeholder syntax for the same job.

A user's notification channel preferences SHALL be honoured for flow sends
on the per-recipient channels exactly as for declarative ones: a flow does
not out-rank the recipient's own settings.

#### Scenario: A flow node and a schema annotation produce the same send

- **GIVEN** a schema annotation and a send-notification node configured with
  the same recipients and the same template
- **WHEN** each fires against the same object
- **THEN** the delivered notifications MUST be identical in channel, body and
  recipients
- **AND** both MUST have passed through the same channel sender
- @e2e exclude covered by a unit test dispatching both paths against a
  recorded sender double

#### Scenario: A recipient's channel preference is respected

- **GIVEN** a user who disabled the email channel in their notification
  preferences
- **WHEN** a send-email node lists that user as a recipient
- **THEN** no email MUST be sent to them
- **AND** the run log MUST record the recipient as skipped by preference,
  not as failed
- @e2e exclude covered by FlowMessagingService unit tests

#### Scenario: Items flow through unchanged

- **GIVEN** a send node between two other steps
- **WHEN** it executes with three items
- **THEN** it MUST return the three items unchanged for downstream steps —
  sending is a side effect, not a transformation
- @e2e exclude covered by the node's unit tests

### Requirement: Flow sends are attributed, logged and bounded

A send performed by a flow SHALL be attributed to the flow run's ACTING user
— the user the run executes as — never to a system or service identity that
would make the message unanswerable and the audit trail anonymous.

The run log SHALL record, per send step: the resolved recipient count, and
per channel the delivered / skipped-by-preference / failed outcomes, bounded
by the run log's existing sampling rule. It SHALL NOT record message bodies
beyond the log's bounded sample — a mail archive is not what a run log is
for.

Guardrails SHALL apply in this order, each independently sufficient to stop
a send:

- **Kill switches.** The notification subsystem's kill switches SHALL
  silence flow sends exactly as they silence declarative ones. No new
  switch SHALL be introduced for flow-originated sends: the flow side
  already has its own stop levers — the per-flow `enabled` flag and the
  instance flow kill switch, checked before every hop — and each side's
  levers SHALL keep working without silencing the other (killing a flow
  never silences declarative notifications, and vice versa).
- **Rate limiting.** Flow sends SHALL count against the SAME `RateLimiter`
  buckets as declarative sends — a shared budget, because the abuse being
  bounded is "how much this instance messages this person", not "per
  subsystem". A rate-limited send is recorded as such in the run log.
- **Recipient bound.** A single step execution SHALL refuse to send to more
  than a configured recipient bound (default modest, app-config raisable).
  Refusal follows the step's `onError` policy and names the count — a
  recipient template that expands to the whole instance is a configuration
  error to surface, not a broadcast to perform.

A send failure (a bounced SMTP handoff, an unknown Talk conversation) SHALL
be a step failure routed through the step's `onError` policy like any other
node's failure. It SHALL NOT be silently swallowed: a flow that reports
COMPLETED with zero of its messages delivered is the silent failure this
engine's specs consistently refuse.

#### Scenario: The subsystem kill switch reaches flow sends

- **GIVEN** a notification-subsystem kill switch set
- **WHEN** a run reaches a send node
- **THEN** no message MUST leave on the silenced channel
- **AND** the run log MUST record the step as skipped by kill switch

#### Scenario: Killing a flow does not silence declarative notifications

- **GIVEN** the instance flow kill switch set (or the sending flow disabled)
- **WHEN** an object event fires a declarative `x-openregister-notifications` rule
- **THEN** the declarative notification MUST still be delivered
- **AND** no flow send MUST occur, because the run never reaches its hop
- @e2e exclude covered by FlowMessagingService unit tests

#### Scenario: The budget is shared, not parallel

- **GIVEN** a recipient whose rate-limit bucket the declarative subsystem
  has already filled this window
- **WHEN** a flow send addresses the same recipient in the same window
- **THEN** the flow send MUST be rate-limited
- **AND** the reason MUST be recorded on the run log entry
- @e2e exclude covered by RateLimiter integration tests

#### Scenario: An exploding recipient list is refused, not sent

- **GIVEN** a send node whose recipient field resolves to more users than
  the recipient bound
- **WHEN** the step executes
- **THEN** no message MUST be sent
- **AND** the failure MUST name the resolved count and the bound, and follow
  the step's `onError` policy
- @e2e exclude covered by the node's unit tests

#### Scenario: A send is attributed to the acting user

- **GIVEN** a run executing as user `alice`
- **WHEN** its send-talk-message node posts to a conversation
- **THEN** the message MUST be attributed to `alice`
- **AND** a run with no resolvable acting user MUST fail the step rather
  than send anonymously
- @e2e exclude covered by FlowMessagingService unit tests

### Requirement: The messaging boundary between the two subsystems is stated and enforced

The declarative subsystem answers "WHENEVER this happens, notify these
people"; a flow send node answers "AT THIS POINT in this process, tell these
people". The engine SHALL NOT blur that line:

- the flow nodes SHALL NOT accept a schema/event subscription — a node that
  fires on events is a trigger, and triggering is already spec'd;
- the declarative dialect SHALL NOT gain an "invoke this flow node" clause;
  a schema that wants a process reaction declares a flow trigger;
- `activity`, `webhook` and `web-push` SHALL NOT be flow node types:
  activity is an audit surface, outbound HTTP is OpenConnector's job
  (ADR-094 — `openconnector.source-call` against a configured source), and
  web-push is a delivery detail of `nc-notification`, riding along exactly
  as it does for the declarative dispatcher.

#### Scenario: Outbound HTTP stays with OpenConnector

- **GIVEN** an author wanting a flow to POST JSON to an external endpoint
- **WHEN** they search the palette
- **THEN** no `openregister.send-webhook` node MUST exist
- **AND** the documented path is an `openconnector.source-call` node against
  a configured source
- @e2e exclude a palette composition assertion — covered by
  FlowNodeRegistryTest

#### Scenario: Web-push rides along with a notification send

- **GIVEN** a send-notification node addressing a user with an active
  web-push subscription
- **WHEN** the step executes
- **THEN** the web-push delivery MUST follow the nc-notification send under
  the dispatcher's existing rules, with no flow-side configuration
- @e2e exclude covered by FlowMessagingService unit tests
