# Tasks: capability-rows-verified-c

## 1. File type and size limits per property

- [ ] 1.1 Pin both refusals in a test: save an object whose file property allows only `application/pdf` with a `.png` file, and with a PDF above `maxSize`. Verify: a PHPUnit test on `lib/Service/Object/SaveObject/FileUploadGuard.php` that reads the type and size messages.

## 2. Administrator actions on the audit trail

- [ ] 2.1 Write an audit entry when a schema's authorisation rules change, naming the schema, the actor and the old and new rules. Verify: a PHPUnit test on the schema update path that reads the entry back.
- [ ] 2.2 Write an audit entry when an API token is created or revoked, naming the token, its owner and the actor, never the token value. Verify: a PHPUnit test on the token routes.
- [ ] 2.3 Write an audit entry when a schema or register is created, changed or deleted. Verify: a Newman request that changes a schema and reads the audit trail.
