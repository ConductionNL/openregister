# Design — credential broker organisation scope

## Context

The credential-broker chain (see `credential-broker/design.md`) resolves an outbound
call in `CredentialBrokerService` behind four guards: **owner** (per-object IDOR),
**allowedApps** (the calling app is listed), **provider allow-rules** (method + path
pattern), and **host-lock** (base URL). The stored secret lives in the Nextcloud vault
(`ICredentialsManager`) keyed by the credential's UUID; the object itself never holds a
secret. Organisations are OR-native (UUID identities resolved through `UserService`).

This design adds an **organisation** flavour of credential alongside the existing
**personal** one, changing only the owner guard and the vault-owner selection — the
provider allow-rules, host-lock, and constrained proxy are untouched.

## D1 — Schema: `scope` + `organisation`

The `credential` schema gains two properties:

- `scope`: enum `personal` | `organisation`, **default `personal`**. Absent ⇒ personal.
- `organisation`: an OR organisation UUID. **Required iff** `scope = organisation`;
  MUST be absent/ignored for personal credentials.

`owner` remains, and for organisation credentials records the **provisioning admin** for
audit/attribution only — it is NOT the access-control key (membership is). No secret is
added to the object; the metadata stays safe to list, export, audit, and query.

## D2 — Where the organisation secret lives (the crux)

`ICredentialsManager` is per-user: `store($userId, $identifier, $value)`. A shared
organisation secret cannot live under one member's user, or it would vanish when that
member leaves and would be readable only in that member's session.

**Decision:** organisation secrets are stored as Nextcloud **system credentials** — the
same `ICredentialsManager`, but under a single reserved system identity
(`ICredentialsManager::store('', $uuid, $secret)`; the empty user is Nextcloud's
supported system-credential namespace), still keyed by the credential UUID. Rationale:

- No user "owns" the secret, so membership changes never orphan it.
- The key is the credential UUID (unique), so different organisations never collide.
- **Access is gated by the guards in D3 BEFORE the vault is read** — the UUID is only
  used to fetch the secret once the acting user has been proven a member and the app
  proven allowed. The vault key is not itself a capability.
- Encryption at rest is inherited from `ICredentialsManager` (encrypted store),
  identical to the personal path.

Personal secrets are unchanged: stored under the owning user, keyed by UUID.

A single private helper selects the vault owner from the credential's scope
(`'' ` for organisation, the `owner` uid for personal) at both write (create/rotate) and
read (broker resolve) time, so the two paths cannot drift.

## D3 — Broker guard: additive organisation branch

`CredentialBrokerService` today (personal): resolve acting identity → **deny unless
`credential.owner === actingUid`** → allowedApps → provider rules → host-lock.

The owner guard becomes scope-dispatched, **without changing the personal branch**:

```
if credential.scope == 'personal' (or absent):
    deny unless credential.owner === actingUid          # UNCHANGED
else: # organisation
    deny unless actingUid is a member of credential.organisation
```

Membership is resolved through `UserService` (the acting user's organisations must
include `credential.organisation`). The `allowedApps`, provider-allow-rule, and
host-lock guards run afterwards for BOTH scopes, unchanged. The sessionless
`actingUserId` fallback (design D-K, honoured only when no session exists) applies to the
personal branch only; an organisation call MUST have a real session to resolve
membership (no session ⇒ deny), because there is no owner to fall back to.

**Security invariant:** the change is strictly additive. A personal credential's guard
is byte-for-byte the same; the organisation branch is a *new*, *narrower-than-public*
gate (org membership + allowedApps), never a widening of the personal one. It is
impossible for the new code to admit a personal-credential call that the old code denied.

## D4 — Admin CRUD & listing

- `POST /api/credentials` with `scope: 'organisation'` + `organisation: <uuid>`:
  allowed only when the caller is an **administrator of that organisation** (or a
  Nextcloud admin). The `organisation` defaults to the caller's active organisation when
  omitted. On success the secret is written to the system vault (D2).
- `PUT` / `DELETE` on an organisation credential: same admin gate.
- `GET /api/credentials?scope=organisation`: returns the organisation credentials of the
  caller's active organisation (visible to any member; secrets never returned).
- `GET /api/credentials` (no scope) or `?scope=personal`: unchanged — the caller's own
  personal credentials.

Organisation-admin resolution reuses OR's existing organisation ownership/role model
(the organisation object's owner/administrators); if OR exposes only owner-level
authority today, org-credential management is gated to the organisation owner + NC admin,
and finer roles are a follow-up (called out in tasks). Personal CRUD is unchanged and
requires no admin.

## D5 — API compatibility

The wire shape is a superset: `scope` and `organisation` are optional additive fields on
`POST`, and `scope` is an optional query param on `GET`. Every existing client that
sends neither continues to hit the personal path with identical behaviour. The
`/providers` catalogue endpoint is unchanged.

## D6 — Frontend already present

`CnCredentials` (nextcloud-vue) renders `scope="organisation"` with full allowed-app
management and posts `scope: 'organisation'`; it is surfaced from an app's **admin**
settings (organisation credentials) vs the **personal** settings (personal credentials).
This change is the backend that mode calls; no further frontend contract is required
beyond honouring the `scope` param already sent.

## Risks

- **Vault-owner drift** — mitigated by the single shared owner-selector helper (D2).
- **Membership check cost** — `UserService` organisation lookups are cached; the guard
  adds one membership resolution per organisation broker call.
- **Org-admin authority granularity** — bounded explicitly in D4; owner-level gate first,
  finer roles deferred.
