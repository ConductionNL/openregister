---
title: Acting on Behalf of a User
sidebar_position: 2
description: runAs and runAsSystem — the fleet-wide contract for executing work as somebody, and the rules that keep it from becoming privilege escalation.
keywords:
  - runAs
  - runAsSystem
  - delegated identity
  - impersonation
  - RBAC
  - ADR-099
---

# Acting on Behalf of a User

OpenRegister owns the one implementation of "run this work as that user" for the
whole fleet. This page is the contract other apps bind to.

Canonical decision: **ADR-099** (hydra, `openspec/architecture/`).

## There is no Nextcloud `runAs()`

Worth stating plainly, because it is the first thing people look for. Nextcloud
core has no such function — verified against 33.0.0-dev and a code search across
the `nextcloud` org, where the only hits are `runAsBackgroundJob` in the
files-metadata event and `runAsUser` in Helm charts.

What core provides is a *mutable session subject* and two ways to set it:

| method | effect | use |
|---|---|---|
| `IUserSession::setUser()` | sets the active user **and writes `user_id` into the PHP session** | logging a user in |
| `IUserSession::setVolatileActiveUser()` | sets the active user, persists nothing (since 29.0) | **this** |

## `ObjectService::runAs(IUser $user, callable $operation)`

Runs `$operation` with `$user` as the acting identity, then restores whatever
identity preceded it — including when the operation throws.

```php
$rows = $this->objects->runAs($owner, fn (): array => $this->objects->findAll($query));
```

Four properties you can rely on:

- **It narrows, never widens.** A row `$user` cannot read stays unreadable. This
  is not a privilege grant; it is a restriction to somebody else's rights.
- **Nothing is persisted.** It uses `setVolatileActiveUser()`, so a request that
  dies before the `finally` — a fatal, an `exit()` — cannot leave the caller's
  session authenticated as the acted-as user.
- **It restores the previous identity, not null.** Nesting composes: an inner
  scope returning into an outer one leaves the outer identity in force.
- **It exists because the query layer has no acting-user parameter.**
  `MagicRbacHandler` and `MagicOrganizationHandler` read `IUserSession::getUser()`
  directly at roughly a dozen points, and the UID-keyed caches follow the session
  subject. Swapping the subject moves every reader in lockstep. Threading a
  `?IUser` through is the better end state and is not reachable in one change.

## `ObjectService::runAsSystem(callable $operation)`

Runs `$operation` as a trusted principal with **no user at all**. For work that
genuinely has nobody to act for: installation, migration, repair, and seeding the
app's own shipped data. A schema migration runs on nobody's behalf.

> 🔴 **Code-initiated only.** It must not be reachable from a flow node, a tool
> invoked by an agent, or the handling of an inbound request. Those three carry
> user-authored definitions or user-supplied input, and this method sits right
> next to every place a missing identity makes something fail. An escape hatch
> that turns a refusal into a success gets taken. Where an identity cannot be
> resolved the correct outcome is a **refusal naming what is missing**.

`SystemOperationContextBoundaryTest` pins the call-site set so adding one is a
deliberate act with a visible diff.

## Attribution is not authorization

Two different questions, two different fields, different lifetimes:

| field | answers | lifetime |
|---|---|---|
| `triggeredBy` | who *caused* this | immutable provenance |
| `runAs` | whose *rights* it executes with | re-evaluated every time work runs |

A scheduled run makes them differ: the cause is a schedule, the acting identity
is a person. **Never read `triggeredBy` to decide access.**

## Where a flow run's identity comes from

A flow has **no acting identity of its own**. `flow.owner` and
`flow.organisation` are ownership of the *definition* — who may edit it, which
tenant it belongs to — and are required on write. They are not a mandate to
execute as that person.

Identity enters through the **trigger**, because that is where a run begins and a
flow may carry several:

| trigger | acting identity |
|---|---|
| manual | the acting session user |
| object event | the user whose action raised the event |
| schedule | the `runAs` declared on that trigger node — **required** |
| sub-flow | the calling run's `runAs` |

A schedule trigger without a resolvable `runAs` **fails to save**
(`FlowTriggerValidator`), and a dispatch that cannot resolve one is refused at the
queue with `FlowUnattributed` rather than recorded and rejected node by node.

## Rules that keep this from becoming escalation

**Identity narrows along an invocation chain.** A callee may never widen the
identity it was invoked with by declaring one. A flow running as A that invokes an
agent declaring `actingUser: B` runs as A, or refuses.

**A caller-supplied context cannot choose the identity.** Run context is supplied
at queue time, so `context['runAs']` is ignored — the run's own value wins.
Honouring it would let anyone who can start a flow pick whose rights its steps use.

**Rights are re-resolved, never snapshotted.** At every fire, at every resume. A
run parked for three weeks answers to the rights its subject holds *now*. A
disabled account is refused, not just a deleted one — disabling is how a departure
is normally processed, and `IUserManager::get()` returns a disabled user happily.

**A dead identity under a schedule disables it, loudly.** The flow records the
refusal in `status_message` and the schedule is switched off. A schedule that
quietly stops firing is an instrument that lies.

## For app authors retiring a local copy

Five apps grew their own version of this — Integriq (`FlowOwner::runAs`), Buildiq
(`JobOwnerImpersonator::runAsOwner`), Humaniq (`HoursMigrationRunner::runAsActingUser`),
Dossiq (`SearchesObjects::runAsSystemIfAvailable`). All used `setUser()`, so all
carried the session-persistence hazard.

Retiring each is that app's own change. Bind to `ObjectService::runAs()` and
delete the local copy; the semantics above are the contract. Do not reimplement
the scope — six copies of a security-critical grammar is how they drifted in the
first place.
