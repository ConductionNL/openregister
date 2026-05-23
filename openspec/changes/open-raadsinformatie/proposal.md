---
kind: doc
---

# Open Raadsinformatie (ORI) — Specification Redirect

## Why

OpenRegister integrates with external data sources through specialized adapter modules. Open Raadsinformatie (ORI) is an initiative providing structured access to Dutch municipal council information (raadsinformatie). This proposal captures the adapter pattern for integrating ORI data sources into OpenRegister's register infrastructure.

The canonical technical specification for Open Raadsinformatie is maintained by the Procest application under version control and cross-project governance. This change documents OpenRegister's adapter implementation for consuming ORI data within our platform's object and register models.

## What Changes

### Shipped in this change

- **ORI Integration Adapter** — A specialized adapter module that interfaces with Open Raadsinformatie data sources and translates ORI entities into OpenRegister's unified object model.
- **Data Source Registration** — Administrative interface for registering and configuring ORI data sources (endpoints, credentials, sync schedules).
- **Object Mapping** — Bidirectional mapping between ORI entities (council documents, decisions, procedures) and OpenRegister's schema-based object representation.
- **Synchronization Service** — Batch and incremental sync capabilities to pull ORI data, validate against schemas, and persist as OpenRegister objects.

### Integration scope

- Placement: Sub-page under Integrations / External adapters (SUB_PAGE)
- Consumed registers: New ORI-sourced registers created via the admin interface
- Dependent apps: opencatalogi, softwarecatalog (for linked search results)

### Non-breaking

This change introduces a new adapter capability without modifying existing registers or object storage. It is purely additive and defaults to disabled until an admin configures an ORI data source.

## Canonical Specification

The normative specification for Open Raadsinformatie requirements, data formats, and API contracts is **owned and maintained by the Procest application**. Implementers MUST refer to `procest/openspec/specs/open-raadsinformatie/spec.md` for:

- ORI data entity definitions and schema contracts
- Sync protocol and error handling semantics
- Field mapping and validation rules
- Performance and reliability requirements

This OpenRegister change document covers only the **adapter pattern and integration surface** within OpenRegister's framework. Design decisions specific to ORI data representation are deferred to the Procest specification.

## Out of scope

- ORI API design and versioning (Procest-owned)
- ORI entity schema definitions (Procest-owned)
- ORI data source credentials management beyond OpenRegister's vault integration
- Cross-project ORI governance beyond federation via openregister's existing cross-app patterns

## Verification

- OpenRegister adapter loads without errors
- Admin UI allows registering a test ORI data source
- Sample ORI objects sync and persist in a created register
- opencatalogi and softwarecatalog can query ORI-sourced objects via their existing APIs
