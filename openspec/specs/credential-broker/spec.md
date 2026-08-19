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

### Requirement: Upstream transport failures carry a secret-free real reason

When the broker's outbound call fails at the transport level (after all four
guards passed and the secret was injected), the `CredentialUpstreamException`
thrown to in-process callers, and the corresponding server-side log line,
SHALL both carry the underlying exception's class and a redacted description
of its message — with the credential's own secret value removed — rather than
a single hardcoded generic string. The HTTP API response for the same failure
SHALL remain a static, generic message; this requirement governs only the
exception object and the log line, not the response returned across the HTTP
trust boundary.

#### Scenario: A header-format failure's real cause reaches the exception, not just the log

- **WHEN** the outbound HTTP client rejects the request because the injected
  auth header value is invalid (e.g. it contains a raw newline)
- **AND** the underlying transport exception's own message embeds the
  rejected header value, which contains the secret
- **THEN** the `CredentialUpstreamException` thrown by the broker contains
  the real failure reason (e.g. "is not valid header value") with the secret
  value redacted
- **AND** the log line the broker writes for this failure contains the same
  redacted reason, never the raw secret

#### Scenario: The HTTP response stays generic regardless of the improved diagnosis

- **WHEN** a brokered call made over `POST /api/credentials/{id}/request` or
  `.../session-request` fails at the transport level
- **THEN** the JSON response is the static `{"message": "Upstream request
  failed"}` body with HTTP 502, unchanged by how much diagnostic detail the
  exception object itself now carries

### Requirement: A credential secret is trimmed of surrounding whitespace before storage

Both places a caller-supplied secret is written to the credential vault
(minting a new credential, and rotating an existing credential's secret)
SHALL trim leading and trailing whitespace (including newlines) from the
secret before it is passed to `CredentialStore::put()`. A secret that trims
to an empty string SHALL be treated as no secret supplied.

#### Scenario: A secret rotated with a trailing newline is stored trimmed

- **WHEN** a credential's secret is rotated via `PUT /api/credentials/{id}`
  with a value ending in `"\n"`
- **THEN** the value stored in the vault has the trailing newline removed
- **AND** a subsequent brokered call injects the trimmed value into the
  outbound header, which is a valid header value
