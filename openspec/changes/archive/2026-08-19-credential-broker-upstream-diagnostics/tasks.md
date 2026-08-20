# Tasks — credential broker upstream diagnostics

## Redact + surface the real transport failure (D1, D2)

- [x] 1.1 Add `CredentialBrokerService::describeUpstreamFailure(Throwable $exception, string $secret): string` — redacts the exact secret (and its trimmed variant) from `$exception->getMessage()`, returns `ClassName: sanitised message`.
- [x] 1.2 Add a `string $secret` parameter to `performCall()`; pass it from `request()`'s call site.
- [x] 1.3 In `performCall()`'s catch block, use `describeUpstreamFailure()` for both the `logger->error()` context (`error` key — fixes the current raw-secret-into-log leak) and the thrown `CredentialUpstreamException`'s message (fixes the swallowed generic literal).
- [x] 1.4 Confirm `CredentialController::brokerRequest()` / `sessionBrokerRequest()` are untouched — the HTTP response stays the static generic message (D2); do not read `$e->getMessage()` there.

## Trim on write (D3)

- [x] 2.1 `CredentialBrokerService::mint()`: `trim()` a non-null `$secret` before the null/empty check and the vault write.
- [x] 2.2 `CredentialController::update()`: `trim()` the `secret` request param before the `!== ''` check and the direct `credentialStore->put()` call.

## Tests

- [x] 3.1 Unit test: a transport `Throwable` whose message embeds the injected secret → the thrown `CredentialUpstreamException`'s message contains the real reason text but NOT the secret.
- [x] 3.2 Unit test: the same case → `LoggerInterface::error()` is called with an `error` context value that does NOT contain the secret.
- [x] 3.3 Unit test: `mint()` with a secret carrying a trailing `"\n"` stores the trimmed value (assert the exact string passed to `CredentialStore::put()`).
- [x] 3.4 Unit test: `CredentialController::update()` with a whitespace-padded secret stores the trimmed value.
- [x] 3.5 Confirm the existing `testUpstreamFailureMapsTo502` and `testSecretNeverAppearsInResponse` controller tests still pass unmodified (pins D2).

## Verification

- [x] 4.1 `composer check:strict` gates run directly (`vendor/bin/phpcs`, `phpmd`, `psalm`, `phpstan` scoped to the two changed files — the composer wrapper's full-project `phpcs`/`phpmd` passes were also run and show no new findings) — all clean.
- [x] 4.2 Full PHPUnit suite for `tests/Unit/Service/Credential/` (including `CredentialControllerOrganisationTest.php`) and `tests/Unit/Controller/CredentialControllerTest.php` green — 153 tests, 794 assertions.

## Acceptance criteria

- A transport-level upstream failure's real cause (exception class + a
  secret-redacted message) is present on the `CredentialUpstreamException`
  object and in the server-side log line — never only in a raw log field that
  itself might carry the secret.
- The secret value is provably absent from both the thrown exception's
  message and the log line, even when the underlying transport exception's
  own message embedded it.
- A secret rotated or minted with leading/trailing whitespace/newlines is
  stored trimmed, at both entry points (`mint()`, `update()`'s direct vault
  write).
- The HTTP API response shape for an upstream failure is unchanged (still the
  static generic 502 body).
