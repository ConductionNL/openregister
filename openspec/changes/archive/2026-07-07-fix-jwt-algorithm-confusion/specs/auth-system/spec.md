## ADDED Requirements

### Requirement: JWT verification algorithm is pinned server-side

When verifying a JWT presented for authorization, OpenRegister SHALL determine
the verification algorithm exclusively from the consumer's stored
`authorizationConfiguration`. It SHALL NOT fall back to the algorithm declared
in the attacker-supplied JWT header. If the consumer configuration does not pin
an algorithm, the token SHALL be rejected.

#### Scenario: Algorithm-confusion attack is rejected

- **WHEN** a consumer is configured for an asymmetric algorithm (RS/PS) with an
  RSA public key, and no explicit `algorithm` override
- **AND** an attacker submits a token with header `alg: HS256` signed using the
  public key as an HMAC secret
- **THEN** verification fails and the request is not authenticated

#### Scenario: Header algorithm must match the pinned class

- **WHEN** the pinned algorithm class is asymmetric (RS/PS)
- **AND** a presented token's header `alg` is an HMAC algorithm (or vice versa)
- **THEN** the token is rejected before signature verification

#### Scenario: Asymmetric tokens are verified asymmetrically

- **WHEN** a consumer is configured for RS256 with a valid RSA public key
- **AND** a correctly RS256-signed token is presented
- **THEN** the signature is verified with the public key via asymmetric
  verification (not HMAC) and authentication succeeds

### Requirement: Basic-auth header parsing is defensive

Parsing of an HTTP Basic authorization header SHALL guard against malformed
base64 input and SHALL preserve passwords that contain a colon.

#### Scenario: Malformed basic header fails cleanly

- **WHEN** a Basic auth header contains invalid base64
- **THEN** the request fails authentication without raising a runtime error

#### Scenario: Colon in password is preserved

- **WHEN** a Basic auth credential's password contains one or more `:` characters
- **THEN** the full password (after the first `:`) is used, not a truncated prefix
