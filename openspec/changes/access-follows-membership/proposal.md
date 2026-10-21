---
kind: code
depends_on: []
---

# Proposal: access-follows-membership

## Summary

A bookkeeper in shillinq may read the rows of the administrations they are a
member of, and no others. The membership is a record: an
`AdministrationMembership` row that says user `anna` has role `boekhouder` in
administration `adm-1` from 1 January. OpenRegister's authorization rules can
compare an object field with the caller's user id, groups, organisation or
the current time, but not with values held in the caller's rows of another
schema. So the isolation cannot be declared today, and shillinq enforces it
in its own service layer, which ADR-023 Rule 1 forbids and which list queries
through the plain object API do not pass through at all.

This change adds one match operator, `$memberOf`. It admits an object when
the value of one of its fields is in the set of values that the caller's rows
in a membership schema hold. The same need exists wherever an app has a
tenant-like scope that is not a Nextcloud organisation: a case team, a
school, a project, a board.

## What changes

- A `match` property MAY take `{ "$memberOf": { ... } }`. The operand names
  the membership schema (and optionally its register), the membership field
  whose values form the set, the membership field that holds the caller's
  user id, optional static filters (for a role) and an optional validity
  window.
- The operator is resolved by the one token resolver the whole RBAC stack
  shares (`ConditionMatcher::resolveDynamicValue()`), into `{ "$in": [ ... ] }`.
  So the list query, the count, the single-object verdict, property-level
  rules and deny rules all read the same set, and the list predicate sits in
  the SQL `WHERE`, where pagination and counts are computed.
- The caller's set is read once per request per caller and per operand.
- Every failure denies: no caller, a malformed operand, a membership schema
  that cannot be found or read. Nothing is admitted by an error.

## Example

```json
"authorization": {
  "read": [
    {
      "group": "shillinq_bookkeeper",
      "match": {
        "administrationId": {
          "$memberOf": {
            "schema": "administration-membership",
            "register": "shillinq",
            "property": "administrationId",
            "match": "userId",
            "where": { "role": { "$in": ["boekhouder", "controller"] } },
            "current": { "from": "validFrom", "until": "validUntil" }
          }
        }
      }
    }
  ]
}
```

## Out of scope

- `$notMemberOf`. Nobody has asked for exclusion by membership.
- Dynamic tokens inside `where`. The filters are literals; the caller is
  bound by `match`.
- A membership that itself follows a membership (nested `$memberOf`).
- A management UI for the operator. Schemas declare it in their register
  JSON, as every other match rule.

## Impact

- New `lib/Service/Rbac/MemberOfResolver.php`.
- `lib/Service/ConditionMatcher.php` resolves `$memberOf`.
- No change to `MagicRbacHandler` or `PermissionHandler`: both already
  delegate token resolution to `ConditionMatcher`.
- No migration. Existing rules are unchanged.
- Consumers: shillinq (administration isolation). Other apps with
  tenant-like scopes can adopt it without code.

## ADR fit

- ADR-023 Rule 1: data RBAC is OpenRegister's job, and a gap is fixed in
  OpenRegister. This is that fix; shillinq can drop its own guard.
- ADR-031: the behaviour is declared in the schema's authorization block, not
  written as an app service.
- ADR-011 (OpenRegister): one resolver, so list and find cannot disagree.
