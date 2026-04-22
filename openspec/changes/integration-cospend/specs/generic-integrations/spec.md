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
