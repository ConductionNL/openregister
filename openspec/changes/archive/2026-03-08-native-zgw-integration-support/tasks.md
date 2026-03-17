## Tasks

### Webhook Payload Mapping

- [x] Add `mapping` field (nullable integer) to `Webhook` entity (`lib/Db/Webhook.php`) with type registration in constructor, getter/setter annotations, and inclusion in `jsonSerialize()`
- [x] Add database migration to add nullable `mapping` column to `oc_openregister_webhooks` table
- [x] Add `MappingService` and `MappingMapper` dependencies to `WebhookService` constructor
- [x] Enrich the event payload in `WebhookEventListener` or `WebhookService.dispatchEvent()` to include full context: `event` (class short name), `action` (create/update/delete), `object` (full data), `objectUuid`, `schema` (slug, name, uuid), `register` (slug, name, uuid), `timestamp` (ISO 8601)
- [x] Update `WebhookService.deliverWebhook()` to check if the webhook has a mapping ID set — if so, load the Mapping via `MappingMapper`, run `MappingService.executeMapping(mapping, payload)`, and use the result as the delivery payload; if mapping not found or execution fails, fall back to raw payload with a warning log
- [x] Ensure HMAC signature is computed from the mapped payload (after transformation), not the raw input
- [x] Add unit tests for webhook payload mapping covering: mapping transforms payload, missing mapping falls back to raw, mapping error falls back to raw with warning, HMAC uses mapped payload, null mapping uses existing behavior, mapping takes precedence over CloudEvents

### Reference Existence Validation

- [x] Add `validateReferenceExists()` method to `SaveObject` that accepts a property name, UUID value, target schema reference, and register context, then queries for the object's existence and throws a 422 exception if not found
- [x] Integrate `validateReferenceExists()` into the save pipeline: after schema property iteration, for each property with `validateReference: true` and a non-null `$ref`, validate the stored UUID(s) — skip validation for unchanged values on updates
- [x] Handle array properties: when `type: array` with `items.$ref` and `validateReference: true`, validate each UUID in the array
- [x] Resolve target register: use property-level `register` config if present, fall back to the object's own register
- [x] Add unit tests for reference validation covering: valid reference passes, invalid reference throws 422, null/empty skipped, array with mixed valid/invalid, unchanged values on update skipped, cross-register references

### Deletion Audit Trail

- [x] Add `AuditTrailMapper` dependency to `ReferentialIntegrityService` constructor
- [x] Create `logIntegrityAction()` private method in `ReferentialIntegrityService` that builds and persists an `AuditTrail` entry with the action type, affected object details, trigger object details, and initiating user
- [x] Call `logIntegrityAction()` in `applyDeletionActions()` after each CASCADE delete with action `referential_integrity.cascade_delete`
- [x] Call `logIntegrityAction()` in `applyDeletionActions()` after each SET_NULL update with action `referential_integrity.set_null`
- [x] Call `logIntegrityAction()` in `applyDeletionActions()` after each SET_DEFAULT update with action `referential_integrity.set_default`
- [x] Call `logIntegrityAction()` from the RESTRICT block path (in `canDelete()` or the controller) with action `referential_integrity.restrict_blocked`
- [x] Wrap AuditTrail writes in try/catch so failures log warnings but don't block the integrity action
- [x] Add unit tests for deletion audit trail covering: cascade creates entry, set_null creates entry, set_default creates entry, restrict creates entry, no_action creates no entry, user context propagated, audit failure doesn't block deletion

### RBAC Scopes Documentation

- [x] Add a documentation section to the `rbac-scopes` spec explaining the consumer=user, scope=group mapping: how ZGW applicaties map to OpenRegister Consumer entities, how ZGW scopes map to Nextcloud groups checked via schema/property authorization rules, and how superuser mode maps to admin group membership
