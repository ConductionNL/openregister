---
kind: code
---

# Proposal: a-system-write-declares-itself

Written after the fact, during the quality sweep of `parity/round2`. The code
and its tests shipped in openregister#3958; the `@spec` tag on
`tests/Unit/Service/SystemOperationContextAssertTest.php` named this change
and nothing here existed, so the tag pointed at a file nobody had written.
This records what was built rather than proposing something new.

## Why

A consuming app cannot hard-depend on OpenRegister, so every consumer
invented the same guard: check the class exists, call `run()`, and otherwise
perform the operation plainly. That fallback is the bug. It does not decline
to elevate. It runs the identical write as whoever is signed in and returns
the same value the elevated call would have returned, so nothing throws and
nothing logs. The write either records the wrong principal, or fails a
permission check somewhere far away for a reason nobody connects back to a
missing class.

It also leaves the codebase unsweepable. A reviewer asking which writes run
as the system cannot answer from the source, because a call site naming the
context may or may not have elevated, and a scan for the idiom counts the
degraded path as elevated. That ambiguity is what stopped integriq's
permission sweep: the safe subset could not be identified, so nothing could
be restricted.

## What was built

`SystemOperationContext::assertSystem(what, operation)` runs the operation
inside the elevated scope and refuses to be ambiguous about it. Either the
operation ran elevated, or the call throws
`SystemContextUnavailableException` naming the write.

The elevation is verified rather than assumed, and on both sides of the
operation. Before it, because an elevation that never applied is the
ordinary failure. After it, because one that stopped applying part-way is
the dangerous one: the write has already happened, and checking only up
front would call it elevated.

An earlier draft checked that the class itself existed and threw when it did
not, which cannot happen, because a class that does not exist cannot run its
own static method. That guard was dead the day it was written, and a dead
guard is worse than none: it reads as a check, and a later edit deletes it
with every test still green.
