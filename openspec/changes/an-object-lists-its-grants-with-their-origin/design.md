# Design: an-object-lists-its-grants-with-their-origin

## D-1. Endpoint

```
GET /apps/openregister/api/objects/{register}/{schema}/{id}/grants
    ?principalTypes=user,group      optional, comma list; default all
```

A plain `Controller`, `#[NoAdminRequired]`, `#[NoCSRFRequired]` (a GET that changes
nothing), so a refusal keeps its status code. The route sits beside
`objectPermissions#index` in `appinfo/routes.php`. No existing route matches
`/grants` on this path.

Response:

```json
{
  "object": "8a1c…",
  "results": [
    {
      "id": "rule:schema:group:woo-team",
      "principal": {"type": "group", "id": "woo-team", "displayName": "Team Woo en informatie"},
      "verbs": ["read", "update"],
      "origin": "rule",
      "level": "schema",
      "role": null,
      "matchedOn": ["caseType"],
      "relative": false,
      "sharedBy": null,
      "inheritedFrom": null,
      "expiresAt": null
    },
    {
      "id": "shared:ocinternal:41",
      "principal": {"type": "group", "id": "juridische-zaken", "displayName": "Juridische zaken"},
      "verbs": ["read"],
      "origin": "shared",
      "level": "object",
      "role": null,
      "matchedOn": [],
      "relative": false,
      "sharedBy": {"id": "pjansen", "displayName": "Pieter Jansen"},
      "inheritedFrom": null,
      "expiresAt": "2026-12-31T00:00:00+00:00"
    }
  ],
  "denyEnforcement": "staging"
}
```

`id` is a stable row key for a client list. It is not a share id and no endpoint
accepts it.

## D-2. Origins

| origin | source | `level` | extra fields |
|---|---|---|---|
| `owner` | `ObjectEntity::getOwner()` and the owning group | `object` | none |
| `shared` | a Nextcloud share on the record's folder (user, group, remote, remote group, email) | `object` | `sharedBy`, `expiresAt` |
| `inherited` | a share on an ancestor record (`InheritedGrantLister`) | `object` | `sharedBy`, `inheritedFrom: {uuid, register, schema}`, `expiresAt` |
| `rule` | an entry in the object, schema or register `authorization` block, directly or through a role | `object`, `schema` or `register` | `role`, `matchedOn`, `relative` |

Link shares are left out. A link has no principal, and the access links an app mints
(`/api/access-links`) are their own list.

One principal can appear on more than one row, once per origin, because "via the case
type" and "shared by Pieter" are two reasons and revoking one leaves the other. Rows with
the same principal, origin, level and role are folded into one, with the verbs merged.

## D-3. Conditional rules are evaluated against the record

`ObjectPermissionsResolver::verbGrantsIn()` flags an entry with a `match` as
`conditional` and lists it for every record. This read goes one step further:

- A condition whose values are all static is evaluated with
  `ConditionMatcher::objectMatchesConditions()` against the record. A rule that does not
  fit is dropped. This is the same matcher the single-object path uses, so the list and the
  decision agree by construction.
- A condition that reads who is asking (`$userId`, `$user.groups`, `$organisation`) cannot
  be answered for "everybody". The row stays, with `relative: true`. Its principal is the
  entry's group (often `authenticated`), and `matchedOn` names the property, for example
  `["assignee"]`, so a client can say "whoever is the assignee".
- `matchedOn` lists the top level property names of the `match` clause, in the order
  written. An unconditional rule has `[]`.

`/permissions` keeps its current behaviour. It is the auditor's view of the rules as
written; this is the view of who reaches this one record.

## D-4. Denies

A verb that an `enforcing` deny removes for a principal is taken out of that row's `verbs`.
A row left with no verbs is dropped. While the deny is `staging`, the verb stays and the
mode is reported in `denyEnforcement`, the same convention `/permissions` follows, so a
client never reads a subtraction that has not happened.

## D-5. Who shared, and display names

- `sharedBy` comes from `IShare::getSharedBy()`. The display name comes from
  `IUserManager::getDisplayName()`. A user who no longer exists keeps the uid and gets
  `displayName: null`; the row is not dropped.
- A group's display name comes from `IGroupManager::get()->getDisplayName()`. A user's from
  `IUserManager`. Remote and email principals carry their address as the display name.
- Pseudo principals keep their id and get a fixed type: `public` becomes type `public`,
  `authenticated` becomes type `authenticated`. `principalTypes=user,group` leaves them out.

## D-6. Who may read the list, checked before anything is read

The order is fixed, and each step runs before the next reads anything:

1. Resolve the record through `ObjectService`, which applies RBAC and multitenancy. A
   caller who cannot read the record gets 404, so its existence does not leak. This is
   `ObjectPermissionsController::resolveObject()`.
2. Decide whether the caller may read the record's access. Admitted are:
   - the owner, the owning group and an administrator;
   - a caller holding `manage` through a rule somebody wrote (the existing
     `managesTheSchema()` check, which refuses a schema that configures nothing);
   - a holder of the organisation's `grant_manage` special right on the record
     (`GrantManageGuard::admittedAs()`);
   - a caller the record's `readGrants` rule admits, evaluated through `PermissionHandler`
     with the record, so a conditional entry such as
     `{"group": "authenticated", "match": {"assignee": "$userId"}}` works.
   Anyone else gets 403 with "Reading who has access to this object needs the right to
   read its grants".
3. Only then are the shares, the ancestor shares and the rules read.

`readGrants` is a new canonical verb in `PermissionCatalogue::CANONICAL`, described as
"See who can open an object and where each grant came from." It MUST NOT resolve
default-open: a schema or register that does not write it admits nobody through it, and
the first four bullets remain the only way in. That differs from `export`, which falls back
to `read`, on purpose: an ordinary reader of a record has no business listing the people on
it.

The shares are read without going through `ObjectSharingService::listGrants()`, because
that method runs its own `requireMayManageGrants()` and would refuse a `readGrants` holder
after step 2 admitted them. The share read is extracted into a guard-free reader that both
`listGrants()` and this endpoint call, and `listGrants()` keeps its guard in front of it.

## D-7. Where the code goes

- `Controller/ObjectGrantsController` owns the request and D-6. It reuses the object
  resolution and access-review checks of `ObjectPermissionsController` by moving them into
  a shared `Service/Rbac/AccessReviewGate`, so the two endpoints cannot drift on who may
  ask.
- `Service/Rbac/ObjectGrantsReport` assembles the rows from `ObjectPermissionsResolver`,
  the extracted share reader and `InheritedGrantLister`, applies D-3 and D-4, and adds the
  names of D-5. It decides nothing about access.

## D-8. Failure

A source that cannot be read answers 500 with "The grants of this object could not be
listed", and the cause goes to the log. An empty list is a real answer (nobody but the
owner), so a failed read never turns into `[]`.

## Open questions

- Should the owner row appear when the owner is a service account? Proposed: yes, with
  its type `user`; a client hides it by id if it wants to.
- Should `readGrants` holders also see `expiresAt` of a share? Proposed: yes, it is part
  of the grant, not of the sharer.
