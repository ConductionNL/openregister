## Context

OpenRegister already has:
- **WebhookService** with CloudEvents 1.0 support via `CloudEventFormatter`, event filtering, HMAC signing, retry logic (exponential/linear/fixed), and delivery logging via `WebhookLog`
- **MappingService** with Twig-based `executeMapping()` that transforms any input array into any output array using a `Mapping` entity (property mapping, dot-notation, casting, passThrough, unset)
- **Mapping entity** with fields: name, mapping (Twig templates), unset, cast, passThrough
- **40+ domain events** dispatched via Nextcloud EventDispatcher (ObjectCreated, ObjectUpdated, ObjectDeleted, etc.)
- **WebhookEventListener** that routes events to `WebhookService.dispatchEvent()`
- **AuditTrail entity** with schema/register/object tracking, action field, changed data, user/session/IP, GDPR fields
- **ReferentialIntegrityService** with `canDelete()` analysis and `applyDeletionActions()` execution (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT, NO_ACTION)
- **SaveObject** with `$ref` resolution via `resolveSchemaReference()` — resolves schema references but does NOT validate that referenced object UUIDs exist
- **Full RBAC** — schema-level, property-level, contextual (org-match), query-time filtering via `MagicRbacHandler`, consumers mapped to Nextcloud users

Procest has custom `NotificatieService` (~240 lines) that builds ZGW notification payloads and delivers them to abonnement (subscription) callback URLs. This duplicates WebhookService's delivery infrastructure — the only thing missing is payload transformation before delivery.

## Goals / Non-Goals

**Goals:**
- Add a generic `mapping` field to webhooks so event payloads can be transformed via `MappingService` before delivery
- Add configurable reference existence validation in the save pipeline for `$ref` properties
- Add AuditTrail logging to `applyDeletionActions()` for referential integrity actions
- Document how ZGW scopes map to OpenRegister's existing group-based RBAC (consumers=users, scopes=groups)

**Non-Goals:**
- ZGW-specific formatter code in OpenRegister (the mapping approach is fully generic)
- New RBAC enforcement code (already exists in PropertyRbacHandler, MagicRbacHandler, ConditionMatcher)
- Confidentiality level enforcement (already handled by authorization rules on schema properties)
- Full ZGW NRC API compliance (OpenRegister is not a ZGW reference implementation)

## Decisions

### Decision 1: Webhook payload mapping via MappingService — not a format-specific formatter

Add an optional `mapping` field (integer, nullable) to the `Webhook` entity that references a `Mapping` entity ID. When set, `WebhookService.deliverWebhook()` loads the Mapping and runs the event payload through `MappingService.executeMapping()` before sending.

The event payload passed to the mapping includes all available context:

```json
{
  "event": "ObjectCreatedEvent",
  "action": "create",
  "object": { "uuid": "abc-123", "title": "...", "status": "open", ... },
  "schema": { "slug": "zaak", "name": "Case", ... },
  "register": { "slug": "procest", "name": "Procest", ... },
  "timestamp": "2026-03-08T10:00:00+01:00"
}
```

A consumer app (like Procest) creates a Mapping that transforms this into whatever format its subscribers expect. For ZGW notifications, Procest would configure:

```json
{
  "mapping": {
    "kanaal": "{{ register.slug }}",
    "hoofdObject": "{{ baseUrl }}/{{ register.slug }}/v1/{{ schema.slug }}en/{{ object.uuid }}",
    "resource": "{{ schema.slug }}",
    "resourceUrl": "{{ baseUrl }}/{{ register.slug }}/v1/{{ schema.slug }}en/{{ object.uuid }}",
    "actie": "{{ action }}",
    "aanmaakdatum": "{{ timestamp }}",
    "kenmerken": {}
  }
}
```

This is fully generic — any app can create any mapping for any payload format. OpenRegister has zero knowledge of ZGW.

**Rationale:** OpenRegister already has a complete Twig-based mapping engine. Adding a ZGW-specific formatter would duplicate what MappingService already does. The mapping approach means any future payload format (FHIR, STUF, custom) works the same way — just configure a different Mapping.

**Implementation in WebhookService.deliverWebhook():**

