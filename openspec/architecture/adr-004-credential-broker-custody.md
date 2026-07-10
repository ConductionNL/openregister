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

`CredentialBrokerService` enforces, in order: owner check → allowed-app check →
allow-rule (method+path) check → host-lock check. Every denial funnels through
a single static 403 with the reason logged **secret-free** (credential UUID
only). Store errors (decrypt/lookup) fail closed to denial.

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
