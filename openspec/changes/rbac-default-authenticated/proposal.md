---
kind: code
---

# Proposal: rbac-default-authenticated

## Summary

A schema that declares no authorization is currently readable by ANYONE,
including an anonymous caller. Make the absent-authorization default
**authenticated-only**. `public` becomes something an author must assign
deliberately, and can never be arrived at by omission.

## Motivation

The default is fail-OPEN, and it is the wrong way round.

`PropertyRbacHandler` reads, in as many words:

```php
// If no authorization is defined for this property, it follows object-level rules.
if ($authorization === null || empty($authorization) === true) {
    return true;
}
// If action is not configured, property is accessible.
```

and `userQualifiesForGroup()` returns `true` unconditionally for the group
`public`. So "nobody said anything" and "everybody may read this" are the same
state, and they are reached by writing nothing at all.

**Measured across the fleet on 2026-08-15 — 321 of 368 declared schemas (87%)
carry no authorization block:**

| app | schemas | with authorization | none |
| --- | --- | --- | --- |
| scholiq | 118 | 0 | **118** |
| shillinq | 114 | 0 | **114** |
| decidesk | 39 | 5 | **34** |
| pipelinq | 27 | 1 | **26** |
| docudesk | 21 | 1 | **20** |
| portaliq | 9 | 0 | **9** |
| opencatalogi | 10 | 10 | 0 |
| softwarecatalog | 21 | 21 | 0 |
| procest | 6 | 6 | 0 |
| openregister | 3 | 3 | 0 |

Four apps did the work. Six did not — not out of carelessness, but because
omission has never had a consequence.

This is not currently an open door on every surface: the HTTP object API was
measured refusing anonymous callers outright (`total=0` anonymous vs `total=8`
admin on the same query, with `_rbac=false&_multitenancy=false` making no
difference). The exposure is on the **in-process** path — a leaf app calling
`ObjectService` inside a `#[PublicPage]` controller. Measured on portaliq's own
content API: **6 pages returned to an anonymous caller** from a schema with no
authorization block.

**And that path is about to get much wider.** `portal-public-search` puts
anonymous full-text search over OR objects. Under today's default it would
make those 321 unmarked schemas searchable by anyone, and the first sign would
be a citizen finding somebody's payslip.

## Decision

Absent authorization means **`authenticated`**, not "anyone".

- No authorization block on a schema, and none on its register → read requires
  a logged-in user.
- `public` is granted only where an author wrote `"group": "public"`.
- Everything already explicit keeps its exact current behaviour.

The register-level fallback stays: schema authorization → register
authorization → the new authenticated default. Only the final rung changes.

## Affected Projects

- [ ] `openregister` — the default in the RBAC handlers, and the tests that
      pin it.
- [ ] Every app with unmarked schemas — **no code change**, but a behaviour
      change to audit. The six apps above are the population.

## Design notes

**This is a breaking change to a fleet contract, and it should be.** The
direction is safe — content becomes less visible, never more — so the failure
mode is a 404 someone reports, not a disclosure nobody notices. That asymmetry
is the entire argument.

**It cannot be shipped silently.** An app whose public surface depends on an
unmarked schema will go blank, and the operator needs to know why. The change
logs, once per schema, the fact that it refused on the default — so the audit
is a log grep rather than a survey of 368 schemas.

**A migration flag is deliberately NOT offered.** An `or.rbac.legacy_open=true`
escape hatch would be set once, during an upgrade, by an operator who wanted
their site back — and never unset. The flag would become the default in
practice and the fleet would keep the fail-open semantics with extra steps.

## Risks

- **Six apps have unmarked schemas and some serve public content from them.**
  Auditing them is part of this change, not a follow-up. A count is not an
  audit: each app has to say which schemas it *intends* to be public.
- **Anything reading OR anonymously in-process will change behaviour.** That
  is the point, and it is why the audit precedes the flip.
- **A permission default is exactly the kind of change that looks fine in
  tests and breaks a tenant.** The tests must include an app-shaped case: a
  `#[PublicPage]` controller reading an unmarked schema, which must go from
  rows to none.
