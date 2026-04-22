# TMLO Metadata (Toepassingsprofiel Metadatastandaard Lokale Overheden)

## Standards

| Standard | Description |
|----------|-------------|
| TMLO 1.2 | Dutch application profile for local government metadata |
| MDTO | Metadatastandaard voor Duurzaam Toegankelijke Overheidsinformatie |
| NEN-ISO 23081 | International standard for records management metadata |

## Overview

OpenRegister implements the TMLO standard for Dutch archival metadata on registered objects. Each object carries a `tmlo` JSON column that stores structured metadata fields required by the TMLO 1.2 specification. The system supports auto-population of TMLO fields on object creation, field validation, archival status transitions, and export to MDTO-compliant XML for interoperability with national archival systems.

## Status

**Routes: REGISTERED AND ACTIVE** -- Three TMLO/MDTO endpoints are registered in `appinfo/routes.php` and respond to requests. The endpoints require numeric register/schema IDs. TMLO functionality is per-register opt-in; registers without TMLO enabled return HTTP 400 with a clear message.

## Key Capabilities

### TMLO JSON Column
- Every `ObjectEntity` has a nullable `tmlo` JSON column (default: empty array)
- Stores structured TMLO fields: identification, name, classification, archival status, retention category, dates, creator, etc.
- Persisted alongside the object's main data in the `object` JSON column

### Auto-Populate on Create
- When an object is created in a TMLO-enabled register, the `SaveObject` service auto-populates default TMLO fields
- Fields populated include: identification (UUID), name, creation date, creator, and default archival status

### Archival Status Transitions
- TMLO `archiefStatus` field tracks lifecycle: `in_bewerking` (in progress), `vastgesteld` (finalized), `overgebracht` (transferred), `vernietigd` (destroyed)
- Status transitions are validated by `TmloService`

### Field Validation
- `TmloService` validates TMLO field structure and required fields
- Enforces TMLO 1.2 element constraints (required vs optional fields)

### MDTO XML Export
- Single object export: generates MDTO-compliant XML for one object
- Batch export: generates MDTO XML for all objects in a register/schema combination
- XML follows the MDTO namespace and schema for interoperability with e-Depot and other national archival infrastructure

## Architecture

### Backend Files

| File | Purpose |
|------|---------|
| `lib/Controller/TmloController.php` | API controller with 3 endpoints (summary, single export, batch export) |
| `lib/Service/TmloService.php` | TMLO business logic, validation, XML generation |
| `lib/Db/ObjectEntity.php` | Entity with `tmlo` JSON column (line 284) |
| `lib/Service/Object/SaveObject.php` | Auto-populates TMLO on object creation |
| `lib/Db/MagicMapper.php` | Includes TMLO in search/query handling |
| `lib/Db/MagicMapper/MagicSearchHandler.php` | TMLO field search support |

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/objects/{register}/{schema}/tmlo/summary` | TMLO metadata summary for a register/schema |
| GET | `/api/objects/{register}/{schema}/export/mdto` | Batch MDTO XML export for all objects |
| GET | `/api/objects/{register}/{schema}/{id}/export/mdto` | Single object MDTO XML export |

**Note:** The `{register}` and `{schema}` parameters accept numeric IDs. Slug-based lookups fail with HTTP 500 due to the controller using `find((int) $register)` which casts slugs to `0`.

## API Test Results (2026-03-25)

| Endpoint | Parameters | Result |
|----------|-----------|--------|
| GET `/api/objects/voorzieningen/sector/tmlo/summary` | Slug-based | 500 -- register lookup fails (slug cast to int 0) |
| GET `/api/objects/3/3/tmlo/summary` | Numeric IDs | **400** -- "TMLO is not enabled on this register" (correct response, register exists but TMLO not enabled) |
| GET `/api/objects/3/3/export/mdto` | Numeric IDs | 500 -- HTML error page (likely same cast issue in exportBatch) |
| GET `/api/objects/voorzieningen/sector/export/mdto` | Slug-based | 500 -- register lookup fails |

### Known Issues

1. **Slug resolution not implemented in TmloController** -- The controller calls `$this->registerMapper->find((int) $register)` which only works with numeric database IDs. Other controllers (like ObjectsController) use a resolver that handles UUID, slug, and numeric ID lookups. TmloController should use the same resolution pattern.

2. **TMLO not enabled on any test register** -- No registers in the test environment have TMLO enabled, so the summary endpoint correctly returns 400. To fully test, a register would need TMLO configuration added.
