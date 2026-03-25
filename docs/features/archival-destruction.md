# Archival & Destruction Workflow

## Standards

| Standard | Description |
|----------|-------------|
| GEMMA Archiveringscomponent | Dutch municipal reference architecture for archival |
| Archiefwet 1995 | Dutch Archives Act governing retention and destruction |
| Selectielijst Gemeenten | Municipal selection list defining retention categories |
| NEN-ISO 15489 | International standard for records management |

## Overview

OpenRegister provides a complete archival and destruction workflow for managing the lifecycle of registered objects. This includes selection lists that define retention categories, automated retention tracking per object, destruction list generation when retention periods expire, and a formal approval/rejection workflow before permanent deletion.

## Status

**Routes: NOT YET REGISTERED** -- The `ArchivalController` class and all supporting code (entities, mappers, service, background job) exist in the codebase, but the routes have not been added to `appinfo/routes.php`. The archival API endpoints are therefore not yet accessible. The database migration (`Version1Date20260325000000`) also needs to be applied to create the `selection_lists` and `destruction_lists` tables.

## Key Capabilities

### Selection List Management
- Full CRUD for selection list entries (category, retention years, action type)
- Each selection list entry maps to an archival category from the Selectielijst Gemeenten
- Configurable retention period in years
- Action types: `destroy`, `transfer`, `permanent` (permanent preservation)

### Retention Metadata
- Per-object retention metadata stored in the object's `retention` JSON field
- Tracks retention start date, category reference, and computed expiry
- API to get and set retention metadata on individual objects

### Destruction List Generation
- Automated generation of destruction lists based on expired retention periods
- Background job (`DestructionCheckJob`) runs daily to identify objects past retention
- Destruction lists group objects for batch review

### Approval Workflow
- Formal approve/reject workflow for destruction lists
- Approval triggers permanent deletion of listed objects
- Rejection removes individual objects from a destruction list with reason tracking
- Full audit trail via OpenRegister's standard audit logging

## Architecture

### Backend Files

| File | Purpose |
|------|---------|
| `lib/Controller/ArchivalController.php` | API controller with 11 endpoints |
| `lib/Service/ArchivalService.php` | Business logic for retention and destruction |
| `lib/Db/SelectionList.php` | Selection list entity |
| `lib/Db/SelectionListMapper.php` | Selection list database mapper |
| `lib/Db/DestructionList.php` | Destruction list entity |
| `lib/Db/DestructionListMapper.php` | Destruction list database mapper |
| `lib/BackgroundJob/DestructionCheckJob.php` | Daily cron job for retention expiry checks |
| `lib/Migration/Version1Date20260325000000.php` | Database migration for archival tables |

### API Endpoints (Planned)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/archival/selection-lists` | List all selection list entries |
| GET | `/api/archival/selection-lists/{id}` | Get a single selection list entry |
| POST | `/api/archival/selection-lists` | Create a selection list entry |
| PUT | `/api/archival/selection-lists/{id}` | Update a selection list entry |
| DELETE | `/api/archival/selection-lists/{id}` | Delete a selection list entry |
| GET | `/api/archival/objects/{id}/retention` | Get retention metadata for an object |
| PUT | `/api/archival/objects/{id}/retention` | Set retention metadata for an object |
| GET | `/api/archival/destruction-lists` | List all destruction lists |
| GET | `/api/archival/destruction-lists/{id}` | Get a single destruction list |
| POST | `/api/archival/destruction-lists/generate` | Generate destruction list from expired retentions |
| POST | `/api/archival/destruction-lists/{id}/approve` | Approve and execute a destruction list |
| POST | `/api/archival/destruction-lists/{id}/reject` | Reject objects from a destruction list |

## API Test Results (2026-03-25)

All archival endpoints return **HTTP 404** because routes are not yet registered in `appinfo/routes.php`. The controller code, entities, mappers, service layer, and background job are fully implemented but not wired up.

| Endpoint | Result |
|----------|--------|
| GET `/api/archival/selection-lists` | 404 (route not registered) |
| POST `/api/archival/selection-lists` | 404 (route not registered) |
| GET `/api/archival/destruction-lists` | 404 (route not registered) |
