# Store plane: a declared install authorization

## Why

The engine hardcodes `IGroupManager::isAdmin` on install. That is the right
posture for dossiq and pipelinq, whose installs write case and commercial
CONFIGURATION — the shape every later handler operates against.

It is the wrong posture for the other three, and each is wrong differently:

- **integriq** gates instantiate on ADR-023 `catalog.instantiate`, and the
  `source` schema carries its own data-layer admin lock. An admin-only route
  would take a capability away from every operator who has the action and is
  not an instance administrator.
- **hermiq** lets any authenticated user install, because the install lands
  `state: quarantined` and an ADR-023 action (`agenttemplate.approve-quarantined`)
  gates the thing that actually matters — approval.
- **buildiq** lets any authenticated user install, and the installer becomes
  the owner of the app they cloned.

Migrating those three onto an admin-only install is a regression, and the fleet
decision is explicitly that they migrate without one.

## What changes

The `store` block gains `installAuth`. Absent, it is `admin`, so dossiq,
pipelinq and every block written before this key keep exactly the posture they
have.

```jsonc
"store": {
  "installAuth": "admin"                      // default
  // "installAuth": "authenticated"
  // "installAuth": "action:catalog.instantiate"
}
```

An unknown value is malformed and DISABLES the store, for the same reason an
unknown `source` does: the alternative is guessing at an authorization posture
on an app's behalf, and the safe-looking guess (fall back to `admin`) silently
removes a capability rather than granting one, which is just as wrong and
harder to notice.

## What does NOT change

- `installable` remains the security boundary and is unaffected by this key.
  Loosening WHO may install does not widen WHAT may be written; an
  `authenticated` install still refuses every schema the allowlist omits.
- The write still runs as the calling user and never through `runAsSystem()`.
  A store payload comes off the network.
- Discovery is untouched.

## Out of scope

The `action:` arm resolves through ADR-023's `ActionAuthService`, which lives
in the leaf apps rather than in OpenRegister. This change defines the
vocabulary and enforces `admin` and `authenticated`; wiring `action:` to a
resolver is the increment that follows, and until then a store declaring it is
rejected as malformed rather than silently treated as `authenticated`.
