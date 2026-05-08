# Contacts Integration

| Property   | Value |
|------------|-------|
| Status     | Implemented (routes not yet registered) |
| Standards  | GEMMA Klantcontactcomponent, CardDAV (RFC 6352) |
| App        | OpenRegister |

## Overview

OpenRegister provides a contacts integration that links Nextcloud CardDAV contacts to register objects. It offers fuzzy matching by email, name, and organization, enriches matches with deep link URLs, and integrates with the Nextcloud Contacts Menu via a provider interface.

**Current state:** The backend services (`ContactMatchingService`, `ContactsController`, `ContactsMenuProvider`) are fully implemented, but the API routes for `ContactsController` are **not yet registered** in `appinfo/routes.php`. The `ContactsMenuProvider` (which hooks into the Nextcloud contacts menu) is functional and does not require API routes.

## Key Components

| Component | File | Purpose |
|-----------|------|---------|
| `ContactMatchingService` | `lib/Service/ContactMatchingService.php` | Core matching engine with email/name/org scoring |
| `ContactsController` | `lib/Controller/ContactsController.php` | REST API for contact matching and object linking |
| `ContactsMenuProvider` | `lib/Contacts/ContactsMenuProvider.php` | Nextcloud `IProvider` integration for contacts menu |
| `ContactService` | `lib/Service/ContactService.php` | CardDAV contact CRUD operations |
| `ContactLink` | `lib/Db/ContactLink.php` | Entity for contact-to-object links |
| `ContactLinkMapper` | `lib/Db/ContactLinkMapper.php` | Database mapper for contact links |

## Matching Scores

The `ContactMatchingService` uses a weighted confidence scoring system:

| Match Type | Confidence | Details |
|------------|-----------|---------|
| Email (exact) | 1.0 | Primary identifier, highest confidence |
| Name (full match) | 0.7 | All name parts match |
| Name (partial match) | 0.4 | Some name parts match |
| Organization | 0.5 | Organization name match |

Matching results are cached in APCu with a TTL of 60 seconds.

## API Endpoints (Not Yet Routed)

The following endpoints exist in `ContactsController` but are **not registered in routes.php**:

| Method | Intended URL | Controller Method | Description |
|--------|-------------|-------------------|-------------|
| GET | `/api/contacts/match` | `match()` | Fuzzy match contacts by email/name/org |
| GET | `/api/objects/{register}/{schema}/{id}/contacts` | `index()` | List contacts linked to an object |
| POST | `/api/objects/{register}/{schema}/{id}/contacts` | `create()` | Link a contact to an object |
| PUT | `/api/objects/{register}/{schema}/{id}/contacts/{contactId}` | `update()` | Update a contact link |
| DELETE | `/api/objects/{register}/{schema}/{id}/contacts/{contactId}` | `destroy()` | Remove a contact link |
| GET | `/api/contacts/{contactUid}/objects` | `objects()` | List objects linked to a contact |

### Match Endpoint Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `email` | string | One of email/name required | Email address to match |
| `name` | string | One of email/name required | Display name to match |
| `organization` | string | No | Organization name for additional scoring |

### Match Response

```json
{
  "matches": [
    {
      "contactUid": "abc-123",
      "displayName": "John Doe",
      "email": "john@example.com",
      "confidence": 1.0,
      "deepLinks": [...]
    }
  ],
  "total": 1
}
```

## ContactsMenuProvider

The `ContactsMenuProvider` implements `OCP\Contacts\ContactsMenu\IProvider` and is registered automatically with Nextcloud. When a user clicks a contact in the Nextcloud header contacts menu, the provider enriches the contact entry with links to related OpenRegister objects.

## API Test Results (2026-03-25)

| Endpoint | HTTP Status | Result |
|----------|-------------|--------|
| `GET /api/contacts/match?email=admin@example.com` | 404 | Routes not registered |
| `GET /api/contacts/match?name=Admin` | 404 | Routes not registered |

The 404 responses confirm that the `ContactsController` routes need to be added to `appinfo/routes.php` before the API is usable.
