---
kind: code
depends_on: []
---

# Proposal: several-legal-entities-in-one-instance

## Summary

A gemeenschappelijke regeling is several legal entities sharing one back
office. Each keeps its own records and its own responsibility, and they
share the code lists, the case types and the parties. OpenRegister
separates tenants well and shares nothing between them. This change adds
shared master data across organisations, a move of a live object graph
from one organisation to another, and logging that names a tenant without
naming a person.

## Candidates and cluster

Cluster 66 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "More than one legal
entity in one instance". Owner openregister, size L, four candidates:
C-case-core-14, C-access-and-privacy-51, C-access-and-privacy-68 and
C-configuration-72. Highest relevance `should`, no `must`, no matrix hole.
Passers: 4, three driven and one documented. dossiq rates `partial` on all
four and `no` on none, which is one of only four clusters in the sweep
with no `no` at all.

Ledger row the candidate notes name: 17.1.

**The build plan rates this cluster already specified.** Its "Already
specified, so no new change is needed" table lists it against "openregister
tenancy and dossiq `TenantAuthenticationService`", with "re-rate: all four
members read `partial`, none reads `no`" as what is left. That reading is
right about isolation and wrong about sharing. What follows names the four
requirements the existing specs do not carry, and the re-rate is a task in
this change rather than an argument against it.

## The decisions this rests on

**D21, documented candidates admitted and labelled.**
C-access-and-privacy-51 has no driven passer: decos-join, "documented,
/zaaksysteem/joni-plus". It is in scope as documented and is never counted
in a driven tally.

**D6, relevance-led promotion.** No member is a `must`, so the cluster
enters on relevance rather than on a passer count. It is wave 3 for the
same reason: every member is L and none of them blocks a tender answer.

## Why

The proving system is glpi, cited by the case core lane at
`case-core.tsv:26`: "Transfer between entities
(front/transfer.action.php, src/Transfer.php:50)". A live record and its
whole sub-item graph move to another entity, with a policy per object type
that says move, copy or drop. That is C-case-core-14, and the municipal
reading is plain: a case that turns out to belong to the omgevingsdienst
or to a neighbouring gemeente.

The second driven passer is odoo, cited at `access-and-privacy.tsv:85`:
"res_company.py, company_id on every model", for C-access-and-privacy-68,
several legal entities in one instance with their records separated and
their master data shared. A gemeenschappelijke regeling or a
samenwerkingsverband is exactly that shape.

The third is plane at `configuration.tsv:73` for C-configuration-72:
"apps/admin and the application share credentials but not a session". Two
surfaces, one identity, separate sessions.

The dossiq reading names the halves that exist:
"lib/Service/CaseTransferService.php federates a case to another instance
with accept and reject; no per-object-type policy",
"TenantOrganisationResolver.php is a tenancy layer with no shared-master
story", and "lib/Service/TenantAuditTrailService.php".

**What exists here and does not close it.** ADR-002 makes a tenant an
`Organisation` keyed by UUID and forbids using a Nextcloud group as a
tenancy boundary. `saas-multi-tenant` carries the isolation model, the
tenant context resolution and the tenant-scoped queries.
`tenant-lifecycle` carries provisioning, the status transitions,
deprovisioning with retention and a purge that touches only its own
organisation. `tenant-isolation-audit` logs cross-tenant access attempts
and blocks suspended organisations at the middleware. `tenant-quotas`
bounds requests, storage and bandwidth. Together they are a strong
separation story and no sharing story at all: nothing lets two
organisations read one code list, nothing moves a live object between
organisations, nothing keeps a token out of a log line, and the
administration surface signs in exactly like the product.

## What changes

- **A register or a schema may be shared master data.** An organisation
  marked as the holder owns the rows; the organisations that consume it
  read them and cannot write them. A code list, a case type and a party
  are the three the corpus asks for. The sharing is declared, and a shared
  row is read through the same query path, not copied.
- **An object graph moves between organisations, with a policy per object
  type.** The policy says move, copy or drop for each type under the root.
  The move is previewed first, naming every object and its policy, and it
  is one act on the audit trail of both organisations.
- **A log line names the tenant, not the person, and never the secret.**
  Application and audit logging carries the organisation UUID and a
  pseudonymous actor reference. A token, a password and a credential value
  are redacted before the line is written, and a redaction that fails
  drops the line rather than writing the value.
- **The administration surface signs in separately.** Entering the
  administration of an organisation requires a fresh authentication, and
  that elevated session expires on its own. It is the same identity, a
  different session.

## Consumers

- **dossiq**: `TenantAuthenticationService` and `TenantOrganisationResolver`
  consume the shared master declaration, and `CaseTransferService` moves a
  case between organisations rather than only between instances.
- **opencatalogi and stackiq**: one publication catalogue shared by every
  organisation in a samenwerkingsverband.
- **humaniq**: one employee register held by the holder, read by each
  entity.
- **integriq**: one connection configuration shared where the entities
  share a gateway.

## ADRs

- ADR-002: the organisation UUID stays the only tenant key. Shared master
  data is a declared read across organisations, never a second tenancy
  mechanism.
- ADR-022: one tenancy model in the object layer, consumed by every leaf
  app.
- ADR-005: the move fails closed, and a shared row is read-only to a
  consumer by construction rather than by a screen that hides the button.
- ADR-003: the move between organisations is an audit fact on both chains.

## Impact

- Extends: `saas-multi-tenant` (shared master data and the move) and
  `tenant-isolation-audit` (the pseudonymous log line and the elevated
  session).
- Affected code: the organisation resolver and the tenant-scoped query
  handler, the transfer service, the logging context, the settings
  middleware.
- Backwards compatible: an instance with one organisation, and an instance
  that declares no shared master data, behaves as today.
- Size: L.

## Out of scope

- Provisioning, quotas and deprovisioning, which `tenant-lifecycle` and
  `tenant-quotas` already carry.
- Federating a case to another instance, which is a different act and
  stays with `organisation-as-federated-counterparty`.
- The identity broker behind the second sign-in. D8 puts portal identity in
  portaliq; this change asks only for a separate session.
