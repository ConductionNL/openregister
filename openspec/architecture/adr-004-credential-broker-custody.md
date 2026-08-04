# ADR-004: Credential broker — apps never hold third-party secrets

**Status**: accepted (documents the decision as implemented across the
`credential-broker`, `credential-doriath-leaf`, and `credential-provider-doffin`
changes)

**Date**: 2026-07-07

## Context

Conduction apps increasingly act on external providers (GitHub, GitLab, Doffin,
…) on a user's behalf. The naive design has each app store the user's personal
access token itself, spreading long-lived secrets across every app with no
central revocation, no per-app scoping, and no audit.

OpenRegister implements a **credential broker** instead
(`lib/Service/Credential/`): the user gives a secret to OpenRegister once; apps
make outbound calls *through* OpenRegister and never see the secret. This is a
security-critical custody boundary that is currently documented only in project
memory, not in any ADR a reviewer or new app author would find.

## Decision

**Third-party secrets live in a vault behind OpenRegister. Consuming apps
receive short-lived, per-app, signed capability tokens — never the secret — and
every outbound call passes through a constrained proxy with a fixed allow-list.**

### Numbered rules

#### Rule 1 — Secret never leaves the vault; metadata never carries the secret

The secret is stored via a pluggable `CredentialStore`
(`DoriathCredentialStore` → the Doriath vault leaf; `NextcloudVaultCredentialStore`
→ `ICredentialsManager` fallback), keyed by the credential UUID. The
`credential` OR object holds only metadata (`name`, `provider`, `owner`,
`allowedApps[]`, `createdAt`) and MUST NOT contain a secret in any property,
export, audit row, or GraphQL projection.

#### Rule 2 — Apps present signed, per-app, expiring tokens

`CredentialAppTokenService` mints HMAC-signed tokens bound to `{appId,
credentialId, iat, exp}` and verifies them with constant-time `hash_equals`.
The per-app secret is looked up from the `appId` *inside* the signed payload,
not asserted by the caller, so a token cannot be forged without the registered
app secret.

#### Rule 3 — The proxy is constrained by a runtime-immutable catalogue

`lib/Settings/credential-providers.json` host-locks each provider's `baseUrl`,
declares the auth-header `authScheme`, and enumerates `allowRules[]` (method +
path-prefix). The catalogue is read-only at runtime: there is no API that
creates, updates, or deletes a provider or an allow-rule. Widening the proxy's
reach requires a code review and release.

#### Rule 4 — Four fail-closed guards on every brokered call

`CredentialBrokerService` enforces, in order: access check → allowed-app check →
allow-rule (method+path) check → host-lock check. Every denial funnels through
a single static 403 with the reason logged **secret-free** (credential UUID
only). Store errors (decrypt/lookup) fail closed to denial.

The access check (Guard 1) has three admit branches, evaluated so that none can
change another's verdict:

1. **Personal owner** — a `personal` credential is admitted only when the acting
   identity equals its `owner` (strict equality).
2. **Organisation member** — an `organisation` credential is admitted for a
   member of its `organisation`. A session is authoritative; a sessionless
   trusted in-process caller may assert a matching `actingOrganisationId`.
3. **Share principal** (`shared-credentials-and-flows`) — a credential is also
   admitted when the acting identity appears in its `sharedWith[]`, directly or
   through a group. This branch only ever ADMITS, never denies, so a credential
   with no `sharedWith[]` is decided exactly as it was before it existed.

Two properties of the share branch are load-bearing:

- **A share grants USE, never disclosure.** The recipient can cause the broker to
  make calls with the credential; they never receive the secret. Rule 1 keeps it
  in the vault and out of every projection, export and audit row, and the routed
  broker path returns only the upstream response. This is what makes sharing a
  credential safe to offer at all.
- **A share never crosses a tenant boundary.** When the credential declares an
  `organisation`, a named principal is admitted only from inside it. Groups are
  RBAC *permission principals* only and are never a tenant discriminator
  (ADR-002 Rule 1) — the organisation UUID is.

Guards 2–4 apply unchanged to every call admitted through any branch, so a share
is not a bypass: a recipient is still refused by `allowedApps`, by the provider
allow-rules, and by the host lock.

## Consequences

- (+) One revocation point; per-app scoping; secret-free audit.
- (+) The blast radius of a compromised consuming app is bounded to its
  allow-rules, not "all the user's tokens."
- (−) Every provider integration requires a catalogue entry + release; apps
  cannot self-serve new hosts.
- Known hardening follow-ups (token method/path binding, private-key temp-file
  handling, JWT algorithm pinning) are tracked in
  `openspec/changes/fix-jwt-algorithm-confusion` and
  `openspec/changes/harden-credential-token-binding`. The canonical spec still
  lives under `openspec/changes/credential-broker/`; promote it to
  `openspec/specs/credential-broker/` via `/opsx-sync`.
