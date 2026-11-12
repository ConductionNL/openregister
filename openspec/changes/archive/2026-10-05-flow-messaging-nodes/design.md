# Design: flow-messaging-nodes

## Decision 1: extract call-shared channel units, do not call the dispatcher

Two ways to reuse `AnnotationNotificationDispatcher`:

1. **Call the dispatcher with a synthetic annotation.** The flow node
   fabricates an `x-openregister-notifications` spec at run time and feeds
   it through the declarative pipeline. Rejected: the dispatcher's pipeline
   is event-shaped (coalescing, digest windows, schema-rule evaluation are
   all about event streams), and a synthetic annotation would drag an
   orchestration send through machinery built for a different question —
   plus every future dispatcher change would silently change flow-send
   behaviour through a path nobody can see on the canvas.
2. **Extract the per-channel SEND units** (the nc-notification push +
   web-push ride-along, the mail composition/handoff, the Talk post) and the
   recipient resolver into units both callers invoke (chosen). The
   dispatcher keeps its pipeline and calls the units at the end; the flow's
   `FlowMessagingService` calls the same units directly. What is shared is
   exactly what must not fork — channel mechanics, recipient resolution,
   rate limiting, kill switches, templating. What is NOT shared is the
   pipeline that decides WHETHER to send, because the two subsystems answer
   that question differently by design.

The refactor is behaviour-preserving for the declarative path and is guarded
by the existing dispatcher tests plus the "same send from both callers"
equivalence scenario.

## Decision 2: shared rate-limit budget, separate kill switches

The rate limiter bounds "how much this instance messages this person". Two
parallel budgets would let a flow double every recipient's ceiling — so the
buckets are shared. Kill switches cut the other way: the reason to pull a
switch is a runaway SOURCE, and an operator killing a misbehaving flow
should not silence case-update notifications for the whole instance. So:
existing subsystem switches apply to everything (they guard the machinery),
and the flow side needs NO new switch — a runaway sending flow is stopped
with the levers that already exist there: the flow's own `enabled` flag,
or the instance flow kill switch when it is many flows at once. Neither
touches the declarative subsystem, which is exactly the independence an
operator needs. A new config key would be a third lever duplicating the
first two (per product-owner decision 2026-08-18: no app-config switch
for flow messaging).

## Decision 3: acting user, and what happens without one

Sends are attributed to the run's acting user: replies have an addressee,
audits have an actor, and Talk messages appear from a person who is actually
in the conversation. A run without a resolvable acting user (e.g. a
schedule-triggered flow whose owner was deleted) FAILS the step rather than
falling back to a system identity — the fallback would be an anonymous
messenger created by an edge case, exactly how "the owner lookup runs as
Anonymous" class bugs are born. The failure names the missing actor.

Talk specifics: posting as the acting user requires that user to be a
participant of the target conversation. The node treats "not a participant"
as a step failure with that reason — it does NOT auto-join the user into a
conversation, which would be a privacy-relevant side effect performed by a
messaging convenience.

## Decision 4: three nodes, not one "send" node with a channel picker

A single `openregister.send` with `channel: email|talk|notification` was
considered and rejected. The three differ in required config shape (subject
vs conversation token vs title), in failure modes, and in form layout; one
node would be a union type whose form shows and hides fields by channel —
a modal pretending to be three modals. Three nodes keep each form flat,
each `configKeys()` honest, and the palette self-explanatory. The cost —
three registrations instead of one — is where the cost belongs.

## Recipient resolution shape

Recipients accept either a literal list (user/group ids, the form's select)
or a template resolving a field on the item (e.g. `{{ item.assignee }}`),
because the person to tell is usually ON the case object. Resolution reuses
the notification subsystem's recipient resolver (groups expanded, unknown
ids reported per recipient in the run log). The recipient bound is applied
AFTER expansion — bounding the resolved humans, not the config entries.
