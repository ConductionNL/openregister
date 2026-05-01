# Closed as superseded — 2026-05-01

Proposal-only stub (0/15 tasks ticked). The spec.md inside the change carries `status: implemented`, and the substantial implementation already ships in `lib/`.

**Superseded by:**
- `lib/Service/AuthenticationService.php` — multi-method authentication entrypoint (session, Basic, JWT, API key, OAuth2)
- `lib/Service/AuthorizationService.php` — RBAC evaluation + Consumer-to-Nextcloud-user resolution
- `lib/Service/AuthorizationAuditService.php` — authentication and security audit events
- `lib/Service/SecurityService.php` — rate limiting, brute-force protection, input sanitisation hooks
- `lib/Db/Consumer.php` + `lib/Db/ConsumerMapper.php` — Consumer entity (JWT/OAuth/API key bridge to `IUser`)
- `MultiTenancyTrait` usage across mappers (organisation isolation per request)
- Nextcloud-native `#[PublicPage]` annotations on public controllers (mixed-visibility enforcement)

**Canonical specs to keep evolving instead of this change:**
- `openspec/specs/rbac-scopes/spec.md` (scope-based permission grammar)
- `openspec/specs/multi-tenancy/spec.md` (organisation isolation contract)
- The `auth-system` capability spec already lives at `openspec/changes/archive/2026-05-01-auth-system/specs/auth-system/spec.md` (status: implemented) — promote to `openspec/specs/auth-system/spec.md` when next touched.

**What did NOT ship from this proposal (real work, but belongs in new focused changes, not this stub):**
- A unified permission cache layer (`Requirement: Permission evaluation results MUST be cacheable`) — partially exists but not centralised.
- Per-Consumer outbound service-to-service token issuance (currently consumers authenticate inbound only).
- MCP-specific auth path validation (uses generic API-key auth today).

If you re-open any of these scopes, write a focused change (e.g. `auth-permission-cache`, `auth-outbound-tokens`) against the actual shipped surface — don't revive this proposal.
