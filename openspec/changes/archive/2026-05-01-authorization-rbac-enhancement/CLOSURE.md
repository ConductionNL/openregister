# Closed as superseded — 2026-05-01

This change was a proposal-only stub that substantially duplicated already-shipped capabilities.

**Superseded by canonical specs:**
- `openspec/specs/rbac-scopes/spec.md` — three-level RBAC engine, scope discovery, request-scoped scope cache, OAS scope generation
- `openspec/specs/row-field-level-security/spec.md` — per-record field-level access rules + condition matcher

**What was unique here that did NOT ship:**
- Named role abstractions (above the existing group-based rules)
- Admin UI for role/scope management
- Delegation patterns
- LDAP/AD group→role mapping
- Public-access toggles

If those bullets become real work, file as a delta on `rbac-scopes` rather than reviving this stub. Discovery during the 2026-05-01 proposal-vs-code audit.
