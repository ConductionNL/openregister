# Notificatie Engine Specification (delta)

---
status: partial
---

## Purpose

A notification rule that reaches nobody says so: refused at declaration
when it can never resolve, recorded at dispatch when it resolves to
nobody today, and surfaced to a person rather than to a log.

## ADDED Requirements

### Requirement: A recipient that can never resolve is refused on import (REQ-RRN-01)

A recipient written as an empty `groups` or `users` list MUST be refused
at declaration time as `notification-recipient-names-nobody`.

A recipient naming a group that exists but is empty MUST NOT be refused.
Every declared group ships empty on a fresh install, so refusing it would
fail the import of every correct annotation on every new instance.

#### Scenario: An empty recipient list is refused

- GIVEN a rule whose recipient declares `groups: []`
- WHEN the annotation is validated
- THEN it is refused as `notification-recipient-names-nobody`

#### Scenario: A declared but unstaffed group is accepted

- GIVEN a rule naming a group that exists and has no members
- WHEN the annotation is validated
- THEN it is accepted, because who is in a group is an instance question and not a declaration error

### Requirement: A dispatch that reached nobody is recorded (REQ-RRN-02)

Dispatch MUST report how many recipients it reached, and a dispatch
reaching zero MUST be recorded against the rule. The record MUST be made
once per rule per run, with the count continuing underneath, so a storm
produces one line and not hundreds.

#### Scenario: A rule resolving to nobody is recorded

- GIVEN a rule whose recipients resolve to no one
- WHEN it is dispatched for an object
- THEN the rule is recorded as having reached nobody

#### Scenario: Four hundred objects produce one line

- GIVEN the same rule reaching nobody for four hundred objects in one run
- WHEN the run completes
- THEN one line was written for that rule
- AND the occurrence count reflects all four hundred

### Requirement: The record reaches a person, not only a log (REQ-RRN-03)

The aggregate of which rules reached nobody MUST be readable by an
administrator on a surface they already visit, and MUST NOT depend on
knowing which string to search the log for.

The recorder MUST be a shared service, not built privately inside the
dispatcher, or the aggregate is discarded with the dispatcher instance.

> 🔴 **NOT MET as of `parity/round2` `1a895e046`.** `report()` has no
> caller and the recorder has no service registration, so this
> requirement is declared and unimplemented on purpose, rather than left
> to be rediscovered. See tasks 3.1 to 3.3.

#### Scenario: An administrator sees that a rule is unstaffed

- GIVEN a rule that reached nobody during the last run
- WHEN an administrator opens the notification settings
- THEN they are told which rules reached nobody, and how often

#### Scenario: Nothing depends on searching the log

- GIVEN an administrator who has never heard of the log marker
- WHEN they want to know whether their rules reach anybody
- THEN the answer is on a page and not only in a log line
