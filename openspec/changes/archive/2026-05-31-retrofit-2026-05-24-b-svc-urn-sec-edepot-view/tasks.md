# Tasks

Retroactive reverse-spec annotation. Each task corresponds either to one NEW requirement drafted in the matching `specs/<capability>/spec.md` delta, or to an annotation against an EXISTING requirement in the canonical spec. The methods listed are annotated with `@spec ...#task-N`.

## urn-and-mapping-utilities → urn-resource-addressing

- [x] task-1: urn-resource-addressing (NEW REQ — shipped URN construction) — `UrnService::buildForObject`, `build`, `getInstanceSlug` (RFC 8141 `urn:nl-or:{instance}:{register}:{schema}:{uuid}` construction with on-demand build, lower-cased components, configurable instance slug; null when object identity is incomplete) (reverse-spec annotation)
- [x] task-2: urn-resource-addressing (NEW REQ — shipped URN resolution) — `UrnService::parse`, `resolveUrl`, `urnFromUrl`, `resolveBulk` (parse to components, resolve to canonical API URL, reverse URL→URN over the three ObjectReferenceProvider URL shapes, bulk loop; cross-instance/unknown register/schema return null) (reverse-spec annotation)

## security-and-rate-limiting → auth-system (annotation-only, existing REQs)

- [x] task-3: auth-system#"Rate limiting MUST protect against brute force attacks and API abuse" — `SecurityService::checkLoginRateLimit`, `recordFailedLoginAttempt`, `recordSuccessfulLogin`, `clearIpRateLimits`, `clearUserRateLimits` (5-attempt / 900s window, 3600s lockout, progressive 2s→60s delay, per-user + per-IP counters, success-clears, admin manual clear) (reverse-spec annotation against existing REQ)
- [x] task-4: auth-system#"Input sanitization MUST prevent XSS and injection attacks" — `SecurityService::sanitizeInput`, `validateLoginCredentials` (trim/length-cap/null-byte-strip/htmlspecialchars + dangerous-pattern removal; credential presence/length/charset validation) (reverse-spec annotation against existing REQ)
- [x] task-5: auth-system#"CORS policy MUST be enforced per Consumer and prevent CSRF" — `SecurityService::addSecurityHeaders`, `getClientIpAddress` (security-header set; client IP extraction honouring public-range forwarding headers) (reverse-spec annotation against existing REQ)

## edepot-transfer-extras → edepot-transfer (annotation-only, existing REQs)

- [x] task-6: edepot-transfer#"The system MUST support multiple transport protocols for SIP delivery" + #"The system MUST track transfer status per object" — `EdepotTransferService::executeTransfer`, `gatherObjectsWithFiles`, `getObjectFiles`, `processResults`, `markObjectTransferred`, `markObjectTransferFailed`, `notifyTransferCompletion` (transfer orchestration entrypoint: gather objects+files, send-with-retry, per-object accepted/failed status into `retention`, completion notification) (reverse-spec annotation against existing REQs)
- [x] task-7: edepot-transfer#"The system MUST assemble SIP packages for e-Depot transfer" — `SipPackageBuilder::splitIntoBatches`, `buildSinglePackage`, `createManifestEntry` (size-based package splitting, per-object dir + mets/premis/manifest assembly, manifest entry with SHA-256) (reverse-spec annotation against existing REQ)

## view-service-extras → zoeken-filteren

- [x] task-8: zoeken-filteren (NEW REQ — saved-view CRUD lifecycle) — `ViewService::find`, `findAll`, `create`, `update`, `delete` (owner/public access-controlled CRUD over saved View definitions; single-default-view-per-user invariant via clearDefaultForUser) (reverse-spec annotation)
