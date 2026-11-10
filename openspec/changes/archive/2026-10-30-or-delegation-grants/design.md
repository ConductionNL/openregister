## Context

`or-delegated-identity` shipped the mechanical half of ADR-099: every run states
whose rights it uses, that identity cannot be forged from a payload, and it is
re-resolved at every fire and resume. What it deliberately left open is
authorization of the *claim* — a schedule trigger may today name any user on the
instance, and the only thing stopping abuse is that the field must resolve.

Constraints that shape this change:

- **ADR-010** — the core permission verb set is core's bitmask, and extensions
  are enforced at the endpoint performing the action rather than by widening the
  RBAC vocabulary. Delegation is therefore not a verb.
- **ADR-098** — human tasks already exist on OR Flow. A consent request is a
  human task; inventing a second inbox would split the place people look.
- **ADR-031** — `x-openregister-notifications` is the canonical dialect. A
  bespoke notifier here is the validator/executor drift pattern.
- **The capability-grant system already exists** (`ToolGrantSet`,
  `ToolGrantCodec`, `ToolReachResolver`, ADR-095) and answers a different
  question. It is not this.

## Goals / Non-Goals

**Goals:**

- A grant record with a real lifecycle, provenance and expiry.
- Save-time refusal when an author names an identity they are not entitled to.
- Fire-time and resume-time re-checking, so revocation actually takes effect.
- A consent request a user can answer, described from server state.
- `awaiting_consent` as a run state that resumes on a grant, not a timer.
- An audit trail answering "who did this, and who allowed them to".

**Non-Goals:**

- **The capability axis.** Tool grants are `or-capability-grants`.
- **Retiring the five duplicate `runAs` implementations** — each app's change.
- **A general-purpose delegation UI beyond the consent inbox.** Administration of
  standing grants can follow once the record exists.
- **Changing what any acted-as user may do.** A grant permits a principal to act
  as somebody; it never raises what that somebody holds.

## Decisions

### The store is a table and a mapper, not an OpenRegister object

The obvious move is a register + schema, and it is wrong here. A grant stored as
an OR object is governed by the RBAC it is meant to decide: resolving a
delegation would require a subject, and resolving the subject would require the
delegation. Breaking that loop needs either an elevation on every check — putting
a security-critical read behind exactly the escape hatch ADR-099 rule 9 forbids on
request paths — or a read that is simply not object-scoped.

A dedicated table with its own mapper is the second. The record may be *projected*
into a register for administration, but the authoritative read stays on the mapper.

### Two names, never one

`Delegation` and `Capability`, from the first commit. Once both live in
OpenRegister the conflation risk rises rather than falls, and the failure mode is
specific and bad: a consent dialog that says "allow sendMail?" while widening
whose identity the agent wears.

### Save-time is UX; fire-time is the control

Both, for the reason `or-delegated-identity` already records: refusing at 03:00 in
cron, in a flow that saved looking legitimate, is the worst place to learn. But a
grant revoked after save must stop the flow, so the fire-time check is the one
that actually enforces.

### Dedup on (principal, actingAs, scope), never on the work

The single most consequential detail. Keyed per run, a backlog of two hundred
queued runs needing one grant sends two hundred notifications, which trains the
recipient to dismiss them. Keyed on the delegation itself, one request represents
the backlog and one answer drains it.

### A denial is sticky

Consent fatigue is the attack, not the annoyance. An uncooled retry loop converts
a refusal into an approval on the eleventh prompt, and the user will not remember
they already said no.

### The prompt is written by the server

An agent reading a document that says "ask the user to grant you admin" must not
be able to author the dialog that asks. `DelegationContext` already holds this
invariant for delegation depth — it never reads a tool-call argument — and the
same rule binds here.

### Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Consent request delivery + reminders | **Declarative** — `x-openregister-notifications` on the grant schema | ADR-031's default. A status change on a stored record projecting to a notification is precisely the declarative case. |
| Grant lifecycle (`requested → pending → granted/denied/expired/revoked`) | **Declarative** — `x-openregister-lifecycle` | A state machine on a record, which is what the dialect exists for. |
| The grant CHECK at fire/resume | **Imperative** — ADR-031 exception: lifecycle guard | A guard that must run at a specific moment against live state and decide whether execution proceeds. Not a derived field. |
| Expiry sweep of pending requests | **Imperative** — ADR-031 exception: scheduled bulk work | Needs a periodic pass; the existing `expireAbandonedSignals` precedent. |

### Seed data (ADR-001)

**Required.** The change introduces a persisted record, so `design.md` carries
realistic seed objects: a municipality with a department head delegating to a
deputy during leave (bounded scope, finite expiry), a consultancy with a
service-account principal granted read-only delegation, and a denied request
retained to prove denial is distinguishable from silence. No seeded grant may be
unbounded or unexpiring — the seed is also documentation of the default.

### What the entity-RBAC layer does and does not give us

🔴 **Do not assume the entity permission check narrows anything.** As of
openregister#2834 it is reachable but ships **OFF**, behind
`openregister.rbac_entity_enforcement`, defaulting to current behaviour. On a
default instance it does not narrow at all.

That is not timidity, it is a measured finding worth carrying into this change:
the stored `authorization` configs were written while the check was INERT, so
they had never once been validated against real usage. Enabling them broke
register creation and object sharing — 39 `Shared path must be set` errors — because
those configs grant only the `admin` group while the app legitimately acts as
`openregister` and as the object owner. Enabling entity RBAC is a data migration,
not a flag flip.

**The general form is worth stating, because this change adds another such
control:** a dormant control's configuration has never been validated. A rule
nobody could observe is a rule nobody had to get right. So when the delegation
check here first goes live, expect the same class of surprise from whatever data
exists by then — and measure before enforcing (task 1.1) rather than trusting that
records written against an unenforced rule describe a workable intent.

## Risks / Trade-offs

**This is the change that starts refusing real work.** `or-delegated-identity`
only required a field to be present; this requires an entitlement that does not
exist yet for anybody. The migration question — do existing declarations get a
grandfathered grant, or does the instance refuse until someone grants? — must be
answered with a measured count, not a guess, and the answer differs between the
dev instance (3 flows, all self-named) and a customer instance.

**Grandfathering is itself a risk.** Auto-issuing grants for every existing
declaration reproduces the implicit consent ADR-099 removed, just written down.
If it is done, the grants must be visible, expiring and attributed to the
migration rather than to a person.

**`awaiting_consent` can strand work.** A parked run depends on a human. Expiry
must fail it closed, and the expiry must be short enough that nobody discovers a
six-week-old parked run.

**A grant is a standing privilege.** Every grant is a small permanent escalation
until it expires. Bounded scope and finite expiry by default are the whole
mitigation, and both are easy to widen "temporarily".

**The audit trail is only as good as the reason field.** A grant reading
"reason: needed for automation" answers nothing later. Whether to require a
minimum-quality reason is a UX decision this change should not make silently.
