# Lifecycle over a reference field: results, side moves and reopen

## Why

Round 2 of the dossiq competitor analysis (row A02 in
`concurrentie-analyse/procest/_round2/compare/findings.md`, placement section
3, decision D10): every competitor drives a case from its page. OpenCase
offers Close, Reopen, Archive and Create subcase
(`opencase/round2/case-detail-anatomy.md`); GZAC's kebab has Claimen and
Dossier verwijderen (`valtimo/round2/case-detail-anatomy.md`); Zaaksysteem
has Fase afronden, Opschorten, Hervatten, Termijn wijzigen and Vroegtijdig
afhandelen (`xxllnc-zaken/round2/pages/Case-Zaakacties.md`).

dossiq's CaseDetail declares `lifecycleActions { field: status }` and
renders nothing, because `case.status` references a `statusType` row
(triage #1). OpenRegister already answers the core of that: graph mode
derives transitions from FK-scoped sibling rows (object-lifecycle, Lifecycle
graph mode derives transitions from FK-scoped siblings) with `case` and
`statusType` as its worked example. What graph mode cannot express is the
rest of the competitors' action set: a move that records a result and a
comment, side moves such as suspend and resume that leave the ordered graph,
and a reopen from a terminal state. This change adds those three to graph
mode; the dossiq half is `case-status-onto-engine-lifecycle`, and the
`CnLifecycleActions` rendering is nextcloud-vue's.

## What changes

- A transition MAY declare a `form`: properties the actor fills when
  applying it (a result from an enumeration, a comment, a date). The values
  are written to the object with the move, in one save, and appear in the
  audit entry of the transition.
- A `graph` block MAY declare `sideMoves`: named transitions available from
  any non-terminal state that set a second field (`suspended: true`, with
  `resumeTo` the state the object returns to) without touching the ordered
  graph. The engine offers `suspend` when not suspended and `resume` when
  suspended, and refuses graph moves while suspended.
  Each side move MAY carry its own `form`.
- A `graph` block MAY declare `reopen`: a named transition from a terminal
  state to a declared sibling state, guarded like any other and requiring
  `manage` by default.
- `availableActions()` returns the form definition with each action so a
  client renders the dialog from the answer.

## Who benefits

dossiq, zaakafhandelapp, decidiq (decision states), keepiq (ticket states),
humaniq (leave requests), any app with a per-type status vocabulary.

## Impact

- Affected specs: object-lifecycle (delta).
- Affected code: `x-openregister-lifecycle` validation, `TransitionEngine`
  (form, side moves, reopen), the lifecycle actions endpoint, the audit
  entry of a transition.
- Backwards compatible: a graph block without `sideMoves`, `reopen` or
  `form` behaves as today.
