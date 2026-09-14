---
kind: code
---

# Proposal: scoped-api-tokens

## Summary

Let an API principal be narrower than the person who issued it. A personal
API token (`auth-system`, `createToken`) and a Consumer (`auth-system`,
"API consumers MUST be configurable entities") both resolve to a Nextcloud
user and inherit everything that user may do. A supplier given a token today
gets the handler's whole desk. This change adds a grant on a token and on a
Consumer: registers, schemas, verbs and an optional row condition, which
the permission handler intersects with the user's rights on every check.

## Ledger rows

| row | capability | rating | size |
|---|---|---|---|
| Q13.20 | Can an API token be narrower than the person who issued it | partial | M |

## Why

The register's note: "Batch 1 rated `partial` and recorded no path for
dossiq. The note beside the row: a supplier given a token today gets
whatever that token carries." The best competitor, verbatim from the
`best` column: "iTop 3.2: scoped tokens, the best answer in the corpus
(`_round4/compare/proposed-rows-batch5.md`)".

The register's `why`: "an API principal narrower than the person is the
API authorization layer; a Nextcloud app password is full rights".

There is prior art in `auth-system` itself: the requirement "OAuth2 token
scopes MUST translate to RBAC verdicts" narrows an OAuth2 token to a subset
of the user's groups, so a token with `scope: "leesrechten"` is evaluated as
if the user were only in that group. It covers one authentication type and
one axis (groups). This change keeps that intersection rule and generalises
it: any token or Consumer, and a grant by register, schema, verb and row
condition rather than by group.

ADR-091 draws the boundary this change respects: the protocol that
validates a credential is OpenConnector's. What this change adds is not a
credential scheme but a grant that any resolved principal carries, the way
ADR-095 gives an agent a structured tool grant.

## What changes

- A personal API token and a Consumer accept an optional `grant`:
  `{ "registers": [...], "schemas": [...], "verbs": ["read", ...],
  "match": { ...conditional rule... }, "expiresAt": ... }`. Absent grant
  means the user's full rights, as today.
- The permission handler intersects the grant with the user's RBAC on every
  read, list, write and action: a request outside the grant is refused as
  if the user had no right, and list results are filtered by it, at the SQL
  level like row-level security.
- The verbs are the ADR-010 set (`read`, `create`, `update`, `delete`,
  `share`, plus governed per-schema extensions); `manage` cannot be
  granted to a token.
- `GET /api/whoami` reports the effective grant so a caller can see what it
  holds; the audit trail records the token id as `actorVia` on every write
  made through it.
- Issuing a grant wider than the issuer's own rights is refused; a token's
  grant shrinks automatically when the issuer's rights shrink (the
  intersection does it).
- The user self-service token page and the Consumer admin page gain a grant
  editor with a preview against a chosen object.

## Consumers

- dossiq: hand a supplier a scoped token instead of a user. Specified in
  dossiq by the dossiq lane (register row Q13.20).
- integriq: an OpenConnector endpoint that authenticates a counterparty maps
  it to a Consumer whose grant bounds what the flow behind it may touch.
- portaliq (partner tasks), stackiq (vendor updates), keepiq.

## ADRs

- ADR-091: the credential check stays with OpenConnector; the grant is the
  authorization layer's.
- ADR-095: a grant is a structure, not a string.
- ADR-023 rule 1: data RBAC is OpenRegister's, and the token narrows it.
- ADR-099: a granted, scoped identity.
- openregister ADR-010 (permission verbs).

## Impact

- Extends: `auth-system` requirements "API consumers MUST be configurable
  entities that bridge external systems to Nextcloud identities" and "The
  system MUST provide personal-data, activity, notification, token, and
  account-deactivation self-service"; `rbac-scopes`.
- Affected code: `lib/Db/Consumer.php`, the personal token store,
  `PermissionHandler` (grant intersection), the SQL RBAC builder, the audit
  writer (`actorVia`), two settings surfaces.
- Size: M.

## Discovery cluster 40 extension (2026-09-14)

The round 4 discovery sweep in ConductionNL/market-intelligence,
`procest/_round4/discovery/build-plan.md`, names this change as the
vehicle for cluster 40, "Tokens, service accounts and their expiry". Owner
openregister, size M, depends on roles, grants and their provenance,
decision D22, five candidates: C-access-and-privacy-35, -42, -43, -44 and
-70. One is a `must` and a matrix hole: C-access-and-privacy-43. Passers:
6, five driven and one documented. dossiq rates `partial` on two and `no`
on three.

**D22 as taken** puts access inside the query in openregister, which is
what lets a token's grant narrow a query rather than filter a result.

- **An issued credential carries an end date the product enforces, and the
  holder is warned before it lapses** (C-access-and-privacy-43, `must`, a
  hole): request-tracker, "Preferences, Auth Tokens
  (share/html/Prefs/AuthTokens.html), sbin/rt-email-expiring-auth-tokens.in",
  and vikunja. A leverancier gets a token for a migration that runs six
  weeks and keeps it for six years.
- **An integration gets an account of its own, apart from staff accounts**
  (C-access-and-privacy-42): gitlab, "Admin, Settings, Service accounts",
  and vikunja. Today an integration runs as a named person and their
  leaving breaks it.
- **An issued token carries its own rate limit** (C-access-and-privacy-44,
  `could`): plane, "API tokens, api.py:39 allowed_rate_limit default
  60/min per token".
- **The addresses the product may call out to are an administered
  allowlist** (C-access-and-privacy-70): plane, "Webhooks,
  webhook.py:21-31 url validated to http or https and refused for
  localhost".
- **A user sees every token issued in their name and revokes one**
  (C-access-and-privacy-35): openproject, "/my/access_tokens". This one is
  already specified: `account-self-service` requires that the account page
  lists and manages the signed-in user's personal API tokens. The
  extension adds the expiry and the service account beside it, not the
  listing.

**What the extension adds.**

- **A token carries a required end date.** Issued with one, enforced at
  use, and the holder warned before it lapses. A token with no end date is
  not issued.
- **A service account is a principal, not a person.** It holds grants,
  carries tokens, has an owning team rather than an owning person, and
  survives that person leaving. It cannot sign in interactively.
- **A token carries its own rate limit.** Set at issue, refused over it,
  named in the refusal, so one runaway koppeling cannot take the case
  system down for the balie.
- **Outbound destinations are an administered allowlist.** A webhook or an
  automation URL is checked against it, and a destination outside it is
  refused at save rather than at delivery.

The per-caller call record and the source-address binding on a token sit
in `api-as-a-versioned-surface`, which specifies the API surface as a
whole. Both changes name the other so that neither writes a second answer.

**One inherited finding, reported not fixed.** The target spec
`specs/auth-system/spec.md` carries a requirement header at line 888 that
sits outside its `## Requirements` section, so that requirement is
invisible to validate, list and archive, and `openspec archive` refuses
any delta against the spec until it is repaired. The original proposal
already noted the same requirement as invisible. It is on a line neither
this change nor its extension touches, so it belongs to the debt sweep.
