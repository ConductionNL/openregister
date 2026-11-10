# Design: declarative per-transition authorization

## Annotation shape (additive)

```jsonc
"x-openregister-lifecycle": {
  "field": "lifecycle",          // or "property" (alias)
  "initial": "concept",
  "transitions": {
    "completeren": {
      "from": "in_parafering",   // string or [array]
      "to": "geparafeerd",
      "authorization": [          // NEW — optional, additive
        "vergunningverleners",   // literal NC group id
        { "role": "handler" }    // resolved via schema authorization.roles
      ],
      "requires": "OCA\\Procest\\Lifecycle\\VoorstelSubmitGuard" // unchanged seam
    }
  }
}
```

## Enforcement path

`ObjectService::saveObject()` → `MagicMapper::updateObjectEntity()` dispatches
`ObjectUpdatingEvent(newObject, oldObject)`. `LifecycleValidationListener`:

1. Reads `x-openregister-lifecycle` off the schema config (alias `property`→`field`).
2. If the lifecycle field value is unchanged → no-op.
3. Finds the transition whose `to` matches the new value and whose `from`
   (string coerced to list) contains the old value. No match → reject
   `lifecycle-invalid-transition`.
4. **NEW**: if the matched transition declares a non-empty `authorization` list,
   call `PermissionHandler::isTransitionAuthorized(list, callerUid, schema)`.
   Deny → reject `lifecycle-transition-unauthorized`. Evaluated BEFORE the
   `requires` guard so an unauthorized caller never triggers guard side-channels.
5. If `requires` is set, resolve + run the guard (unchanged).

A rejection stamps a structured error and stops propagation; `MagicMapper` raises
`HookStoppedException`, aborting the write. No object data is mutated on rejection.

## `isTransitionAuthorized` (PermissionHandler)

Fail-closed (CWE-863): empty list → deny; null/unknown uid → deny; `admin` group
→ allow; literal string entry matched against `IGroupManager::getUserGroupIds`;
`{role}` entry expanded to the groups assigned to that role on the schema's
`authorization.roles` map, then matched the same way. Unresolvable entries are
skipped (never widen access). This reuses the SAME membership primitive every
other RBAC verdict in the handler trusts.

## Backward compatibility

- Transitions without `authorization` skip the new branch entirely.
- Schemas without `x-openregister-lifecycle` are never touched.
- `field`/array-`from` schemas behave identically; `property`/string-`from` are
  newly accepted, not required.
- No public signature changed (the listener constructor gains a DI-injected
  `PermissionHandler`; the public ObjectService surface is unchanged).

## Consumability by procest

- **status-engine**: declare `x-openregister-lifecycle` on voorstel/parafeerroute/
  bezwaar; OR rejects illegal transitions and runs `requires` guards on save.
- **role-routing**: at workflow-publish time, resolve each step's
  `roleType.ncGroupId` and write it into the relevant transition's
  `authorization` list (literal group ids) — OR then enforces step transition
  authorization server-side without a PHP guard per role.
