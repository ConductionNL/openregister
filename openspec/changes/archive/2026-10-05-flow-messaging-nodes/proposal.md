---
kind: code
---

# Proposal: flow-messaging-nodes

## Summary

Give flows the ability to send: three new step nodes —
`openregister.send-notification` (Nextcloud notification),
`openregister.send-email`, and `openregister.send-talk-message` — implemented
as thin orchestration-time invokers of the EXISTING ADR-031 notification
subsystem's channel machinery. A flow gains "tell a person", it does not gain
a second messaging stack.

## Why

Today the two subsystems cannot meet:

- **Notifications notify.** `x-openregister-notifications` (ADR-031) is
  declarative and event-driven: a schema annotation says "when an object of
  this schema changes, tell these people over these channels". Channels
  `nc-notification`, `email`, `activity`, `webhook`, `talk`, `web-push` are
  implemented in `AnnotationNotificationDispatcher`, with recipient
  resolution, preference filtering, delivery windows, coalescing and rate
  limiting around it.
- **Flows orchestrate.** The engine can read, write, route, wait for a
  signal, call any API through OpenConnector (ADR-094) — and cannot send
  anything. A flow that reaches "now tell the requester their case moved"
  has no node for it.

The gap is real in every approval-shaped flow: `openregister.await-signal`
suspends a run until someone answers, but nothing tells that someone they
are being waited on. Authors work around it with webhook contortions or by
abusing an object write to fire a schema notification — an orchestration
step wearing a declarative trigger's clothes, invisible in the flow's own
run log.

The wrong fix is a flow-owned mailer. The notification subsystem already
solved templating, recipient resolution, rate limiting, kill switches, and
per-user channel preferences; a second implementation of any of those is a
place for the two to disagree — precisely the duplication ADR-065 exists to
end for engines and ADR-031 for business logic.

## What Changes

- **Three step nodes** in `lib/Service/Flow/Nodes/`, registered like every
  other built-in, each implementing `IFlowNodeConfigForm`
  (`flow-node-config-forms` sets that floor):
  - `openregister.send-notification` — recipients (users/groups or a field
    on the item), title/message templates;
  - `openregister.send-email` — recipients, subject/body templates;
  - `openregister.send-talk-message` — Talk conversation token or lookup,
    message template.
- **One shared invoker, not three senders.** A `FlowMessagingService`
  bridges the node call onto the SAME channel senders, recipient resolver,
  `RateLimiter`, and kill-switch checks the annotation dispatcher uses —
  refactoring `AnnotationNotificationDispatcher`'s per-channel send paths
  into call-shared units where needed, never copying them.
- **Templating is the notification dialect's**, applied to the flow item —
  one placeholder syntax for a body whether it is declared on a schema or
  on a node.
- **Attribution and guardrails**: sends run as the flow run's acting user
  and are recorded in the run log per recipient/channel outcome; the
  notification rate limiter counts flow sends in the same buckets; the
  subsystem kill switches silence flow sends too; a per-step recipient
  bound refuses configuration-sized mistakes before they become mail floods.

## What does NOT change

- The declarative subsystem. `x-openregister-notifications` remains the way
  to say "whenever X happens, notify Y" — the boundary statement stands:
  **notifications notify; flows orchestrate.** A flow node is for a send at
  a POINT IN A PROCESS, where "when" is the marking, not an event.
- The channel set. No new channels; `activity`, `webhook` and `web-push` are
  NOT exposed as flow nodes — activity is an audit surface not a message,
  webhooks are OpenConnector's job (ADR-094), web-push rides along with
  `nc-notification` exactly as it does for the dispatcher.
- Notification preferences semantics: a user who turned a channel off stays
  not-messaged on it, flow or no flow.

## Impact

- **Affected specs**: new capability `flow-messaging-nodes`; `flow-engine`
  untouched (the nodes are ordinary registered steps)
- **Affected code**: new nodes + `FlowMessagingService`;
  `AnnotationNotificationDispatcher` refactored to share its channel send
  units; `FlowNodeRegistrationListener` registers the three types
- **Affected apps**: consumers gain the nodes through the shared palette;
  hermiq's sequencer can finally announce its own failures
- **ADRs**: ADR-031 (subsystem reused, dialect untouched), ADR-065 (nodes
  join the single engine), ADR-094 (webhook/API sends stay with
  OpenConnector sources — explicitly not duplicated here)

## Capabilities

### New Capabilities
- `flow-messaging-nodes` — send-notification, send-email and
  send-talk-message step nodes reusing the ADR-031 channel machinery
