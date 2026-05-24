# Tasks — Retrofit auth-system

All tasks are retroactive annotation work — code already exists.

- [x] task-1: auth-system#REQ-AUTH-100 — AuthorizationService::validatePayload MUST require `iat`, default `exp` to iat+1h when absent, and reject any payload whose computed expiry is in the past (retroactive annotation)
- [x] task-2: auth-system#REQ-AUTH-101 — AuthorizationService::authorizeOAuth observed-behaviour: gates on `Bearer` prefix + `IUserSession::isLoggedIn()` only; does not parse the token (SECURITY DRIFT, retroactive annotation)
- [x] task-3: auth-system#REQ-AUTH-102 — AuthorizationService::authorizeBasic + authorizeApiKey observed-behaviour: credentials validated via IUserManager only; `$users` / `$groups` allow-list parameters on authorizeBasic are accepted but unused (SECURITY DRIFT, retroactive annotation)
- [x] task-4: auth-system#REQ-AUTH-103 — PropertyRbacHandler MUST dispatch string-rule vs object-rule, bypass for admin, qualify `public`/`authenticated` pseudo-groups, and short-circuit getUnauthorizedProperties when the incoming property value `===` the existing value (retroactive annotation)
- [x] task-5: auth-system#REQ-AUTH-104 — RbacTable + PermissionMatrix Vue surface MUST render flat-vs-nested authorization structures and load the register+schema list via the openregister stores on mount (retroactive annotation)
