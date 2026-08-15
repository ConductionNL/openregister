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

**Measured across the fleet on 2026-08-15 — 504 of 571 declared schemas (88%)
carry no authorization block, across 15 apps:**

| app | marked | UNMARKED |
| --- | --- | --- |
| scholiq | 0 | **118** |
| shillinq | 0 | **114** |
| procest | 6 | **85** |
| openconnector | 0 | **39** |
| decidesk | 5 | **34** |
| hermiq | 1 | **28** |
| pipelinq | 1 | **26** |
| docudesk | 1 | **20** |
| openregister | 15 | **16** |
| larpingapp | 1 | **9** |
| portaliq | 0 | **9** |
| petstore | 0 | **3** |
| doriath · launchpad · nextcloud-app-template | 0 | **3** |
| openbuild · opencatalogi · softwarecatalog | 37 | 0 |
| **total** | **67** | **504** |

Three apps did the work. Fifteen did not — not out of carelessness, but
because omission has never had a consequence.

> **An earlier draft of this proposal said 321 of 368, across six apps.** That
> survey globbed `lib/Settings/*register*.json | head -1` and read ONE register
> file per app; openregister alone ships 14 and procest ships 2. The undercount
> hid 183 schemas and nine entire apps, including openconnector (39) and hermiq
> (28), which had appeared to have none at all. Corrected here and in
> `docs/rbac-unmarked-schema-audit.md`. The error made the case look smaller,
> never safer — but it is exactly the shape of mistake this change exists to
> stop being invisible.

This is not currently an open door on every surface: the HTTP object API was
measured refusing anonymous callers outright (`total=0` anonymous vs `total=8`
admin on the same query, with `_rbac=false&_multitenancy=false` making no
difference). The exposure is on the **in-process** path — a leaf app calling
`ObjectService` inside a `#[PublicPage]` controller. Measured on portaliq's own
content API: **6 pages returned to an anonymous caller** from a schema with no
authorization block.

**And that path is about to get much wider.** `portal-public-search` puts
anonymous full-text search over OR objects. Under today's default it would
make those 504 unmarked schemas searchable by anyone, and the first sign would
be a citizen finding somebody's payslip. The audit's own first pass proposes
**68 of them as restricted** — sessions, accounts, tokens, audit records,
invoices, submissions — on keyword evidence alone.

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
      change to audit. The 15 apps above are the population.

## Design notes

**This is a breaking change to a fleet contract, and it should be.** The
direction is safe — content becomes less visible, never more — so the failure
mode is a 404 someone reports, not a disclosure nobody notices. That asymmetry
is the entire argument.

**It cannot be shipped silently.** An app whose public surface depends on an
unmarked schema will go blank, and the operator needs to know why. The change
logs, once per schema, the fact that it refused on the default — so the audit
is a log grep rather than a survey of 571 schemas.

**A migration flag is deliberately NOT offered.** An `or.rbac.legacy_open=true`
escape hatch would be set once, during an upgrade, by an operator who wanted
their site back — and never unset. The flag would become the default in
practice and the fleet would keep the fail-open semantics with extra steps.

## Risks

- **Fifteen apps have unmarked schemas and some serve public content from them.**
  Auditing them is part of this change, not a follow-up. A count is not an
  audit: each app has to say which schemas it *intends* to be public.
- **Anything reading OR anonymously in-process will change behaviour.** That
  is the point, and it is why the audit precedes the flip.
- **A permission default is exactly the kind of change that looks fine in
  tests and breaks a tenant.** The tests must include an app-shaped case: a
  `#[PublicPage]` controller reading an unmarked schema, which must go from
  rows to none.
