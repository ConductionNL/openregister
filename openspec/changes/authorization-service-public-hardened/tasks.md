# Tasks: authorization-service-public-hardened

- [x] 1.1 Public `authorizeJwt`, `authorizeBasic`, `authorizeOAuth`, `authorizeApiKey`; new `authorizeNcSession` with CSRF check and allow-list.
- [x] 1.2 `ConsumerSource` + `ConsumerMapperSource` + `ResolvedConsumer`; optional source on the JWT and API-key entry points; `getResolvedConsumer()`.
- [x] 1.3 `JwtValidator` and `RsaJwsVerifier`: RS/PS verification with the pinned algorithm only; iat-in-future, nbf and jti replay refusal; no lifetime cap (Q4 open).
- [x] 1.4 Allow-list on Basic and OAuth; OAuth requires a Bearer header on the request; credentials act through `setVolatileActiveUser()`.
- [x] 1.5 `HmacJwsVerifier`: HS256/384/512 verified with web-token, pinned algorithm only; `JwtValidator` keeps its minimum-secret-length check first so the refusal names its reason (#4491).
- [x] 2.1 `tests/Unit/Service/AuthorizationServiceHardeningTest.php`: tokens really signed (RSA keys generated in the test, web-token JWSBuilder), the real validator and verifier; red on development.
- [x] 2.2 `tests/Unit/Service/AuthorizationServiceTest.php` follows the volatile setter, the allow-list and the Bearer guard.
- [x] 2.3 `tests/Unit/Service/Consumer/HmacJwsVerifierTest.php`, and hardening tests that refuse an HS token signed with another secret and an HS token whose header names another algorithm than the pin (#4491).
- [ ] 3.1 integriq migrates its AuthorizationService onto this one with a `ConsumerSource` over its `consumer` schema (integriq lane; tell integriq).
