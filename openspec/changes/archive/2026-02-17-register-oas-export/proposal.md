# Proposal: register-oas-export

## Summary
OpenRegister already generates OpenAPI 3.1.0 specifications from registers and schemas (`OasService`), but the output has quality issues (invalid structures, generic security schemes) and no RBAC integration. This change improves the OAS export to produce fully valid, Redocly-compatible specifications and maps Nextcloud groups to OAS security scopes so API consumers can see which groups have access to which endpoints and operations.

## Motivation
The current OAS export generates specifications that may not pass strict validation tools like Redocly or Swagger Editor. Additionally, the security section uses generic `read`/`write` scopes that don't reflect the actual RBAC configuration — OpenRegister has a property-level RBAC system (`PropertyRbacHandler`) with group-based permissions, but none of this is surfaced in the OAS output. API consumers have no way to understand which Nextcloud groups can perform which CRUD operations on which endpoints.

## Affected Projects
- [x] Project: `openregister` — Fix OAS generation in `OasService`, integrate RBAC into security schemes, ensure valid output
- [ ] Project: `softwarecatalog` — Test OAS export with real register/schema configurations (validation only, no code changes)

## Scope
### In Scope
- Fix OAS generation to produce valid OpenAPI 3.1.0 output that passes Redocly lint
- Map Nextcloud groups to OAuth2 scopes in the OAS security schemes section
- Apply group-based security requirements per endpoint/operation (e.g., `GET` may be public, `DELETE` requires admin group)
- Validate generated OAS locally with Redocly CLI
- Ensure "Download API Specification" produces a clean, usable OAS file

### Out of Scope
- Changes to the actual API authentication/authorization runtime behavior
- Redocly hosting or deployment pipeline
- UI changes to the register management pages
- GitHub publish workflow changes (existing `publishToGitHub` stays as-is)
- Property-level RBAC visibility in OAS (field-level read/write restrictions) — future enhancement

## Approach
1. **OAS Validation Fixes**: Run current OAS output through Redocly lint, identify and fix structural issues in `OasService` (property sanitization, `$ref` resolution, schema composition)
2. **RBAC-to-Scopes Mapping**: Read register/schema RBAC configuration, extract unique groups, generate OAuth2 scopes from group names
3. **Per-Endpoint Security**: Apply `security` requirements at the operation level based on which groups have CRUD permissions on the schema
4. **Local Redocly Testing**: Install Redocly CLI, validate generated OAS, fix remaining issues iteratively

## Cross-Project Dependencies
- OpenRegister's `PropertyRbacHandler` already defines the group-based RBAC model — this change reads that configuration but does not modify it
- No cross-project code dependencies; softwarecatalog is only used for testing with real data

## Rollback Strategy
All changes are in `OasService.php` and related OAS generation code. Rollback by reverting commits to `OasService`. The OAS endpoints are read-only (GET) and don't affect stored data — a broken OAS file has no impact on runtime behavior or stored objects.

## Open Questions
- Should the OAS include scopes for groups that have _no_ explicit RBAC rules (i.e., the default/fallback permissions)?
- Should we generate separate OAS files per register (current behavior) or also support a combined multi-register OAS with distinct tags?
- What Redocly configuration (if any) should be committed to the repository for CI validation?
