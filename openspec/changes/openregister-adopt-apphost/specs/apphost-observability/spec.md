---
status: proposed
---

# OpenRegister Self-Adoption of AppHost Observability

## Purpose

OpenRegister's own `/api/health` and `/api/metrics` run on the AppHost declarative engine with output parity to the hand-written controllers they replace.

**Cross-references**: `../apphost-observability-engine/specs/apphost-observability/spec.md`

---

## ADDED Requirements

### Requirement: Self-Hosted Declarative Observability

OpenRegister SHALL serve its health and metrics endpoints through the AppHost engine from descriptors in its own `src/manifest.json`, with metric names, types, and label sets identical to the pre-adoption output.

#### Scenario: Metrics parity after adoption

- **GIVEN** a seeded instance with registers, schemas, objects, audit trails, and webhook logs
- **WHEN** `GET /apps/openregister/api/metrics` is called by an admin
- **THEN** the output MUST contain `openregister_info`, `openregister_up`, `openregister_registers_total`, `openregister_schemas_total`, `openregister_objects_total{register,schema}`, `openregister_objects_created_total`, `openregister_objects_updated_total`, `openregister_objects_deleted_total`, `openregister_objects_read_total`, `openregister_webhook_deliveries_total{status}`, `openregister_search_requests_total` with values matching direct table counts
- @e2e exclude API-only — Newman contract collection runs against OR in CI

#### Scenario: Health parity after adoption

- **GIVEN** a healthy instance
- **WHEN** `GET /apps/openregister/api/health` is called anonymously
- **THEN** the response MUST be HTTP 200 with `checks.database = "ok"` and `checks.filesystem = "ok"` in the standard shape
- @e2e exclude API-only — Newman contract collection
