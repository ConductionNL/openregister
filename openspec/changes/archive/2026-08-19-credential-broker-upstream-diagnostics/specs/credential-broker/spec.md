## ADDED Requirements

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
