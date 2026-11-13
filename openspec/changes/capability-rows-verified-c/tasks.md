# Tasks: capability-rows-verified-c

## 1. File type and size limits per property

- [x] 1.1 Pin both refusals in a test: save an object whose file property allows only `application/pdf` with a `.png` file, and with a PDF above `maxSize`. Verify: a PHPUnit test on `lib/Service/Object/SaveObject/FileUploadGuard.php` that reads the type and size messages.
      > 2026-11-13: `FileUploadGuardTest`: a PNG on a PDF-only property and a PDF one byte over 1 MB, each refused with its exact message, for both configuration shapes (register JSON in bytes, editor in megabytes); a PDF exactly at the limit is accepted.

## 2. Administrator actions on the audit trail

- [x] 2.1 Write an audit entry when a schema's authorisation rules change, naming the schema, the actor and the old and new rules. Verify: a PHPUnit test on the schema update path that reads the entry back.
      > 2026-11-13: `SchemaAuthorizationAuditListener` on `SchemaUpdatedEvent` (whose old schema is the stored row before the save) writes `schema.authorization.updated` with the actor, the schema id, uuid and slug, and the rules before and after. A save that keeps the rules writes nothing, and a failed write never fails the save (`SchemaAuthorizationAuditListenerTest`).
- [ ] 2.2 Write an audit entry when an API token is created or revoked, naming the token, its owner and the actor, never the token value. Verify: a PHPUnit test on the token routes.
- [ ] 2.3 Write an audit entry when a schema or register is created, changed or deleted. Verify: a Newman request that changes a schema and reads the audit trail.
