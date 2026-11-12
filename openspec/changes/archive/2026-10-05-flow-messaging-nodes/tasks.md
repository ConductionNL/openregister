# Tasks: flow-messaging-nodes

## Extraction (behaviour-preserving refactor first)

- [x] Extract the per-channel send units from
      `AnnotationNotificationDispatcher` — nc-notification (+ web-push
      ride-along), email composition/handoff, Talk post — and the recipient
      resolver into injectable units under `lib/Service/Notification/`;
      dispatcher re-wired onto them with its existing tests green and
      unchanged.
- [x] Confirm `RateLimiter` bucket keys are caller-agnostic (recipient +
      channel + window), so both callers share one budget by construction.

## FlowMessagingService

- [x] `FlowMessagingService` invoking the extracted units; applies, in
      order: subsystem kill switches → preference filter (per-recipient
      channels) → recipient bound (post-expansion, default from app config)
      → rate limiter → send. No flow-messaging-specific switch: stopping a
      sending flow is the per-flow `enabled` flag or the instance flow kill
      switch, both of which halt the run before this service is reached.
- [x] Acting-user resolution from the run; no resolvable actor = step
      failure naming the missing actor (never a system-identity fallback).
- [x] Templating through the notification dialect's placeholder evaluation
      against the flow item — reuse the dialect's evaluator, add none.
- [x] Per-recipient/channel outcome collection (delivered / skipped-by-
      preference / skipped-by-kill-switch / rate-limited / failed) returned
      for the run log, bounded by the log's sampling rule.

## Nodes

- [x] `SendNotificationNode` (`openregister.send-notification`) — recipients
      (literal or item-field template), title + message templates;
      `validateConfig` refuses an empty message and an empty recipient
      config; `configForm()` per the flow-node-config-forms floor.
- [x] `SendEmailNode` (`openregister.send-email`) — recipients, subject +
      body templates; same validation and form obligations.
- [x] `SendTalkMessageNode` (`openregister.send-talk-message`) —
      conversation token or item-field lookup, message template;
      "acting user not a participant" is a step failure, never an auto-join.
- [x] All three: items returned unchanged; failures routed through the
      step's `onError` policy; registration in
      `FlowNodeRegistrationListener`.

## Tests

- [x] Equivalence test: schema annotation vs flow node, same recipients and
      template, same object — identical deliveries through a recorded
      sender double.
- [x] Guardrail tests, each with a positive control (the send that DOES go
      out when the guard is off): flow kill switch (and declarative path
      unaffected by it), subsystem kill switches, shared rate-limit bucket
      (declarative fill blocks flow send), recipient bound, preference skip.
- [x] Attribution tests: acting user carried onto each channel; missing
      actor fails the step.
- [x] Palette test: exactly these three messaging types; no
      `openregister.send-webhook` (boundary with ADR-094).

## Documentation

- [x] Feed the three nodes and the boundary statement ("notifications
      notify; flows orchestrate; API calls via OpenConnector sources") into
      `flow-engine-docs`' steps catalogue — one sentence each here, the
      prose lives there.

## Acceptance criteria

- No second mailer, Talk client, recipient resolver or template syntax
  exists anywhere under `lib/Service/Flow/`.
- Every guardrail is enforced in code with a test that can fail, not
  declared in help text.
- A COMPLETED run never hides an undelivered message: every non-delivery is
  in the run log with its reason.

## Quality checklist

- `composer check:strict` passes (PHPCS, PHPMD, Psalm, PHPStan).
- `@spec` annotations point at
  `openspec/specs/flow-messaging-nodes/spec.md` anchors.
- Depends on `flow-node-config-forms` for the form floor (soft — forms can
  land with the nodes either way); references ADR-031, ADR-065, ADR-094.
