---
status: in-progress
---

# register-scoped-slug-resolution Specification

**OpenSpec changes**
- register-scoped-slug-resolution

## Purpose

Defines how a schema slug resolves when a caller supplies a register context, what
happens when it does not resolve, and how a register whose `schemas` list has been
lost is repaired from physical storage.

Naming a register makes it a **boundary**. A caller that named one is never served a
schema from outside it — not from another register, not from another application,
and not from a same-slug duplicate within its own application. Global resolution
remains available to callers that name no register.

The behaviour matters because its failure is quiet. Measured on the development
instance 2026-08-16: DocuDesk's Document Register (id 6) carried an empty `schemas`
list while nine `docudesk`-owned schemas shared the slug `anonymizationLink`. Global
resolution returned schema 5084, which has no table under register 6, so slug reads
returned an **empty result set** while four rows sat unreachable in
`oc_openregister_table_6_9177`. An empty result is indistinguishable from "this
register holds no objects", which is why the defect went unnoticed.

## Requirements

### Requirement: A register-scoped schema-slug miss MUST NOT fall back to global resolution

When a caller supplies a register context and identifies a schema by slug, the
system MUST resolve that slug only among the schema ids the register carries, and
MUST throw `SchemaNotInRegisterException` when none matches.

The exception message MUST name the register, the requested slug, and the count of
same-slug schemas elsewhere on the instance, and MUST name the repair command. A
bare "schema not found" reads as "your slug is wrong", which is the one conclusion
that is certainly false when duplicates demonstrably exist.

#### Scenario: A slug the register does not carry is refused

- **GIVEN** a register carrying schemas `[9173, 9174, 9177]`
- **WHEN** a caller resolves a slug none of them carries
- **THEN** the system MUST throw `SchemaNotInRegisterException`
- **AND** MUST NOT call the global resolver

#### Scenario: A register-less caller keeps global resolution

- **GIVEN** a caller that supplies no register context
- **WHEN** it resolves a schema slug
- **THEN** the system MUST resolve globally exactly as before

### Requirement: A lost register-schema linkage MUST be repairable from physical storage

A schema row carries no register column, so a register's `schemas` list cannot be
rebuilt from the schema table. The system MUST reconstruct it from the physical
object tables, whose names encode the pairing as
`oc_openregister_table_<registerId>_<schemaId>`.

The repair MUST be additive only, MUST NOT infer linkage from slug similarity or
application ownership, and MUST be exposed as an operator command that is dry-run by
default.

#### Scenario: Linkage is reconstructed from table names

- **GIVEN** a register with an empty `schemas` list and physical tables for schemas `9173`, `9174`, `9177`
- **WHEN** the repair inspects it
- **THEN** it MUST report those three ids together with each one's live row count

#### Scenario: The repair never removes an existing id

- **GIVEN** a register carrying an id with no physical table
- **WHEN** the repair runs
- **THEN** that id MUST be retained, because a schema may be linked before its first object is written
