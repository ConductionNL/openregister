---
status: proposed
---

# Integration: Cospend

## Purpose

Link NC Cospend projects/bills to OR objects through the registry for case cost tracking.

**Standards**: NC Cospend API, ADR-019
**Cross-references**: [generic-integrations](../../../pluggable-integration-registry/specs/generic-integrations/spec.md)

---

## ADDED Requirements

### Requirement: Cospend Provider Registration

`CospendProvider` registered with id='cospend', group='workflow', requiredApp='cospend', storage='link-table'.

### Requirement: Project or Bill Link Types

Link rows SHALL have a `link_type` of `project` or `bill`, not both hybrid.

### Requirement: Same-Currency Aggregation Only

Totals SHALL aggregate only bills in the same currency. Mixed-currency sets SHALL render per-currency totals side by side.

### Requirement: Widget Surfaces

Standard four; single-entity is amount chip.

### Requirement: Reference-Property Auto-Rendering

`referenceType: 'cospend'` SHALL render amount chip.

### Requirement: Permission Inheritance

`requiresPermission() === null`; Cospend ACLs apply.

---

### Requirement: Graceful Degradation

The provider SHALL conform to the umbrella's Error-Handling Contract. When an underlying bill or project in NC Cospend is missing, inaccessible, or the backing service is down, the provider SHALL surface the documented exception types rather than leaking generic errors.

#### Scenario: Multiple currencies in the same link set

- **GIVEN** an object linked to bills in EUR, USD, and GBP
- **WHEN** `CnCospendCard` renders with `surface='detail-page'`
- **THEN** three side-by-side totals MUST be rendered, one per currency
- **AND** no cross-currency aggregation MUST be attempted