```
1. Build the event payload (existing code)
2. If webhook has a mapping ID:
   a. Load the Mapping entity via MappingMapper
   b. Run MappingService.executeMapping(mapping, payload)
   c. Use the transformed result as the delivery payload
3. Else if CloudEvents is configured (existing):
   a. Use CloudEventFormatter (existing behavior)
4. Else:
   a. Use raw payload (existing default)
```

### Decision 2: Reference existence validation as a schema property configuration

Add a `validateReference` boolean to schema property configuration (alongside existing `$ref`, `onDelete`, `inversedBy`):

```json
{
  "assignee": {
    "type": "string",
    "$ref": "person-schema-id",
    "validateReference": true,
    "onDelete": "SET_NULL"
  }
}
```

When `validateReference` is `true` (default: `false`), `SaveObject` checks that the UUID stored in the property points to an existing object in the referenced schema before saving. This is checked after `$ref` resolution but before the actual save.

The validation:
1. Gets the target schema ID from `$ref` via `resolveSchemaReference()`
2. Gets the register from the property's `register` config or falls back to the object's register
3. Queries for the object by UUID using `ObjectService`
4. If not found, throws an exception with a clear message: `"Referenced object '{uuid}' not found in schema '{schemaSlug}'"`

For array properties (`type: array` with `items.$ref`), each UUID in the array is validated.

**Rationale:** Making it opt-in (configurable per property) avoids performance impact on schemas that don't need it. Import/migration workflows may intentionally create objects before their references exist. The `validateReference` flag gives schema designers control.

### Decision 3: AuditTrail entries for referential integrity actions

In `ReferentialIntegrityService.applyDeletionActions()`, after each action is executed, create an `AuditTrail` entry:

| Integrity Action | AuditTrail action | AuditTrail changed |
|---|---|---|
| CASCADE delete | `referential_integrity.cascade_delete` | `{"deletedObject": uuid, "reason": "cascade from {parentUuid}", "property": "assignee", "parentSchema": "order"}` |
| SET_NULL | `referential_integrity.set_null` | `{"object": uuid, "property": "assignee", "previousValue": "parent-uuid", "reason": "parent deleted"}` |
| SET_DEFAULT | `referential_integrity.set_default` | `{"object": uuid, "property": "assignee", "previousValue": "parent-uuid", "defaultValue": "...", "reason": "parent deleted"}` |
| RESTRICT block | `referential_integrity.restrict_blocked` | `{"blockedObject": parentUuid, "blockers": [...], "reason": "RESTRICT constraint"}` |

The AuditTrail entry includes the schema/register/object IDs of the affected object, plus the user who initiated the original deletion. This provides a complete chain of what happened during cascade operations.

**Rationale:** AuditTrail entity already has all the right fields (schema, register, object, objectUuid, action, changed, user). Just needs to be written during deletion processing.

### Decision 4: RBAC-scopes documentation update only

ZGW autorisaties maps directly to existing OpenRegister concepts:
- **Applicatie** (consumer) → `Consumer` entity with `userId` field → Nextcloud user
- **Scope** (e.g., `zrc.lezen`) → Nextcloud group membership → checked via schema `authorization.read/create/update/delete`
- **heeftAlleAutorisaties** (superuser) → admin group membership
- **maxVertrouwelijkheidaanduiding** → property-level `authorization` with conditional matching

No code changes needed. The `rbac-scopes` spec gets a documentation section explaining this mapping.

## Risks / Trade-offs

- **Reference validation performance**: Checking object existence on every save adds a query per `$ref` property with `validateReference: true`. Mitigated by making it opt-in and using the existing schema/object caches in SaveObject.
- **Mapping complexity**: Twig templates for webhook payload transformation can become complex for formats with nested URL construction (like ZGW's `hoofdObject`). Mitigated by MappingService's existing Twig runtime functions and the ability to add custom filters.
- **Audit trail volume**: CASCADE deletions of deeply nested objects could generate many AuditTrail entries. Mitigated by the existing `expires`/`retentionPeriod` fields for automatic cleanup.
- **Mapping entity lifecycle**: If a Mapping referenced by a webhook is deleted, webhook delivery would fail. Mitigated by null-checking the mapping before delivery and falling back to raw payload.
