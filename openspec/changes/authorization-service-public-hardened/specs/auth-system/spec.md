## ADDED Requirements

### Requirement: An app's inbound endpoints can authenticate through OpenRegister's AuthorizationService

Open Register's AuthorizationService SHALL offer public entry points for JWT, Basic, OAuth Bearer, API key and Nextcloud-session authentication. The JWT and API-key entry points SHALL accept a consumer source supplied by the caller, and SHALL use Open Register's own consumers when none is given. After a JWT or consumer API-key call succeeds, the service SHALL return the consumer that authenticated, including the source's own record; after a user-based mechanism it SHALL return none. Basic, OAuth and session checks SHALL honour the endpoint's users/groups allow-list. A session check SHALL require Nextcloud's CSRF check to pass. An OAuth check SHALL require the request to carry a Bearer Authorization header. A credential SHALL make its user the acting user for the current request only, never writing it into the PHP session.

#### Scenario: an app brings its own consumers

- **GIVEN** an app keeps its consumers as objects of its own schema and passes a consumer source over them
- **WHEN** a JWT signed for one of those consumers is presented
- **THEN** it is authenticated against that consumer's stored key and algorithm
- **AND** the resolved consumer carries the app's own object
- @e2e exclude {service API, no page; covered by AuthorizationServiceHardeningTest}

#### Scenario: a session check refuses a forged cross-origin request

- **GIVEN** a signed-in Nextcloud user
- **WHEN** a session-authenticated endpoint is called without a passing CSRF check, or by a user outside its allow-list
- **THEN** the call is refused
- @e2e exclude {service API, no page; covered by AuthorizationServiceHardeningTest}

#### Scenario: a session cookie is not a Bearer token

- **GIVEN** a browser with a Nextcloud session cookie
- **WHEN** an OAuth endpoint is called with a made-up Bearer value but the request carries no Bearer Authorization header
- **THEN** the call is refused
- @e2e exclude {service API, no page; covered by AuthorizationServiceHardeningTest}

### Requirement: JWTs signed with RS or PS algorithms are verified, and a token is used once

Open Register SHALL verify RS256/384/512 and PS256/384/512 tokens against the consumer's RSA public key (PEM or base64 of a PEM), loading only the algorithm pinned in the consumer's configuration. It SHALL refuse a token whose `iat` lies more than 60 seconds in the future, a token whose `nbf` has not been reached, and a token whose `jti` was accepted before (remembered until the token expires; refused when no distributed cache can check it). It SHALL NOT cap the lifetime a caller states in `exp` until that question is decided.

#### Scenario: an RS256 token is verified with the public key

- **GIVEN** a consumer configured for RS256 with an RSA public key
- **WHEN** a token signed with the matching private key is presented
- **THEN** it is authenticated
- **AND** a token signed with another key, or an HS256 token using the public key as secret, is refused
- @e2e exclude {service API, no page; covered by AuthorizationServiceHardeningTest}

#### Scenario: a replayed or pre-dated token is refused

- **GIVEN** a token with a `jti` that was accepted once
- **WHEN** it is presented again, or a token with an `iat` ten minutes in the future is presented
- **THEN** it is refused
- @e2e exclude {service API, no page; covered by AuthorizationServiceHardeningTest}
