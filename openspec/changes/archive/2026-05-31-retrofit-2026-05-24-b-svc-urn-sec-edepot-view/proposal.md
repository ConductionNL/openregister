# Retrofit — Service Bundle: URN / Security / e-Depot / View (4 sub-clusters)

Reverse-spec of a bundle of service classes whose methods the coverage scanner could not map to an existing REQ. Each sub-cluster `--extend`s its closest canonical capability. NEW requirements are drafted strictly from *observed, shipped* behaviour; aspirational spec language (notably the URN spec's unimplemented design) is deliberately NOT adopted. Scanner false-positives and out-of-scope files were dropped.

## Why

Four service sub-clusters carry runtime behaviour that is either uncovered (URN construction/resolution as actually shipped, saved-view CRUD lifecycle) or already covered but unannotated (SecurityService, e-Depot transfer/SIP helpers). One ghost change, one PR, links the shipped code back to its capability home so future coverage scans stop re-flagging it.

## Approach

Four sub-clusters, three NEW REQs, one ghost change, one PR:

| Sub-cluster | File(s) | Extends | New REQs |
|---|---|---|---|
| urn-and-mapping-utilities | `Service/UrnService` | `urn-resource-addressing` | REQ: shipped construction; REQ: shipped resolution |
| security-and-rate-limiting | `Service/SecurityService` | `auth-system` | 0 (annotation-only) |
| edepot-transfer-extras | `Service/Edepot/EdepotTransferService`, `Service/Edepot/SipPackageBuilder` | `edepot-transfer` | 0 (annotation-only) |
| view-service-extras | `Service/ViewService` | `zoeken-filteren` | 1 (saved-view CRUD lifecycle) |

**urn-resource-addressing** carries a large *aspirational* spec marked "Not implemented" (organisation-as-NID, a persisted `urn` column on `ObjectEntity`, `/api/urn/resolve` endpoints, a `UrnMapping` table, federation, NL-gov identifier mapping, schema `urn` property type). None of that ships. What *does* ship is `UrnService`, with a different, narrower shape:

- Fixed informal NID `nl-or` (RFC 8141 §5.1), not the organisation slug.
- URN shape `urn:nl-or:{instance-slug}:{register-slug}:{schema-slug}:{uuid}` where `instance-slug` derives from the configured host (or `openregister.urn_instance` app-config override), not from register `organisation`.
- URNs are *built on demand*, never persisted on the object and never auto-generated at create time.
- Resolution is in-memory only (parse → register/schema lookup → `IURLGenerator` API URL); reverse resolution mirrors `ObjectReferenceProvider`'s three URL shapes; bulk resolution is a simple per-URN loop with no 100-item cap. Cross-instance/federated URNs parse but return `null` (explicitly out of scope for v1).

So the two NEW REQs here describe the *shipped* surface only and explicitly do NOT claim the aspirational endpoint/table/federation behaviour.

**auth-system** already specifies SecurityService's full surface: rate limiting (`Requirement: Rate limiting MUST protect against brute force attacks and API abuse`), event auditing (`Requirement: Authentication and security events MUST be audited`), input sanitization (`Requirement: Input sanitization MUST prevent XSS and injection attacks`), and security headers (`Requirement: CORS policy MUST be enforced per Consumer and prevent CSRF`). The SecurityService methods are therefore annotation-only against those existing REQs — no new requirements.

**edepot-transfer** already specifies SIP assembly (`Requirement: The system MUST assemble SIP packages for e-Depot transfer`), transport+retry (`Requirement: The system MUST support multiple transport protocols for SIP delivery`), per-object status tracking (`Requirement: The system MUST track transfer status per object`), and the audit trail (`Requirement: The system MUST log all transfer actions in the audit trail`). The `EdepotTransferService` orchestrator and `SipPackageBuilder` helpers are annotation-only against those existing REQs. (Some methods already carry `@spec` refs to two earlier annotate-openregister passes; those are left intact and this change adds refs only where the bundle's entrypoint/helper methods were unlinked here.)

**no-code-app-builder** (the bundle's nominal target for the view sub-cluster) is a local redirect *stub* and `faceting-configuration` is about facet computation, not saved-view CRUD — neither is a correct home. The shipped `ViewService` is the management lifecycle for the saved-view definitions that `zoeken-filteren`'s existing `View-based search composition` requirement *consumes* (`SearchQueryHandler.applyViewsToQuery()`). It is retargeted to `zoeken-filteren` with one NEW REQ covering the CRUD + access-control + single-default-per-user lifecycle.

3 NEW REQs total.

## Affected code units (by sub-cluster)

- **urn-and-mapping-utilities** → `urn-resource-addressing`
  - `lib/Service/UrnService.php` — buildForObject, build, parse, resolveUrl, urnFromUrl, resolveBulk, getInstanceSlug (+ private sanitiseSlug, resolveRegisterSlug, resolveSchemaSlug, findRegister, findSchema)
- **security-and-rate-limiting** → `auth-system` (annotation-only)
  - `lib/Service/SecurityService.php` — checkLoginRateLimit, recordFailedLoginAttempt, recordSuccessfulLogin, clearIpRateLimits, clearUserRateLimits, sanitizeInput, validateLoginCredentials, addSecurityHeaders, getClientIpAddress (+ private sanitizeForCacheKey, logSecurityEvent)
- **edepot-transfer-extras** → `edepot-transfer` (annotation-only)
  - `lib/Service/Edepot/EdepotTransferService.php` — executeTransfer, gatherObjectsWithFiles, getObjectFiles, processResults, markObjectTransferred, markObjectTransferFailed, notifyTransferCompletion
  - `lib/Service/Edepot/SipPackageBuilder.php` — splitIntoBatches, buildSinglePackage, createManifestEntry
- **view-service-extras** → `zoeken-filteren`
  - `lib/Service/ViewService.php` — find, findAll, create, update, delete (+ private clearDefaultForUser)

## Dropped as scanner false-positives / wrong home

- `lib/Service/MappingService.php` — Twig/dot-notation data transformation (encodeArrayKeys, executeMapping, handleCast, applyCast, getMapping(s), cache invalidation). This is a generic data-mapping/transform engine that belongs to `data-import-export`, not to `urn-resource-addressing`. Dropped from this bundle rather than mis-filed under the URN capability.
- `lib/Service/RiskLevelService.php` — computes and persists a file PII *risk level* (none → very_high) from detected-entity counts via Nextcloud `IFilesMetadata`. This is text-extraction / GDPR risk classification, NOT authentication or authorization, so it does not belong under `auth-system`. Dropped from this bundle.
- Private helpers with no externally observable contract of their own (`sanitiseSlug`, `findRegister/findSchema`, `sanitizeForCacheKey`, `logSecurityEvent`, `clearDefaultForUser`, `splitIntoBatches`, `createManifestEntry`) are described inline within the public-method REQs they serve.

## Security observations (surfaced, not specced)

See `## Notes` in each spec delta. Key items:

- **SecurityService rate limiting is not wired into the inbound auth flow.** `auth-system` already records this as a known gap ("Rate limiting exists via `SecurityService` … but is not integrated into the `AuthorizationService` flow for every authentication method"). The methods exist and behave correctly in isolation; nothing calls `checkLoginRateLimit()` before `AuthorizationService::authorizeBasic/Jwt/ApiKey`, so the brute-force protection is presently dormant for API auth.
- **`recordSuccessfulLogin()` clears IP lockouts on any single success** — one valid credential pair from a shared/NAT'd IP resets that IP's failed-attempt counter and lockout for all users behind it. Low severity (success implies a valid credential) but worth noting for shared-egress environments.
- **`getClientIpAddress()` trusts forwarding headers** (`CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, …) when the first hop value is a *public* IP. Behind a proxy that does not strip client-supplied forwarding headers, a caller can spoof the recorded/rate-limited IP. The public-range filter limits this to public addresses only; deployments must ensure a trusted reverse proxy normalises these headers.
- **URN register/schema lookups bypass RBAC + multi-tenancy** (`findRegister/findSchema` call mappers with `_rbac: false, _multitenancy: false`). This matches the resolver's intent (a URN is a system-independent identifier and resolution only confirms register/schema *existence* + builds a URL; object-level RBAC still applies when the resolved URL is fetched), but the existence signal itself is not tenant-scoped.

Source: openspec coverage scan, service bundle `svc-urn-sec-edepot-view`, 2026-05-24.
