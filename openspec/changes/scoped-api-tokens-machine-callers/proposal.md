---
kind: code
depends_on: [scoped-api-tokens]
---

# Proposal: scoped-api-tokens-machine-callers

## Summary

A machine write names the human it acts for, and an administrator provisions, lists and revokes machine credentials with a named owning team and contact, without a developer.

- Rows: 12.20 and 13.11 (none statutory).
- Wave 1, size M. Second part of a chain split from `openregister/scoped-api-tokens` to keep each issue at 20 tasks or fewer. It carries that change's service account (REQ-SAT-004, task C40.3) because the machine credential surface is built on it.
- Depends on `openregister/scoped-api-tokens` (https://github.com/ConductionNL/openregister/issues/4396): the token grant, `TokenGrantValidator` and the audit `actorVia` field. Build it after that change is merged. Whichever of this part and `openregister/rights-administration-hardening` (https://github.com/ConductionNL/openregister/issues/4395) lands second wires `GrantCeiling` into the issue path.
- No Ruben decision bears on it.
- Build rules: openspec/woo-build-rules.md

## Why

Two rows from the Woo capability programme (round 1 build plan, wave 1). Our column today, from the round 1 baseline:

| row | capability | ours today |
|---|---|---|
| 12.20 | A call that does not identify the acting human behind it is refused, not logged as anonymous | no: nothing requires a caller to name the acting human |
| 13.11 | A machine credential is provisioned, listed, named with an owner to contact, and revoked, without a developer | partial: a user creates, lists and revokes their own Nextcloud app passwords; no administrator surface provisions a machine credential with a named contact; REQ-SAT-004 (service account owned by a team) has no implementation |

## What changes

- **Acting human (12.20).** A setting `api.requireActingHuman` (instance, `off` by default) and a schema annotation `x-openregister-acting-human: required` make a write by a token, a Consumer, a service account or a system caller without a named human refused with 403. The human is named in the header `X-OpenRegister-Acting-User` and verified with `DelegationResolver::resolve()` against an active delegation grant from that user to the calling principal (the existing `or-delegation-grants` consent). A named human without a grant is refused the same way. A verified write records `actingUser` and `actorVia` on its audit row. Reads are not affected. A session user acting for themself is the acting human and is never refused.
- **Machine credentials an administrator manages (13.11).** REQ-SAT-004's service account is built: an OpenRegister principal backed by a Nextcloud user that cannot sign in interactively, owned by a group (the team) and carrying a contact (name and e-mail) and a purpose. `/api/admin/machine-credentials` lets an administrator provision a service account and issue its token (grant and end date required, the token shown once), list every machine credential on the instance (service account tokens and Consumers) with owner team, contact, grant summary, expiry and last use, and revoke any one. A page under the OpenRegister administration settings does the same. Every provision, issue and revoke writes an administrative audit row.

The design of the service account is D-C40-2 in `openspec/changes/scoped-api-tokens/design.md`.

## What does not change

- The token grant, its end date, rate limit and outbound allowlist: those stay in `scoped-api-tokens`.
- Reads by a machine caller.

## Dependencies

- `scoped-api-tokens`, merged first.
- The delegation grants are built (`openspec/specs/delegation-grants`). No other app is called.

## Wave and decision

Wave 1, size M. No decision bears on it. Closes 12.20 and 13.11.
