# Proposal: saas-multi-tenant

## Summary

Implement multi-tenant data isolation, OTAP (Ontwikkel/Test/Acceptatie/Productie) environment support, and SaaS deployment readiness for OpenRegister. This enables Conduction to offer OpenRegister as a hosted SaaS service with proper tenant separation, environment promotion workflows, and the operational characteristics required by Dutch government SaaS procurement.

## Demand Evidence

**Cluster: SaaS / cloud delivery** -- 175 tenders, 806 requirements
**Cluster: Test/OTAP environments** -- 175 tenders, 535 requirements
**Cluster: OTAP environment management** -- 18 tenders, 27 requirements
**Cluster: Cloud hosting model** -- 10 tenders, 17 requirements
**Combined**: 378 tenders, 1385 requirements

### Sample Requirements from Tenders

1. **Gemeente Winterswijk**: "De Oplossing wordt geleverd als Software as a Service (SaaS)."
2. **Gemeente Zeist**: "De Oplossing wordt geleverd als Software as a Service (SaaS). Een SaaS-dienst is software die online beschikbaar gesteld wordt en technisch volledig onderhouden wordt door de Opdrachtnemer."
3. **Gemeente Zeist**: "De Oplossing dient aangeboden te kunnen worden als SaaS. Voor gebruik door de gebruikers volstaat een actuele webbrowser (ten minste alle volgende: Edge/Firefox/Chrome/Safari)."
4. **Gemeente Zoetermeer**: "Fysieke scheiding van een multi-tenant omgeving."
5. **Omgevingsdienst Haaglanden**: "De oplossing wordt aangeboden bedrijfszeker geleverd als single tenant Software as a Service (SaaS), specifiek ontworpen voor hosting in de cloud."
6. **Standard OTAP requirement** (multiple tenders): "Er wordt minimaal een test-, acceptatie- en productieomgeving beschikbaar gesteld, waarbij de acceptatieomgeving functioneel (inclusief koppelingen) gelijk is aan de productieomgeving."

## Scope

### In Scope

- **Tenant isolation model**: Define how tenant data is isolated within OpenRegister -- options include database-level isolation (separate schemas/databases per tenant) or application-level isolation (tenant_id filtering on all queries)
- **Tenant context management**: Middleware/service that resolves the current tenant from the request context (subdomain, header, or Nextcloud instance)
- **Tenant-scoped data access**: All register, schema, and object queries are automatically scoped to the current tenant
- **OTAP environment support**: Configuration management that supports promotion of schemas, registers, and configuration between environments (test -> acceptance -> production)
- **Environment configuration export/import**: Export complete environment configuration (schemas, registers, sources, settings) as a portable package for deployment to another environment
- **Tenant provisioning API**: API to create, configure, and decommission tenants programmatically
- **Resource quotas**: Configurable limits per tenant (object count, storage, API rate limits)
- **Tenant admin dashboard**: Per-tenant usage statistics, storage consumption, and configuration overview
- **SaaS operational requirements**: Health check endpoints, graceful degradation, zero-downtime deployment support

### Out of Scope

- Hosting infrastructure (Docker/Kubernetes orchestration)
- Billing and subscription management
- Network-level isolation (firewall rules, VLANs)
- CSV import/export (already exists)
- Authorization/RBAC within a tenant (separate change: `authorization-rbac-enhancement`)

## Acceptance Criteria

1. Data from one tenant is never visible to or accessible by another tenant, even through direct API calls
2. Tenant context is resolved automatically from the request without requiring tenant ID in every API call
3. All database queries are automatically scoped to the current tenant
4. Environment configuration can be exported from one OTAP environment and imported into another
5. Schema and register definitions can be promoted from test to acceptance to production with validation
6. Resource quotas can be configured per tenant and are enforced at the application level
7. A tenant provisioning API allows automated creation of new tenants with default configuration
8. Health check endpoints return tenant-aware status information
9. Tenant decommissioning removes all tenant data while preserving audit trail records
10. Performance: tenant scoping adds less than 10ms overhead to queries

## Dependencies

- OpenRegister core entities (Register, Schema, Object, Source)
- Nextcloud multi-instance or multi-app configuration capabilities
- PostgreSQL schema-level or row-level security features
- **authorization-rbac-enhancement**: Tenant-level admin roles require RBAC foundation

## Standards & Regulations

- BIO (Baseline Informatiebeveiliging Overheid) -- data separation requirements
- AVG/GDPR -- data processing agreements per tenant, data portability
- ISO 27001 -- multi-tenant security controls (A.13 Communications security)
- NEN-ISO 27017 (cloud security) and NEN-ISO 27018 (PII in cloud)
- DigiD assessment guideline (if tenants use DigiD authentication)
- Dutch Government Cloud Policy (Rijkscloud beleid)

## Architecture Considerations

OpenRegister runs as a Nextcloud app, which means multi-tenancy can be implemented at several levels:

1. **Nextcloud instance per tenant** (simplest, strongest isolation, highest resource cost)
2. **OpenRegister register-per-tenant** (medium isolation, uses existing register concept as tenant boundary)
3. **Row-level tenant_id** (most efficient, requires careful query scoping, lowest isolation)

The recommended approach should be evaluated during the design phase, considering that many tenders explicitly request "fysieke scheiding" (physical separation).

## Notes

- OpenRegister already has CSV import/export with ID support
- The OTAP requirement is nearly universal in Dutch government tenders -- organisations expect separate environments for testing configuration changes before they reach production
- Nextcloud's existing multi-instance capability (via separate installations) already provides the strongest form of tenant isolation; this change may focus more on the OTAP workflow and tenant management tooling within that model
