# Design: `type: "agent"` flow action

> Umbrella decisions apply (SPECTR-NEXTCLOUD-PLAN.md §5.2).

## Approach

`FlowActionService::runAction()` gains a fourth case (`agent`, alongside
`calendar-event`/`agenda-task` and `email`/`mail`). It validates the action
config, renders the prompt template through the SAME `render()` helper the
other two actions already use (no new templating engine), builds an
`AgentRunRequestedEvent`, and dispatches it via `IEventDispatcher::dispatchTyped()`.
That is the entire OpenRegister-side surface — there is no LLM invocation, no
HTTP call to Hermiq, and no polling. The event either finds a listener (Hermiq
installed) or is a silent no-op (Hermiq absent); either way the triggering
object's save path is unaffected.

## Event contract

`OCA\OpenRegister\Event\AgentRunRequestedEvent` (extends `OCP\EventDispatcher\Event`):

| Field | Type | Source | Notes |
|---|---|---|---|
| `subjectUuid` | string | `$object->getUuid()` | The triggering object. |
| `subjectRegister` | string | `$object->getRegister()` | Register slug/id. |
| `subjectSchema` | string | `$object->getSchema()` | Schema slug/id. |
| `agent` | string | action config `agent` | Agent UUID (v1: UUID only — OpenRegister's `Agent` entity has no `slug` field yet; see the "Agent ref resolution" note in the companion Hermiq change). |
| `skill` | string\|null | action config `skill` | Optional skill slug; `null` when omitted. |
| `prompt` | string | action config `prompt`, **rendered** | `{{field}}` placeholders already resolved against the object's data via `FlowActionService::render()` — the consumer receives a plain string, not a template. |
| `resultField` | string | action config `resultField` | The object field the consumer writes the agent's output to. |
| `requiresApproval` | bool | action config `requiresApproval` | Default `false`. |
| `mode` | string | action config `mode` | Default `"async"` — the only supported value in v1 (SPECTR-NEXTCLOUD-PLAN.md §5.2 point 5: sync inline execution is explicitly out of scope). |
| `flowName` | string | owning flow's `name` | Diagnostics/audit only. |
| `correlationId` | string | generated (`Uuid::v4()`) | Unique per dispatch — lets a consumer de-duplicate a gated (pending-approval) run. |

`getPayload(): array` flattens all of the above into a plain, JSON-serialisable
associative array — the shape a consumer hands to a background job argument
(job arguments must be scalar-only; the event object itself cannot cross that
boundary).

**Why the prompt is rendered, not a raw template.** SPECTR-NEXTCLOUD-PLAN.md
§5.2 describes the dispatched payload as carrying "prompt template" at the
*configuration* level (the flow author writes `{{field}}` placeholders, same as
`calendar-event`/`email`). OpenRegister already owns a working `{{field}}`
renderer (`FlowActionService::render()`, reused unchanged by all three action
types) and already has the object's full data in hand at dispatch time. Two
options were considered:

1. **Dispatch the raw template string; let the consumer render it.** Rejected —
   duplicates the exact same `{{field}}` interpolation logic in a second
   codebase (Hermiq), and the consumer would need the object's full data
   snapshot in the event anyway to render it, which is a larger payload than a
   single rendered string.
2. **Render once, in OpenRegister, before dispatch (chosen).** Reuses the
   existing helper, keeps the event payload small and self-contained (a plain
   string a consumer can hand straight to an LLM call), and is symmetric with
   how `calendar-event`/`email` already render their templated fields before
   acting. The "template" framing in the plan describes the *author-facing*
   config surface, not the wire payload.

## Declarative-vs-imperative decision (ADR-031)

ADR-031 requires: when OR provides an `x-openregister-*` extension that fits
the requirement, apps declare behaviour in the schema register rather than
writing a service class, and any imperative code added alongside a declarative
surface is justified in this section.

**This change adds no new imperative business logic to OpenRegister.** The
declarative surface (`x-openregister-flows`, already schema-register-driven)
is *extended in vocabulary only* — a flow author now has a fourth action
`type` to choose from, exactly as declarative as `calendar-event`/`email`
before it. The one piece of new PHP (`FlowActionService::runAgent()` +
`AgentRunRequestedEvent`) is not a business-logic service: it is the ADR-041
cross-app command seam, and ADR-031 explicitly carves out exactly this kind of
code as legitimate:

- ADR-031 §"What apps SHOULD still write in PHP" lists **external API
  integrations** (CalDAV, Peppol, ZGW, ORI, TenderNed, IMAP, vendor SaaS) as
  work the schema engine cannot express — "the OR engine cannot reach outside
  systems; the adapter is yours to write." An LLM agent runtime living in a
  sibling Conduction app (Hermiq) is architecturally the same shape as an
  external system from OpenRegister's point of view: OR does not — and per
  gate-27 must not — reach into it directly. Dispatching a typed event is the
  *smallest possible* adapter: one method, one `dispatchTyped()` call, zero
  business rules, zero LLM logic, zero loops.
- The existing `calendar-event` and `email` actions are the precedent: they
  are themselves small, single-purpose PHP methods invoking Nextcloud
  surfaces (`CalendarEventService`, `IMailer`) — accepted, non-controversial
  imperative glue *underneath* a declarative config surface. `runAgent()`
  follows the exact same shape one level further out (through an event
  instead of a direct Nextcloud service call), because the destination
  (Hermiq) is a sibling app rather than an NC built-in.
- No aggregation, calculation, lifecycle, or notification logic is added —
  the four extension categories ADR-031 asks to prefer declaratively. This
  change touches none of them; it only widens `x-openregister-flows`' action
  vocabulary, which itself has no declarative "extension" equivalent to
  supersede it (flows are already the declarative surface).

**Conclusion**: no ADR-031 exception filing is needed — this is the
"external API integrations" carve-out applied to a sibling-app boundary via
the ADR-041 event recipe, not a new service class duplicating an existing
`x-openregister-*` extension.

## Failure isolation

Unchanged from the existing flow contract: `runAction()`'s try/catch wraps
`runAgent()` exactly like every other action type. A malformed config (missing
`agent`/`resultField`, unsupported `mode`) is caught *inside* `runAgent()`
itself (logged, no dispatch) rather than relying on the outer catch, so the
distinction between "config error" and "dispatch failure" is visible in the
logs. A `dispatchTyped()` call that throws (e.g. a buggy third-party listener)
is still caught by `runAction()`'s outer try/catch — the triggering object's
save is never affected, and the remaining actions in the flow still run.

## Files Affected

### Backend (new)
- `lib/Event/AgentRunRequestedEvent.php`
- `tests/Unit/Event/AgentRunRequestedEventTest.php`

### Backend (modified)
- `lib/Service/Flow/FlowActionService.php` — new `agent` case + `runAgent()` +
  `IEventDispatcher` constructor injection.
- `tests/Unit/Service/Flow/FlowActionServiceTest.php` — event-dispatcher mock
  wiring + new agent-action test cases.

## Risks

| Risk | Mitigation |
|---|---|
| A flow author configures `type: "agent"` with no Hermiq installed | Silent no-op by design (ADR-041) — objects keep flowing; nothing to mitigate beyond the existing behaviour of any unlistened NC event. |
| A malformed `agent`/`resultField`/`mode` config silently does nothing, confusing the author | Every skip path logs a `warning` naming the exact missing/invalid field (mirrors the existing "Unknown flow action type" warning). |
| The rendered-prompt design leaks object data the flow author didn't intend | Same trust boundary as `calendar-event`/`email` today — the flow author already controls which object fields the template references; no new exposure introduced. |
