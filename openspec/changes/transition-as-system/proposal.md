---
kind: code
depends_on: []
---

# Proposal: transition-as-system

## Summary

An app can run a lifecycle transition as the system after its own check
has approved the caller. `TransitionEngine::transitionAsSystem()` skips
OpenRegister's own read and update checks on the subject and nothing else:
the transition's declared guard, authorization, condition and actions still
run with the real caller, and the audit row records which app took the
decision.

## Why

learniq's course evaluation answer (DECISIONS row 63, Ruben chose
"Transition as system" on 5 Oct 2026). The answer service saves the draft
response as the system, then asks the TransitionEngine to run `submit`.
The engine looks the row up with the caller's rights and demands `update`
on it, and a learner holds neither on that schema. To make the submit
work, learniq #1715 granted every signed-in user read and update on every
`draft` response row. That rule is the access hole: any signed-in user can
read and change a draft that is being submitted, through the generic
objects API. learniq has already checked the caller (an open, unanswered
invitation in an open campaign) before it writes, and its `requires`
guard checks again inside the transition. What it lacks is a way to say
"I have checked, run the move".

The analysis and the two alternatives are in
`for-ruben/learniq-evaluation-draft-rule.md` (build-all workspace).

## What changes

- `TransitionEngine::transitionAsSystem(objectId, action, app, data)`: a
  separate public method, so no request parameter can select it. `app` is
  required and names the app that approved the caller.
- On that path the subject is read without RBAC and without the
  organisation filter, the `update` check is skipped, and the lifecycle
  save (and the provider-mode re-read) runs without them.
- The save still dispatches `ObjectUpdatingEvent`, so the lifecycle
  validator still refuses an undeclared move and still runs the
  transition's `authorization`, `condition` and `requires` guard with the
  session user.
- The audit row keeps the real caller as its user and carries
  `transitionAsSystem: {"app": "<app>"}` in its change set; an info log line
  names the app, the caller, the object and the action.
- No controller calls it. A structural test pins that.

## Out of scope

- learniq dropping its `authenticated`/`draft` rule and calling the new
  method: a learniq PR after this lands, raising its minimum OpenRegister
  version.
- A generic "run any write as the system" API. `ObjectService::runAsSystem()`
  exists for installation and repair and keeps its reachability boundary.
