---
status: in-progress
---

# credential-broker Specification

## OpenSpec changes

- `github-provider-shop-rules` (in-progress) — widens the runtime-immutable
  provider catalogue's `github` allow-rules for OpenBuild's redesigned app shop:
  adds `GET /search/repositories`, `GET /user`, `POST /user/repos`, and
  `POST /orgs/*/repos` (search, PAT identity, repo creation). Existing repo
  read/contents/git rules and the `gitlab`/`doffin` entries stay unchanged; the
  catalogue remains runtime-immutable, changed only via this reviewed release.
- `anthropic-cli-inject-only-provider` (in-progress) — registers `anthropic-cli`
  as the first `inject_only` provider justified by a NON-HTTP CONSUMER rather than
  an unbounded host: a Claude Max/Pro subscription token bound for the `claude`
  CLI's process environment, where there is no request to proxy and no header to
  substitute, so the constrained-proxy path cannot express it at any host.
  Generalises `inject_only` to mean "the broker cannot bound this call, so it
  refuses to make it". Records the personal-scope-only Anthropic ToS constraint
  (declared here; enforced by the consuming app, which owns the only resolution
  path). `anthropic`/`anthropic-oauth` and the `generic-*` entries stay unchanged;
  catalogue `1.5.0` → `1.6.0`.

## Purpose
TBD - created by archiving change harden-credential-token-binding. Update Purpose after archive.
## Requirements
### Requirement: Brokered credentials never expose the private key on disk

Loading a signing key for authorization SHALL NOT write the private key to a
predictable, world- or group-readable path. If a temporary file is used, it
SHALL be created with a random name and `0600` permissions before the key is
written, and removed immediately after use.

#### Scenario: No world-readable key window

- **WHEN** the service loads an RSA signing key
- **THEN** the key is never present in a file readable by other users
- **AND** any temporary file used is `0600` and deleted after use

### Requirement: Broker capability tokens are bound to the specific request

A broker capability token SHALL bind to the specific outbound call it authorises
(method + path, optionally body digest), not merely to `{appId, credentialId,
exp}`. A token presented for a call that does not match its bound request digest
SHALL be rejected, even within its TTL.

#### Scenario: Token cannot be replayed against a different call

- **WHEN** a token is issued for `GET /repos/x`
- **AND** it is replayed against `PUT /repos/x/contents/y` within its TTL
- **THEN** the broker rejects the call with HTTP 403 (digest mismatch)

#### Scenario: Matching call is authorised

- **WHEN** a token bound to `GET /repos/x` is used for exactly that call
- **THEN** the broker authorises it (subject to the four allow-rule guards)

