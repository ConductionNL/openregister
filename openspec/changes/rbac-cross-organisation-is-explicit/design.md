## Decision

Ruben, 3 Nov 2026: multitenancy is maintained by OpenRegister, not by the apps. Every read rule keeps the caller's organisation filter unless the rule says `crossOrganisation: true`.

## The read boundary for a signed-in, non-admin caller

```
organisation filter (own organisations, shared master data holders, cascade org-less rows)
  OR  match of marked rule 1 the caller holds
  OR  match of marked rule 2 the caller holds ...
AND  RBAC predicate (every rule the caller holds, ORed)
```

- A marked rule the caller holds with no match (or an empty match) lifts the filter: it reads every row, so no filter can narrow it.
- A foreign row passes the filter only through a marked rule's match. The same row then passes RBAC through that same rule, so the branch admits nothing RBAC does not.
- "Holds" is the qualification the RBAC filter applies: a named group the caller is in, `authenticated` for any signed-in caller, `public` when the caller inherits public rights, `user` for that uid.
- Only a schema with its own block can have marked rules. The block is read resolved, so roles and compiled matrix rules count. A register's rules never take a caller across organisations, as before.

## Kept as before

| Caller or case | Behaviour |
|---|---|
| admin | no organisation filter |
| explicit `_multi=true` | strict filter, no cross-organisation branch |
| per-object grant held | tenant edge stands; a marked rule without match does not lift it |
| anonymous | a `public` rule (marked or not) lifts the filter, as before; anonymous callers hold no other rules |
| rows with no organisation | admitted through the register cascade as before (#4249) |
| shared master data | holder organisations stay in the filter's scope |

## Published rows

There is no separate published bypass in the query layer: publication is RBAC (a conditional `public` rule such as `publicationDate <= $now`). A signed-in caller reads the published rows of other organisations only when that rule is marked. Anonymous callers read them as before.

## Anonymous and signed-in on an unmarked public rule

On an unmarked `public` rule an anonymous caller reads every organisation's rows, while a signed-in caller reads only their own. This follows from keeping anonymous callers unchanged. Every world-readable `public` rule in the fleet gets the marker in its app PR, which removes the difference. Whether anonymous callers should also need the marker is open for a later decision.

## What becomes unused

The verdict machinery of #5426 (stays, crosses, neutral, implicit crossing by a bound field) no longer decides the organisation filter for a schema's own block. It still decides one narrow question: whether the register cascade admits rows with no organisation. #5433's public-only branch is generalised into the cross-organisation branch.

## Migration

- `MarkImplicitCrossOrganisationReadRules` (post-migration repair step) adds the marker to every stored read rule that binds a field other than `_organisation` to the caller's organisation or membership, held through a named group. It logs, per schema, the plain group and `public` read rules that now narrow. It is idempotent and never throws.
- App register JSON is the source of truth for app schemas. A re-import replaces the stored block, so each app with an intended crossing adds the marker to its JSON.
