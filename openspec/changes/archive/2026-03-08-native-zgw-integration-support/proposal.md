## Why

Procest built ~400 lines of custom ZGW notification services (`NotificatieService`, kanaal/abonnement management) on top of OpenRegister's existing webhook/event infrastructure. OpenRegister already has comprehensive group-based RBAC (schema-level, property-level, contextual matching, query-time filtering) and multi-auth support (JWT, Basic, OAuth2, API Key) — ZGW scopes simply map to Nextcloud groups, which is a configuration concern. However, three genuine gaps remain: (1) webhook payloads cannot be transformed before delivery (so consumers must accept OpenRegister's raw format), (2) reference existence validation when saving objects with `$ref` properties, and (3) audit logging for referential integrity actions. Closing these gaps lets Procest (and other consumers) hook natively into OpenRegister without custom middleware.

## What Changes

- **Webhook payload mapping**: Add an optional `mapping` field to the Webhook entity that references an OpenRegister Mapping. When set, `WebhookService` runs the event payload through `MappingService.executeMapping()` before delivery. This is fully generic — any app can configure any Twig-based mapping to transform webhook payloads into whatever format their subscribers expect (ZGW notifications, custom formats, etc.). Procest configures a mapping that transforms OpenRegister events into ZGW notification format (kanaal, hoofdObject, resource, resourceUrl, actie). This replaces Procest's custom `NotificatieService` (~200 lines).
- **Reference existence validation on save**: When a schema property has `$ref` pointing to another schema, validate that the referenced object UUID actually exists before saving. Currently OpenRegister strips `$ref` for Opis validation but does not check whether the target object exists, allowing dangling references. Configurable per property via `validateReference: true`.
- **Deletion audit trail**: Log all referential integrity actions (CASCADE deletes, SET_NULL updates, RESTRICT blocks) in OpenRegister's existing AuditTrail entity. Currently `applyDeletionActions()` executes cascades silently with no record of what was deleted or why.
- **Consumer-to-group documentation**: Document how ZGW scopes map to OpenRegister's existing group-based RBAC. Consumers already map to Nextcloud users (`Consumer.userId`), scopes are Nextcloud groups, and schema/property-level authorization already enforces per-group CRUD access. This is a documentation and configuration guide, not new code.

## Capabilities

### New Capabilities
- `webhook-payload-mapping`: Allow webhooks to reference an OpenRegister Mapping entity to transform event payloads before delivery — fully generic, not format-specific
- `reference-existence-validation`: Validate that `$ref` target objects exist when saving objects, preventing dangling references
- `deletion-audit-trail`: Audit logging for all referential integrity actions (cascade, nullify, restrict, set-default) using the existing AuditTrail entity

### Modified Capabilities
- `rbac-scopes`: Add documentation section explaining consumer=user, scope=group mapping and how existing schema/property authorization covers ZGW autorisaties requirements

## Impact

- **OpenRegister Webhook entity**: New optional `mapping` field referencing a Mapping entity
- **OpenRegister WebhookService**: Apply mapping transformation before delivery when configured
- **OpenRegister ObjectService/SaveObject**: New reference existence check in save pipeline
- **OpenRegister ReferentialIntegrityService**: Extended to write AuditTrail entries during deletion actions
- **Procest**: Can remove custom `NotificatieService` (~200 lines) and configure a Mapping + Webhook instead
- **Other consumer apps**: Any app using OpenRegister can transform webhook payloads via mappings
- **Breaking**: None — all additions are opt-in
