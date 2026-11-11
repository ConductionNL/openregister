# Design: object-quota-per-organisation

## D-1. The cap is declared on the schema

The schema is where OpenRegister already declares what a create must
satisfy (unique constraints, lifecycle initial state). A positive integer
only: zero, negatives, strings and floats are read as "no quota", so a
typo can never become a cap of zero that refuses every create.

## D-2. The count is the organisation's real total

Counted with `MagicMapper::searchObjects(['@self' => {register, schema,
organisation}, '_count' => true], _rbac: false, _multitenancy: false)`.
Counting with the creating user's rights would let a user who reads only
their own rows create past the cap. Soft-deleted rows are excluded, as in
every list. A store that answers a list where a count was asked is treated
as a failure, never as zero.

## D-3. Refused through the event, fail-soft on a broken count

The listener uses the refusal idiom of `UniqueConstraintListener`
(`setErrors` plus `stopPropagation`). When the count itself fails, the
create goes through with a warning: a quota is a resource limit, not an
access rule, and a broken count must not stop an organisation's work.

## D-4. Only creates, only objects in an organisation

An update never adds an object. An object in no organisation is not
subject to a per-organisation cap.
