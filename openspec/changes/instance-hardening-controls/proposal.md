---
kind: code
depends_on: []
---

# Proposal: instance-hardening-controls

## Summary

Eighteen candidates, eighteen dossiq `no`. This is the only cluster in the
sweep that dossiq fails completely, and most of it is small: a statement a
user accepts before use, a second authentication before administration, a
notification that carries no case content to an address nobody verified, a
grant that pauses when it is unusually large, and a refusal to remove the
last administrator. Each one is a control a chief information security
officer asks for by name, and each one is cheap.

## Candidates and cluster

Cluster 4 of `procest/_round4/discovery/build-plan.md`
(ConductionNL/market-intelligence, 2026-09-14), "Security hardening of the
instance". Owner openregister, size M, eighteen candidates:
C-access-and-privacy-6, -7, -8, -9, -15, -16, -17, -18, -29, -30, -31,
-38, -40, -66, -72, -76, -85 and -87. Four are `must` and two are matrix
holes: C-access-and-privacy-29 and C-access-and-privacy-31. Passers: 20,
seventeen driven and three documented. dossiq rates `no` on all eighteen,
the heaviest cluster in the file by that measure.

## The decisions this rests on

**D6, relevance-led promotion.** Both holes are `must` with two or more
driven passers and no row in the corpus: a second factor scoped to a group
(osticket, redmine) and an accepted statement with its version recorded
(openproject, otobo, taiga, znuny).

**D21, documented candidates admitted and labelled.** Three members have
no driven passer: C-access-and-privacy-6, C-access-and-privacy-8 and
C-access-and-privacy-72, all from jira-data-center or
jira-service-management. Two are in scope as documented and one is
recorded. None is counted in a driven tally.

## Why

The proving system is otobo, cited by the access lane at
`access-and-privacy.tsv:13`: "Customer accept (AdminCustomerAccept.pm,
CustomerAccept.pm, shown before the dashboard on first login)". A
statement the administrator publishes, accepted before use, with who
accepted which version recorded. That is C-access-and-privacy-31, a `must`
and a matrix hole, with openproject, otobo, taiga and znuny driven. The
AVG informatieplicht is the duty; the acceptance record is the evidence.

The other members and their driven passers:

- **A second factor required by policy, scoped** (C-access-and-privacy-29,
  `must`, a hole): osticket, "Two-factor (include/class.2fa.php:11
  TwoFactorAuthenticationBackend, pluggable per backend, agents and users
  separately)", and redmine. The dossiq lane is precise about why this is
  not already answered: "the platform sets 2FA instance-wide".
- **Step-up authentication before administration, expiring**
  (C-access-and-privacy-38, `must`, documented): jira-data-center, "REST
  API, websudo group, and Secured secrets by default". dossiq's own note:
  "Nextcloud asks for the password again on some settings screens and
  dossiq's own settings are not behind it".
- **A notification to an unverified recipient carries no case content**
  (C-access-and-privacy-8, `must`, documented): jira-service-management,
  "About safe customer notifications". The mail that quotes a dossier to a
  guessed address.
- **A sensitive surface reachable only from named addresses**
  (C-access-and-privacy-30): freescout and gitlab, "The same endpoints
  behind an IP allowlist".
- **A person is barred from interacting while what they wrote stays**
  (C-access-and-privacy-15): forgejo and gitea, "/user/blocks,
  /user/block/{username}, /orgs/{org}/blocks, /issues/{index}/list_blocked".
  Misbruik van recht under the Woo is a real and growing case, and we have
  no mechanism at all.
- **An expression reads an environment variable only from an allowlist**
  (C-access-and-privacy-40): valtimo, "Value resolvers
  (value-resolvers/spec.md)". An expression language that can read the
  environment is a data exfiltration path.
- **Remote images in an inbound message are not fetched until allowed**
  (C-access-and-privacy-66): freescout, "Manage, Modules,
  block-external-images". A tracking pixel in a bezwaar mail otherwise
  reports the handler's address to the sender.
- **The product refuses to remove the last administrator**
  (C-access-and-privacy-76): zammad, "Roles with seat and last-admin
  guards (app/models/role.rb:25,27)".
- **Indexing by search engines is an administered choice**
  (C-access-and-privacy-85): zammad, "Robots
  (config/routes/robots_txt.rb)". A Woo publication should be findable and
  a zaakportaal sign-in page should not.
- **An unusually large grant is flagged** (C-access-and-privacy-6,
  documented): jira-data-center, "Moderating user group activity with
  Safeguards".

