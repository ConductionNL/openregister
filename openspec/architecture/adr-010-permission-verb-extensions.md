# ADR-010: Permission verbs — a uniform core set, with governed per-schema extensions

**Status**: accepted

**Date**: 2026-08-02

## Context

Object-level sharing gives an owner a way to grant one principal access to one
object. A grant has to say access *to do what*, and that immediately raises the
question of vocabulary.

Nextcloud core has five permission verbs, carried as a bitmask on every share:
`READ`, `UPDATE`, `CREATE`, `DELETE`, `SHARE`. OpenRegister has more concepts
than that. A brokered credential can be *used* — spent through the broker —
which is a strictly stronger thing than being able to see that it exists. A flow
can be *run*. ZGW's zaakafhandeling has verbs like `besluit_nemen`. None of
those are core verbs, and none of them fit cleanly into the five.

Two failure modes are available, and both are cheap to fall into.

The first is to invent a parallel permission model, so that a grant carries
OpenRegister's own verb set. That gives two vocabularies to keep in step and two
places to enforce them, and this codebase has already paid for that mistake once
— the predecessor change found two access-control divergences between four
enforcement paths in a single week, each one a second copy of a rule.

The second is to let every schema define whatever verbs it likes and have RBAC
enforce them. That makes the RBAC evaluator responsible for a vocabulary it
cannot know, and turns "which verbs exist" into an unbounded, per-tenant
question that no reviewer can hold in their head.

## Decision

**A uniform core verb set, mapped onto core's bitmask. Extensions are allowed,
declared by the schema, and enforced at the endpoint that performs the action —
never by widening the RBAC vocabulary.**

### Rule 1 — The core set is core's bitmask, unchanged

`read`, `update`, `create`, `delete` and `share` map one-to-one onto
`OCP\Constants::PERMISSION_*`. A grant carries core's bitmask, and
`ObjectGrantResolver::permissionFor()` is the single place the mapping lives.
There is no OpenRegister-side permission integer.

### Rule 2 — An action with no core bit is NOT admitted by a grant

`permissionFor()` returns `null` for anything outside the five, and the callers
fail closed. A grant cannot admit `besluit_nemen`, because a grant has no way to
carry it.

This is deliberate and it is the load-bearing rule. It means the RBAC evaluator
never has to know what an extension verb means: it can only ever answer for the
five it does know, and it answers "no" for the rest.

### Rule 3 — An extension verb SHALL be declared by its schema

A schema that needs a verb beyond the core five declares it. An undeclared verb
is not a verb; it is a typo, and it is refused.

### Rule 4 — An extension verb SHALL be enforced at the endpoint that performs it

RBAC grants *visibility*. Whether this caller may `run` this flow, or `use` this
credential, is decided by the code that runs the flow or spends the credential —
which is the only code that understands what the verb costs. The credential
broker's guard chain is the worked example: it decides `use` itself, in one
fail-closed sequence, and does not ask RBAC to.

Where a grant needs to carry an extension verb across the wire, it rides in
`IShare`'s `IAttributes` bag — the extension point other Nextcloud apps already
use — and not in the permission integer.

### Rule 5 — An extension verb SHALL NOT redefine a core verb

A schema may not declare `read` to mean something other than read. The core five
have one meaning across every register, schema and app, because that is what
lets one shares component render a permission picker without being taught each
schema.

## Consequences

- (+) One permission vocabulary in RBAC, and it is core's — so a grant, a file
  share and a folder share all mean the same thing by the same bitmask.
- (+) The RBAC evaluator stays finite and reviewable. "Which verbs can RBAC
  decide" has a five-item answer that does not vary by tenant.
- (+) Fail-closed by construction for anything unknown: a new verb is invisible
  to grants until somebody deliberately enforces it somewhere.
- (−) Two enforcement points exist — RBAC for visibility, the acting endpoint
  for the verb — and a reader has to know that. This ADR is the mitigation.
- (−) An extension verb cannot be granted to a principal through the sharing UI
  today. It can be *declared* and enforced; carrying it on a grant needs the
  `IAttributes` half, which is not built yet.

## Cross-references

- ADR-004 (credential broker custody) — the worked example of Rule 4: `use` is
  decided by the broker's fail-closed guard chain, not by RBAC.
- Company ADR-011 (reuse, don't reimplement) — the same instinct, applied to
  evaluators rather than to utilities.
- `openspec/changes/object-level-sharing-and-private-scope/design.md`, Q5 and Q6
  — where this was settled, including why `read` and `use` are separate verbs
  and why `use` implies `read`.
- `ObjectGrantResolver::permissionFor()` — Rules 1 and 2, in code.
