---
kind: code
---

# Proposal: a-rule-that-reaches-nobody-says-so

Shipped as openregister#3961 (`7ba9fea87`). This document records what was
built, and, more importantly, the one thing it does not yet do.

## What was built

A notification rule that resolves to zero recipients now says so, in two
places, because there are two different failures wearing the same shape.

- **At declaration time**, `NotificationAnnotationValidator` refuses
  `notification-recipient-names-nobody`: a recipient written as
  `groups: []` or `users: []` can never resolve, whatever the instance
  looks like, so it is refused on import.
- **At dispatch time**, `AnnotationNotificationDispatcher::dispatchToParties()`
  returns the number reached instead of `void`, and when that number is
  zero it calls `RuleReachRecorder::reachedNobody()`.

A declared group that happens to be empty is deliberately **not** refused
at declaration. Every declared group in this fleet ships empty on a fresh
install, because an empty group denies everyone except admins and object
owners. Refusing it would fail the import of every correct annotation on
every new instance. "Can never resolve" and "resolves to nobody today"
are different questions with different answers.

`RuleReachRecorder` logs once per rule per run, because four hundred
identical lines is the same silence with noise in front of it.

## What it does not do, recorded rather than left to be discovered

🔴 **`RuleReachRecorder::report()` has no caller.**

Measured on `parity/round2` at `1a895e046`:

- `grep` for `->report(` across `lib` finds ten call sites, all of them
  other classes: `ConnectionSeamReportJob`, `ApiTokenSettingsController`,
  `EdepotSettingsController`, `HardeningController`, `AnonymisationRun`
  and two repair steps. None is this class.
- `RuleReachRecorder` appears in `lib` in exactly three files: itself,
  the dispatcher's property, constructor parameter and default, and one
  comment in the validator pointing at it.
- It is **not registered in `lib/AppInfo/`**. The dispatcher builds its
  own with `new RuleReachRecorder(logger: $logger)` when none is
  injected, and holds it in a private property.

So `report()` is not merely uncalled. It is unreachable: the aggregate it
builds, which is the part that says *how many* rules reached nobody and
whether one rule failed four hundred times or four hundred rules failed
once, lives in a private object that is discarded when the dispatcher
instance is.

**What that leaves.** The warning line is real and it is logged. It is
findable by an administrator who already suspects the problem and knows
`[notification] rule reached nobody` is the string to search for. That is
the wrong audience: the person who needs to know is the one who will
never be told that the thing they are waiting for failed, and they are
not reading the log.

This is the dark-capability shape: a method that exists, is tested, reads
as a feature in review, and is reachable by nothing. Recording it here so
it is not rediscovered as a surprise, and so nobody reads #3961's tests
as evidence that an operator is being told.

## What would give it a caller

Three candidates, in order of how much they cost:

1. **A status endpoint and an admin panel row.** Register
   `RuleReachRecorder` as a shared service rather than a per-dispatcher
   private, keep the counts for the run, and read `report()` from the
   notification settings page: "2 rules reached nobody in the last run".
   This is the smallest change that puts the aggregate in front of a
   person, and it is where an administrator configuring notifications is
   already standing.
2. **A scheduled check that notifies.** A background job that calls
   `report()` after a dispatch sweep and raises a Nextcloud notification
   to admins when `needsAPerson` is true. This reaches somebody who is
   not looking, which is the whole point, but it needs the same care
   about storms that every other rule needs, and it must not turn a
   legitimately quiet instance into a nag.
3. **Persist it.** Write the per-run result so it can be read after the
   fact and trended, which is what answers "has this rule been
   unstaffed for three weeks" rather than "is it unstaffed right now".

Option 1 is the honest first step and the one this change recommends: it
is the smallest thing that changes the audience from "whoever reads logs"
to "whoever configures notifications".

## What a leaf app did in the meantime

filinq#1136 does not wait for any of it. Its inbox asks, at read time,
whether the group its rule names has members, and says the answer on the
screen beside the failure count. That answers a different question,
"will this reach anybody at all", before the run rather than after it,
and it is on a screen rather than in a log.

It is a good answer and it is not a substitute. A leaf app can only ask
about the rules it declared itself. `report()` is the platform's answer,
across every rule on the instance, and it stays worth wiring.
