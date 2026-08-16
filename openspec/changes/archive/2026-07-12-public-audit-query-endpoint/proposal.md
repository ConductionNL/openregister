---
kind: feature
---

# Public audit query endpoint — admins inspect all fleet audit trails via OR

## Why

Applications log their audit entries to OpenRegister (e.g., procest logs AI decisions as `aiAuditEntry` objects, parafering approvals as `paraferingAuditEntry`). Today, there is no unified query endpoint for admins to inspect these logs without opening app-specific audit UIs. Admin query access should be:
- **Queryable:** Filter by app, schema, object, timestamp
- **Exportable:** CSV/JSON for compliance reporting
- **Centralized:** In OpenRegister (the data platform), not duplicated per-app
- **RBAC-gated:** Admins only

## What changes

- **New public endpoint:** `GET /api/v2/audit` — query audit entries across all apps/schemas with filters (registerId, schemaId, objectId, app, timestampStart/End) and paging (limit, offset).
- **Export variant:** `GET /api/v2/audit/export?format=csv|json` — same query + export.
- **No app-specific audit UIs needed:** Apps record audits to OR; admins query via OR directly.
- **RBAC:** Endpoint accessible only to NC admins.

## Impact

- **Single source of truth:** All audit trails queryable in one place (OR)
- **No duplication:** Apps don't build audit UIs; they just log entries to OR
- **Cross-app compliance:** EU AI Act Art. 14 (human-oversight logs), GDPR Art. 32 (audit trails), Algoritmeregister (algorithm audit)

## Capabilities

### New Capabilities
- `public-audit-query-endpoint` — admins query all fleet audit trails (any app, any schema) via OpenRegister's unified audit API; exportable as CSV/JSON for compliance/evidence
