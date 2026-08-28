---
kind: fix
depends_on: []
adr: openspec/architecture/adr-004-credential-broker-custody.md
---

## Why

Reproduced live tonight via a real credential rotation: a stored secret was
rotated to a token string with an embedded trailing newline (`"gho_...\n"`).
Nextcloud's HTTP client (`IClientService`) rejected the resulting
`Authorization: token {secret}\n` header as invalid (header-injection
protection — a `\n` in a header value is illegal). The caller learned nothing
beyond a static "Upstream request failed" / `broker_denied`-style response.

`CredentialBrokerService::performCall()` (`lib/Service/Credential/CredentialBrokerService.php:1125-1155`)
catches every transport-level `Throwable` from the outbound call and:

1. Logs `$e->getMessage()` **verbatim** into the `error` log context. For this
   exact failure mode the underlying exception's message embeds the actual
   rejected header VALUE — i.e. the secret itself — so today's code writes the
   raw secret into `nextcloud.log` on every header-format failure. This is a
   live secret-disclosure bug, not just a diagnostics gap.
2. Throws `new CredentialUpstreamException(message: 'Upstream request failed')`
   — a hardcoded literal that discards the real exception entirely. Nothing
   about the actual cause (`"... is not valid header value"`, a DNS failure, a
   TLS error, a timeout) is recoverable from the exception object itself, only
   from grepping `nextcloud.log`'s context field — which, per point 1, may
   itself contain the secret.

Separately, neither of the two places a credential secret is written
(`CredentialBrokerService::mint()`, used by `POST /api/credentials`, and
`CredentialController::update()`'s direct `credentialStore->put()`, used by
`PUT /api/credentials/{id}` for rotation) trims the incoming value, so a
copy-pasted secret with trailing whitespace/newline is stored and later
injected into a header byte-for-byte.

## What Changes

- `CredentialBrokerService::performCall()` redacts the known secret (and its
  trimmed variant) out of the transport exception's message before it touches
  a log line or an exception object, via a new `describeUpstreamFailure()`
  helper. The redacted `"ClassName: message"` description replaces both the
  log line's raw `$e->getMessage()` (fixing the current leak) and the
  generic literal in `CredentialUpstreamException` (fixing the swallowing).
- The HTTP-facing response stays a static, generic message
  (`CredentialController::brokerRequest()` / `sessionBrokerRequest()` are
  unchanged) — ADR-004's fail-closed non-disclosure posture across the HTTP
  trust boundary is preserved. The improved detail lands in the exception
  object and the log line, which is what in-process callers (openconnector,
  background jobs, tests) and operators actually consume.
- `CredentialBrokerService::mint()` and `CredentialController::update()` both
  `trim()` an incoming secret before it reaches the vault — garbage-in
  prevention for the exact scenario that triggered this, applied at both
  places a secret enters storage.

## Impact

- Affected: `lib/Service/Credential/CredentialBrokerService.php`,
  `lib/Controller/CredentialController.php`.
- No API/response-shape change; no new dependency.
- Risk: low. The redaction only ever removes an exact substring (the actual
  secret value); it cannot make a message MORE revealing than before, and the
  static HTTP response is untouched (pinned by the existing
  `testUpstreamFailureMapsTo502` test).
