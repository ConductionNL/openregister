# `ObjectTransitionedEvent` — the slug contract

`ObjectTransitionedEvent` documents its `register` and `schema` constructor
params as **slugs**. `TransitionEngine` has always passed
`(string) $object->getRegister()` / `getSchema()`, which are **numeric ids**.

Every listener that compares those values to a slug literal has therefore never
matched, and has **never run** — not once, with no exception, no log line and no
failing test.

## Why the fix ships disabled

Honouring the documented contract is a two-line change. It is also one of the
largest behaviour changes available in this codebase, because **44 listeners
across scholiq (34), shillinq (8) and openbuild (2) start running at once**,
against data that was written while they were silent.

What those 44 do:

| Class of work | Count | Examples |
|---|---|---|
| General-ledger postings | 5 | COGS valuation, fixed-asset disposal journals, intercompany mirrored entries, GR/IR clearing, delivery dispatch (which cascades into stock-move valuation) |
| Outbound I/O | 7 | HTTP to DUO / OSO / municipality data exchange, external timetable systems, Nextcloud Talk provisioning, Docudesk generation, pipelinq timeline, parent/guardian notifications |
| Bulk / cascading writes | ~14 | academic-year rollover, application conversion, conference-slot generation, grade rollups, report-card composition, credential issuance |
| Benign local rollups | remainder | flags, counters, small recomputes (these still write objects) |

None of this is idempotent at the listener layer, and there is no backfill. An
instance that enables the flag will emit ledger entries and outbound calls for
transitions that already happened under the old, silent behaviour.

There is also an **emergent fail-closed hazard**, which is observable today and
is not caused by this flag: `TransitionEngine::transition()` does not wrap
`dispatchTyped()` in a try/catch. A listener that throws turns the user's
transition into a 500. This already happens on this instance — a
`learning-plan-evaluation` transition returns 500 with
`Failed to process event: Property 'eventId' should be type 'integer or null'`,
thrown by a listener and propagated out of the dispatcher. 23 of the 34 scholiq
wake-ups call `saveObject()` with no `catch` anywhere in the file, so enabling
the flag widens an existing hazard rather than creating a new one.

## Enabling it

```
occ config:app:set openregister transition_event_slug_contract --value=yes
```

Default is `no`. Before enabling on an instance, audit that instance's own
`ObjectTransitionedEvent` listeners against the table above.

⚠️ The app-config local cache is APCu and per-SAPI: a value written by `occ` is
not immediately visible to web workers. Poll until the change is actually
observed rather than assuming it took effect.

## What the flag does not fix

- **5 shillinq listeners stay dead.** `ACMReportSignTransitionListener`,
  `OpdrachtUitvoeringTransitionListener`, `CommitmentMaterialisationListener`,
  `TenderNedAwardDetectedListener` and `VerplichtingTransitionListener` compare
  `$event->getObject()->getSchema()` — the **ObjectEntity's own** numeric id,
  which this change does not touch. Enabling the flag therefore leaves
  shillinq's ledger wiring *half* live, which may be worse than fully dead.
- **`ActionListener` payload values shift.** `lib/Listener/ActionListener.php`
  overwrites `$payload['registerUuid']` / `['schemaUuid']` with the event's
  strings. Those keys are matched against Action rows that store **UUIDs**, so
  `"116"` never matched and `"bezwaar"` will not either — no matching change.
  But the payload is passed verbatim to `ActionExecutor`, so any action template
  or filter condition referencing `{{registerUuid}}` / `{{schemaUuid}}` starts
  seeing a slug. Audit action templates before enabling.
- **Filtered event subscription is unaffected.** `ObjectEventProxyListener`
  resolves declared interest against the written `ObjectEntity`'s ids, never
  against `$event->getRegister()`, so slug and id declarations both keep working.

## Verification

Live positive control on the development instance, procest `bezwaar`
(register 17 = `procest`, schema 116 = `bezwaar`), using a probe listener with
the same guard shape as the 44:

| flag | transition | event carried | slug guard | listener ran |
|---|---|---|---|---|
| `no` (default) | HTTP 200 | `reg=17:sch=116` | rejects | **no** |
| `yes` | HTTP 200 | `reg=procest:sch=bezwaar` | passes | **yes** |
