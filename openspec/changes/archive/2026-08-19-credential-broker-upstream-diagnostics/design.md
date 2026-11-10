# Design — credential broker upstream diagnostics

## Context

`CredentialBrokerService::performCall()` is the single place the broker
performs the outbound HTTP call after all four guards and secret injection
have passed (`credential-broker/design.md` D4). Its `catch (Throwable $e)`
block is the last point in-process that ever sees the real transport
exception; today it discards it in two different ways (see proposal.md).

Tonight's concrete trigger: `NC's IClientService` (Guzzle underneath) rejects
a header value containing a raw `\n` before the request ever leaves the
process. The exception it throws embeds the offending header value verbatim
in its own message — `"token gho_...\n" is not valid header value` — which
means the secret is present in `$e->getMessage()` for this specific failure
class. Not every transport failure carries the secret (a DNS failure or a
timeout does not), but the fix has to assume any transport exception MIGHT,
because the header-injection guard is exactly the kind of failure this
broker's own secret injection can trigger.

## D1 — Redact by exact substring, not by heuristic

Considered a generic "looks like a token" heuristic (e.g. regex for
high-entropy strings) — rejected. It is unreliable in both directions: it can
miss a low-entropy secret and can also eat unrelated diagnostic text that
happens to look token-like (a commit SHA, a request id). The broker already
knows the EXACT secret value for this call (it just injected it into the
headers a few lines above `performCall()`), so the correct redaction is
`str_replace($secret, '[redacted]', $message)` — it removes precisely the
string that must never leak and nothing else. The trimmed variant of the
secret is also redacted, in case a future transport layer normalises
whitespace before it surfaces the value in its own message (defence in depth,
cheap to add, matches D3's trim-on-write making the two variants usually
identical anyway).

`describeUpstreamFailure(Throwable $exception, string $secret): string`
returns `get_class($exception) . ': ' . $message` — the exception class name
carries real signal (`InvalidArgumentException` vs. a Guzzle connect
exception vs. a timeout) that the generic literal threw away entirely, and
it is not the sort of thing that can carry a secret.

## D2 — Keep the HTTP response static; improve the exception, not the controller

`CredentialController::brokerRequest()` / `sessionBrokerRequest()` already
map `CredentialUpstreamException` to a hardcoded `{"message": "Upstream
request failed"}` / 502, ignoring `$e->getMessage()` — this is intentional
per `CredentialUpstreamException`'s own docblock ("this exception maps to a
single static client error ... the real cause is logged server-side") and is
pinned by the existing `testUpstreamFailureMapsTo502` test, which asserts the
literal response body. The broker is reachable by less-privileged in-process
callers across app boundaries (any app in `allowedApps`), so the HTTP/API
surface must stay generic regardless of how good the redaction is — a
redaction bug would otherwise become a cross-app information-disclosure bug.
This change does not touch the controller's catch blocks or that test.

What DOES change is who can see the improved message: the exception object
now carries it, so (a) the log line callers grep is no longer the only
place, (b) any in-process caller that catches `CredentialUpstreamException`
directly (background jobs, `resolveInjectable()`'s siblings, tests) sees it
too, and (c) an operator reading `nextcloud.log`'s `error` context field
(the thing that was actually grepped tonight) sees the real, sanitised
reason instead of either nothing or a raw secret.

## D3 — Trim on write too (both places a secret enters storage)

Two places accept a raw secret from request input and hand it to the vault:

- `CredentialBrokerService::mint()` (`POST /api/credentials` via
  `CredentialController::create()`, and any in-process minter).
- `CredentialController::update()`'s direct `$this->credentialStore->put($id,
  $secret, $scope)` (`PUT /api/credentials/{id}`) — this is the ROTATION
  path, and specifically the one that produced tonight's incident. It
  bypasses `mint()` entirely (an existing credential's metadata object is
  already persisted; only the vault write happens), so trimming inside
  `mint()` alone would not have caught this.

Both now `trim()` the incoming secret before it reaches
`CredentialStore::put()`. `trim()`'s default character list
(`" \t\n\r\0\x0B"`) covers the reported case (`\n`) and the adjacent ones
(`\r`, tabs, stray spaces from a copy-paste) without touching the meaningful
byte content of the secret. An all-whitespace secret trims to `''`, which
both call sites already treat as "no secret supplied" (mint: skip the vault
write; update: skip the `put()` call) — no new edge case introduced.

This is deliberately BOTH a write-side fix (D3) and a read-side fix (D1/D2):
D3 prevents the exact reproduced scenario from recurring, but does nothing
for every OTHER transport failure the broker can hit (wrong `baseUrl`, TLS,
timeout, provider outage) — those still need D1/D2's better diagnosis. D1/D2
alone would leave the vault silently storing a malformed secret forever.
Doing only one leaves a real gap; doing both is the same size of change and
closes both.

## Non-goals

- Not changing `CredentialStore`'s interface or either leaf implementation
  (`NextcloudVaultCredentialStore`, `DoriathCredentialStore`) — trimming is
  an application-level input-normalisation concern, not a storage-leaf
  concern, and touching the interface would ripple into every leaf for no
  additional benefit (both existing call sites already funnel through the
  two places D3 touches).
- Not adding a general secret-scrub helper shared across the codebase — the
  grep for `scrub`/`redact`/`sanitize` found only unrelated per-domain
  helpers (GDPR data-subject scrub, SQL identifier sanitizers, file-metadata
  scrubbers, etc.); none apply to "redact one already-known exact string from
  a free-text exception message", so a small local helper is the right size
  and avoids a speculative shared abstraction with no second caller yet.
