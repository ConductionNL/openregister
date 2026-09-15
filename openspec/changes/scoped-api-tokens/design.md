# Design: scoped-api-tokens

## D-1: intersection, never substitution

The token does not carry rights of its own. It carries a filter over the
user's rights. So a token can only narrow, an issuer cannot mint what they
lack, and a revoked user takes every token with them without a sweep.

## D-1b: the OAuth2 scope rule is a special case

The existing OAuth2 requirement evaluates a token as a subset of the user's
groups. Under this change that is a grant whose `groups` list is the
scope set; the evaluator treats it as one more layer, so the two rules
cannot disagree.

## D-2: the same evaluator, one more input

`PermissionHandler` already layers schema RBAC, row-level and field-level
rules. The grant enters that chain as one more layer with the same match
grammar, so list filtering happens in SQL and no endpoint needs a second
check.

## D-3: no manage through a token

`manage` is how sharing and configuration are changed. A machine principal
that can widen its own audience is the failure iTop's scoping exists to
prevent, so the verb is not grantable.

## D-4: the grant is visible

`whoami` returns the effective grant and every write records the token id.
A supplier who is refused can read why; an auditor can see which token
wrote.

## D-5: kind

Code, in OpenRegister. Consuming apps issue tokens.

## D-C40-1. The end date is required, not offered

An optional expiry is an expiry nobody sets, and the six-year migration
token is the finding that follows. Issuing requires an end date, the
holder is warned before it lapses, and renewing is a deliberate act with
its own record.

## D-C40-2. A service account is owned by a team

An integration that runs as a person breaks when the person leaves, and
nobody can tell whose it was. A service account is owned by a team, holds
its own grants, and cannot sign in interactively, which is what stops it
becoming a shared login.

## D-C40-3. The outbound allowlist is checked at save

Checking a destination at delivery time means the failure arrives at three
in the morning, in a job, to nobody. Checking at save tells the person who
typed it, while they are still there.
