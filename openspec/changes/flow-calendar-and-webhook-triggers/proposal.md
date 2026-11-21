---
kind: code
---

# Proposal: flow-calendar-and-webhook-triggers

## Summary

Two new ways to start a flow. A calendar trigger starts it at a moment taken from a date on a record, such as thirty days before a contract ends, counted in calendar days or in working days. An incoming-webhook trigger gives the flow its own address, so another system starts it with a signed call and the call's body becomes the run's input.

Ruben decided both on 12 Nov 2026 (decision 399): specify them for the flow engine, not built yet.

## Why

The engine has three trigger node types today: object, schedule and manual (flow-engine, "A TRIGGER is a node, and a flow may carry several"). Two common asks have no node:

- **"Remind the owner a month before the contract ends."** A cron schedule fires at fixed clock times. It cannot fire relative to a date that differs per record, so authors build a nightly flow that reads every record and compares dates by hand, and every app writes that loop again.
- **"Start this flow when the payment provider calls us."** There is no inbound address. Authors route the call through an object create, which needs a schema for the call and a user account for the caller.

## What changes

- A new node type `openregister.trigger-calendar`: a register, a schema, a date property, an offset, and optionally the working calendar to count the offset in (flow-business-timers).
- A new node type `openregister.trigger-webhook`: a per-trigger address and a signing secret, shown once. A signed call queues a run with the body as input and answers 202 with the run's id.
- Both declare the identity a run executes as (`runAs`), the same way a schedule trigger does, and fail closed without one.

## Out of scope

- A CloudEvents receiver. integriq owns that (decision 392); this trigger takes a plain JSON call addressed to one flow.
- Firing on events in a Nextcloud calendar. The calendar trigger reads dates on records.
- Replaying moments that passed before a calendar trigger was enabled.

## Rows this closes

| matrix | row | capability | own rating |
|---|---|---|---|
| openregister | auto-trigger-calendar | Start an automation at a date held on a record, such as a month before a contract ends | no |
| openregister | auto-trigger-webhook | Start an automation when another system calls a web address | no |

## Risks

- A public address on a flow is an entry point without a session. The signature check runs before anything is queued, the address carries no information about the flow, and a refused call leaves no run behind.
- A calendar trigger on a large schema can make many runs come due at once. Due runs go through the same queue as every other trigger, so queue fairness (flow-queue-fairness) applies to them.
