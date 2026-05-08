# Deep Link Registry

## Overview

The Deep Link Registry enables consuming Nextcloud apps (Procest, Pipelinq, OpenCatalogi, ZaakAfhandelApp, and others) to claim ownership of specific OpenRegister (register, schema) combinations by registering URL templates at boot time. When Nextcloud's unified search returns objects belonging to a claimed combination, search results link directly to the consuming app's detail view instead of OpenRegister's generic object view.

This decouples object **storage** (OpenRegister) from object **presentation** (consuming apps), allowing each app to own its user experience while sharing a common data layer.

## How It Works

### Boot-Time Registration

The registry is event-driven and in-memory only:

1. OpenRegister dispatches a `DeepLinkRegistrationEvent` during `Application::boot()`
2. Consuming apps listen for this event in their own `Application::boot()`
3. On the event, they call `register()` to claim a (register, schema) → URL template mapping
4. The mappings live in-memory for the lifetime of the request

```php
// In consuming app's Application.php:
$dispatcher->addListener(
    DeepLinkRegistrationEvent::class,
    function (DeepLinkRegistrationEvent $event) {
        $event->register(
            appId: 'procest',
            registerSlug: 'zaken-register',
            schemaSlug: 'zaken',
            urlTemplate: '/apps/procest/zaken/{uuid}',
            icon: 'zaken'
        );
    }
);
```

### URL Resolution

When `ObjectsProvider` (the unified search provider) generates a result URL for an object:

1. It calls `DeepLinkRegistryService.resolve(registerSlug, schemaSlug)` to check for a claim
2. If a claim exists, the URL template is rendered with the object's UUID: `/apps/procest/zaken/550e8400-...`
3. If no claim exists, the URL falls back to OpenRegister's own object view: `/apps/openregister/objects/{uuid}`

### URL Template Variables

| Variable | Resolves to |
|----------|-------------|
| `{uuid}` | Object UUID |
| `{register}` | Register slug |
| `{schema}` | Schema slug |

### Icon Resolution

The icon is used in unified search results. If no icon is specified in the registration, the consuming app's default icon is used. Falls back to OpenRegister's icon if the app ID is not recognized by Nextcloud's icon system.

## Key Classes

| Class | Location | Purpose |
|-------|----------|---------|
| `DeepLinkRegistryService` | `lib/Service/DeepLinkRegistryService.php` | In-memory registry with `register()`, `resolve()`, `resolveUrl()`, `resolveIcon()`, `hasRegistrations()`, `reset()` |
| `DeepLinkRegistrationEvent` | `lib/Event/DeepLinkRegistrationEvent.php` | Event dispatched during boot; wraps the registry service |
| `DeepLinkRegistration` | `lib/Dto/DeepLinkRegistration.php` | Value object for a single registration (appId, registerSlug, schemaSlug, urlTemplate, icon) |

## Use Cases

### Procest (Case Management)

Procest registers the `zaken-register/zaken` combination. When a user searches for a case in Nextcloud's global search, the result links directly to the case detail view in Procest — not to OpenRegister's raw object view.

### Pipelinq (CRM)

Pipelinq registers `crm-register/contacten` and `crm-register/organisaties`. Search results for contacts and organisations open in Pipelinq's CRM interface.

### OpenCatalogi (Service Catalogue)

OpenCatalogi registers its catalogue schemas. Search results for applications and services open in the OpenCatalogi detail view.

### ZaakAfhandelApp

ZaakAfhandelApp registers zaaktype schemas. Case search results open in the case handling interface rather than the raw data view.

## In-Memory Design

The registry is deliberately in-memory only — there is no database table for registrations. This means:

- No persistent configuration overhead
- Registrations automatically reflect the currently installed and enabled apps
- If an app is disabled, its registrations are simply absent on the next request
- No stale registrations from uninstalled apps

The trade-off is that registrations must be re-established on every request. Since `Application::boot()` runs on every request, this is transparent.

## Related Features

- [Object Storage & Lifecycle](object-storage.md) — objects whose URLs are being resolved
- [Event-Driven Architecture](event-driven-architecture.md) — boot-time event mechanism
- [OpenAPI & GraphQL APIs](api-generation.md) — consuming apps can also use the generated API directly