**What exists here and does not close it.** `field-level-encryption`
already specifies encryption per flagged property, authorised decryption
and exclusion from search, which answers C-access-and-privacy-7 in full.
`credential-broker` and ADR-004 hold credential custody, so
C-access-and-privacy-72 needs only an external source rather than a new
custody model. `account-self-service` gives a user their own account page
and their own tokens, and `permission-provenance-and-deny` will tell them
which grants they hold, which together answer C-access-and-privacy-16.
Nextcloud owns passwords, sessions and the second factor itself. What is
missing is everything that scopes those platform controls to this
application's data, and every control the platform does not have at all.

## What changes

- **Every control is reported against a floor it cannot fall below.** An
  administrator reads one page: the password and session policy as Nextcloud
  enforces them, the rate limit on each surface, the brute-force state, the
  origins a browser may read from, and the upload ceiling. Each carries the
  floor this instance declared. A change that would cross a floor is refused,
  a floor may not be declared weaker than the shipped baseline, and both the
  change and the refusal are on the audit trail.
- **A published statement is accepted before use.** An administrator
  publishes a statement with a version. A user is asked once per version
  before the application renders, and the acceptance is recorded with the
  user, the version and the time. A new version asks again.
- **Administration needs a fresh authentication, and it expires.** Opening
  the administration surface requires authenticating again. The elevated
  session expires after an administered period, after which an
  administration write is refused. It is the same identity, a second
  session.
- **A scope may require a verified second factor.** A register or a schema
  may declare that reading it requires a second factor. Nextcloud enforces
  the factor; this declares where it is required, which is the scoping the
  platform lacks.
- **A sensitive surface may be bound to named addresses.** The
  administration surface and the API may each carry an address allowlist.
  A refusal names neither the allowlist nor the addresses on it.
- **A notification to an unverified address carries no content.** Until an
  address is verified, a notification to it names the fact that there is
  something to read and links to it, and carries no case data.
- **Remote content in an inbound message is not fetched until allowed.**
  Images and other remote references are held; the reader allows them per
  message, and the choice is recorded.
- **The last administrator cannot be removed.** Removing the last account
  holding administration of a register, a schema or an organisation is
  refused, naming what would be left without one.
- **An unusually large grant pauses.** A grant that would give access to
  more than an administered share of a register in one act is held for a
  second administrator to confirm, and the attempt is recorded either way.
- **An expression reads only allowlisted environment variables.** The
  allowlist is administered; anything else resolves as absent, and the
  attempt is logged.
- **Indexing by search engines is administered per public surface.** A
  published surface declares whether it may be indexed, and the instance
  answers accordingly.
- **A credential value may come from an external secret manager.** The
  broker gains a source that resolves a value at use time rather than
  storing it, keeping custody where ADR-004 puts it.
- **A person may be barred from interacting.** What they already wrote
  stays and stays attributed. The bar is recorded with a reason and is
  reversible.

## Consumers

- **dossiq**: publishes the privacy statement, declares which registers
  need a second factor, and bars a vexatious requester without deleting
  the record.
- **portaliq**: the unverified-recipient rule is what makes a portal
  notification safe to send before an address is confirmed.
- **opencatalogi**: declares that a Woo publication may be indexed and a
  sign-in page may not.
- **every fleet app**: the last-administrator guard and the elevated
  session with no work per app.

## ADRs

- ADR-005: every control here fails closed. An unresolvable allowlist, an
  unverifiable address and an unreadable statement version all refuse.
- ADR-004: credential custody stays with the broker. An external secret
  manager is a source, never a second store.
- ADR-003: acceptance, elevation, a bar and a held grant are audit facts on
  the chain.
- ADR-022: these are platform controls, specified once and declared by leaf
  apps.

## Impact

- Extends: a new `instance-hardening` capability, beside
  `field-level-encryption` and `credential-broker`, which keep what they
  already carry.
- Affected code: the bootstrap that renders the application, the settings
  middleware, the notification recipient resolution, the expression
  evaluator's environment resolver, the permission write path.
- Backwards compatible: an instance that publishes no statement, declares
  no second-factor scope and sets no allowlist behaves as today.
- Size: M.

## Out of scope

- Field encryption (C-access-and-privacy-7), which
  `field-level-encryption` already specifies in full.
- Password ageing (C-access-and-privacy-9) and the session list
  (C-access-and-privacy-18). Nextcloud owns both, and saying so is worth
  more than claiming them.
- Removing yourself from a record you were given access to
  (C-access-and-privacy-17), which is
  `object-level-sharing-and-private-scope`.
- Reading your own roles (C-access-and-privacy-16), which is
  `account-self-service` plus `permission-provenance-and-deny`.
- An on-call rota (C-access-and-privacy-87, documented). D19 puts
  rostering in humaniq.
