# Fix object read visibility gated on the CREATE permission

## Why

`PermissionHandler::filterObjectsForPermissions()` is the object-level visibility
filter on the **read** path. It gates visibility on the **create** permission:

```php
// Property-level RBAC ... this loop only decides object-level visibility.
if ($this->hasPermission(
        schema: $schema,
        action: 'create',   // <-- gating READ visibility on CREATE
        userId: $userId,
        objectOwner: $objectOwner,
        _rbac: $_rbac
    ) === false
) {
    continue;   // object hidden
}
```

`lib/Service/Object/PermissionHandler.php` ~line 911. The comment directly above
states the loop decides visibility, and it asks for `create`.

### Consequence

On **any** register, a user who may not *create* a schema's objects cannot *see*
them either. Read-only consumers are the normal case, so this is broad.

Observed on openbuild (Conduction/openbuild#76): the `openbuild/application`
schema authorises `create: ["admin"]`, so every non-admin fails the check and the
object is skipped:

| Caller | Raw OR objects | openbuild app list |
|---|---|---|
| admin | 21 | all |
| `rbac-editor` (granted editor on an app) | **0** | none |

The app-level permission model is therefore unreachable for exactly the users it
exists to serve: an owner can grant a team editor/viewer on an app and those
users still see nothing. The affordance exists and does not work.

### Second gap: no way to express "any authenticated user"

`resolveReadGroupIds()` treats `public` and `admin` as broadcast sentinels.
`public` means **anonymous** (`isPublicReadable()` explicitly replaced the
published/depublished gate). There is no value meaning "any logged-in caller but
not anonymous", so that intent currently cannot be expressed at all — the only
way to approximate it publishes the data.

## What changes

1. The read path gates on `read`, not `create`.
2. A new `users` sentinel means "any authenticated caller, never anonymous".

## Open question — resolve BEFORE implementing

Adding `read: ['rbac-editors']` flipped `rbac-editor` from 0 to 21 objects, even
though the gate above asks for `create`. Something on that path consults read
rules and it is not yet known what. Until that is explained, a fix here is a
guess that happens to work, and the verification matrix below cannot be trusted
to mean what it says.

## Blast radius

OpenRegister is the foundation every Conduction app builds on. A wrong read gate
either hides data everywhere or exposes it everywhere, silently — which is
exactly what the `create` gate has been doing undetected. This change is not
shippable without the full matrix in `tasks.md`.

## Impact

- Unblocks Conduction/openbuild#76 and four quarantined e2e scenarios
  (`versionRouting` 9.2, `schema-access-scopes-rbac` ×3, plus the viewer-gating
  assertion omitted from `save-as-template`).
- Likely unblocks equivalent read-only-consumer cases in other apps on shared
  registers; worth checking before and after.
