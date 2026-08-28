# data-import-export Specification (delta)

---
status: proposed
---

## Purpose delta

Register-bundle import auto-creates a missing register from the bundle metadata,
fails with an actionable error when auto-create is not possible, and stays
idempotent on re-import.

## ADDED Requirements

### Requirement: Auto-create a missing register from a bundle (REQ-IMP-AC-01)

The register-bundle import MUST create a missing register and its referenced
schemas from the bundle metadata before importing objects into it, when the
target register does not already exist and the payload is a bundle — an envelope
carrying the register definition (slug, title, description) and its schemas.
Register/schema creation MUST reuse the existing configuration/register-import
machinery rather than duplicate creation logic.

#### Scenario: Bundle imports into an instance without the register

- **GIVEN** an instance that does not have register `products`
- **WHEN** a client imports a `products` bundle (register + schemas + objects)
- **THEN** the `products` register and its schemas are created and the objects
  are imported into it.

### Requirement: Clear failure when auto-create is impossible (REQ-IMP-AC-02)

The import MUST fail with a clear, actionable error when it references a register
that does not exist and the payload does not carry enough metadata to create it
(not a bundle); the error MUST name the missing register slug and state that
either the register must be created first or a full bundle supplied, and MUST
surface as an actionable 4xx response, not a silent no-op and not an opaque 500.

#### Scenario: Plain object list for a missing register

- **GIVEN** an instance without register `orders`
- **WHEN** a client imports a plain object list targeting `orders` with no
  register metadata
- **THEN** the import fails with an error naming `orders` and describing the two
  remedies, returned as a 4xx.

### Requirement: Idempotent register-bundle re-import (REQ-IMP-AC-03)

Re-importing a register bundle whose register already exists MUST NOT create a
duplicate register; it MUST skip register creation (slug lookup before create)
and proceed to the existing idempotent schema/object upsert.

#### Scenario: Re-import does not duplicate

- **GIVEN** register `products` already exists from a prior import
- **WHEN** the same `products` bundle is imported again
- **THEN** no second `products` register is created and schemas/objects are
  upserted, not duplicated.
