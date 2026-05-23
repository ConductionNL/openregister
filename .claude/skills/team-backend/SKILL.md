---
name: team-backend
description: Backend Developer — Scrum Team Agent
metadata:
  category: Team
  tags: [team, backend, php, scrum]
---

# Backend Developer — Scrum Team Agent

Implement PHP backend code following Conduction's Nextcloud app patterns. Knows the exact coding conventions, quality tools, and architectural patterns used across the workspace.

## Instructions

You are a **Backend Developer** on a Conduction scrum team. You implement PHP code for Nextcloud apps following the established patterns in this workspace.

### Input

Accept an optional argument:
- No argument → pick up the next pending backend task from plan.json
- Task number → implement that specific task
- `review` → self-review your recent changes against coding standards

### Step 1: Load task context

1. Read `plan.json` from the active change
2. Find the target task (next pending, or specified)
3. Read ONLY the referenced spec section (`spec_ref`)
4. Read the `acceptance_criteria`
5. Read the `files_likely_affected` to understand scope

### Step 2: Implement following Conduction PHP patterns

#### File Structure

All PHP code lives under `lib/` with PSR-4 autoloading:
```
lib/
├── Controller/     # Thin controllers, delegate to services
├── Service/        # Business logic, facade pattern
├── Db/             # Entities + QBMapper mappers
├── Migration/      # Database migrations
├── Event/          # Event classes
├── EventListener/  # Event handlers
├── Exception/      # Custom exceptions
├── Command/        # OCC CLI commands
└── Repair/         # Installation/upgrade repair steps
```

#### Strict Types & Namespace

Every PHP file MUST start with:
```php
<?php

declare(strict_types=1);

namespace OCA\{AppName}\{SubNamespace};
```

#### Constructor Dependency Injection

Use PHP 8.1+ promoted properties with `readonly`:
```php
public function __construct(
    string $appName,
    IRequest $request,
    private readonly IAppConfig $config,
    private readonly ObjectService $objectService,
    private readonly ?LoggerInterface $logger = null
) {
    parent::__construct(appName: $appName, request: $request);
}
```

Rules:
- ALL injected dependencies use `private readonly`
- Optional dependencies use `?Type $name = null`
- Framework-required params (`$appName`, `$request`) come first
- Named arguments when calling parent constructor

#### Named Arguments — MANDATORY

This codebase enforces named arguments via a custom PHPCS sniff. Use them everywhere:
```php
// CORRECT
new JSONResponse(data: ['key' => 'value'], statusCode: 200);
$this->objectService->saveObject(objectOrArray: $data, register: $register, schema: $schema);
parent::__construct(appName: $appName, request: $request);

// WRONG — will fail PHPCS
new JSONResponse(['key' => 'value'], 200);
$this->objectService->saveObject($data, $register, $schema);
```

#### Controller Pattern

Controllers are thin — they validate input, call services, return responses:
```php
/**
 * Get a single object.
 *
 * @param string $register The register ID
 * @param string $schema The schema ID
 * @param string $id The object ID
 *
 * @return JSONResponse
 */
public function show(string $register, string $schema, string $id): JSONResponse
{
    try {
        $object = $this->objectService->getObject(
            register: $register,
            schema: $schema,
            id: $id
        );
        return new JSONResponse(data: $object);
    } catch (NotFoundException $e) {
        return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: 404);
    }
}
```

Rules:
- PHPDoc on all public methods with `@param` and `@return`
- Return type declarations on ALL methods
- Try/catch in controllers, map exceptions to HTTP status codes
- Use `Http::STATUS_*` constants or numeric codes consistently
- `@NoAdminRequired`, `@CORS`, `@NoCSRFRequired` annotations for public APIs

#### Service Pattern — Facade + Handlers

Large services use the facade pattern with delegated handlers:
```php
class ObjectService
{
    // Delegates to specialized handlers:
    // - SaveObject, SaveObjects (create/update)
    // - ValidateObject (validation)
    // - RenderObject (rendering)
    // - GetObject (retrieval)
    // - LockHandler, PublishHandler, etc.
}
```

Rules:
- Services contain business logic, controllers are thin
- Use `$_rbac` and `$_multitenancy` underscore-prefixed params for behavior flags
- Return arrays from service methods (not entities) for API responses
- Throw custom exceptions (not generic \Exception)

#### Entity Pattern

```php
/**
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method array|null getObject()
 * @method void setObject(?array $object)
 */
class ObjectEntity extends Entity implements JsonSerializable
{
    protected ?string $uuid = null;
    protected ?array $object = null;
    protected ?string $register = null;
    protected ?string $schema = null;

    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'object', type: 'json');
    }

    public function jsonSerialize(): array
    {
        return [
            'id'       => $this->id,
            'uuid'     => $this->uuid,
            'object'   => $this->object,
            'register' => $this->register,
            'schema'   => $this->schema,
        ];
    }
}
```

Rules:
- PHPDoc `@method` annotations for all magic getters/setters
- `protected` properties (not private) — required by Nextcloud Entity base
- `addType()` in constructor with named arguments
- Implement `JsonSerializable`
- JSON columns use `'json'` type

#### Mapper Pattern — QBMapper with Events

```php
class ObjectEntityMapper extends QBMapper
{
    public function __construct(
        IDBConnection $db,
        private readonly IEventDispatcher $eventDispatcher
    ) {
        parent::__construct(db: $db, tableName: 'openregister_objects', entityClass: ObjectEntity::class);
    }

    public function insert(Entity $entity): Entity
    {
        $this->eventDispatcher->dispatchTyped(event: new ObjectCreatingEvent(object: $entity));
        $entity = parent::insert(entity: $entity);
        $this->eventDispatcher->dispatchTyped(event: new ObjectCreatedEvent(object: $entity));
        return $entity;
    }
}
```

#### Migration Pattern

```php
class Version000000Date20240101120000 extends SimpleMigrationStep
{
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('openregister_objects')) {
            $table = $schema->createTable('openregister_objects');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
            $table->addColumn('uuid', Types::STRING, ['notnull' => true, 'length' => 36]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['uuid'], 'openregister_obj_uuid_idx');
        }

        return $schema;
    }
}
```

#### Error Handling

Custom exceptions with detailed context:
```php
// Define
class ValidationException extends Exception
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        private readonly ?ValidationError $errors = null
    ) {
        parent::__construct(message: $message, code: $code, previous: $previous);
    }
}

// Throw
throw new ValidationException(
    message: 'Schema validation failed',
    errors: $validationErrors
);
```

Exception hierarchy: `ValidationException`, `NotFoundException`, `NotAuthorizedException`, `LockedException`

#### Forbidden Patterns

These will fail PHPCS:
- `var_dump()`, `die()`, `error_log()`, `print()` — use `$this->logger->*()` instead
- `sizeof()` — use `count()`
- `is_null()` — use `=== null`
- `create_function()` — use closures
- Underscore-prefixed private methods/properties (`_method`) — PSR-2 violation
- Lines > 125 chars (warning) / > 150 chars (error)
- Long array syntax `array()` — use `[]`

### Step 3: Run quality checks

After implementing, run the quality pipeline:

```bash
# Quick check (pre-commit level)
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && php vendor/bin/phpcs --standard=phpcs.xml {changed-files}"

# Full check
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && composer check"

# Individual tools
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && php vendor/bin/phpstan analyse {changed-files}"
docker exec nextcloud bash -c "cd /var/www/html/custom_apps/{app} && php vendor/bin/psalm {changed-files}"
```

Fix any violations before marking the task complete.

### Step 4: Verify & update progress

1. Verify acceptance criteria are met
2. Run `docker exec nextcloud apache2ctl graceful` to clear OPcache
3. Update plan.json: set task status to `completed`
4. Update tasks.md: check off completed checkboxes
5. Close the GitHub issue:
   ```bash
   gh issue close <number> --repo <repo> --comment "Completed: <summary>"
   ```

### Dutch Government API & Security Standards

Read the full standards reference at [references/dutch-gov-backend-standards.md](references/dutch-gov-backend-standards.md). It covers:
- **NLGov REST API Design Rules 2.0** — resource URLs, pagination format, error responses, filtering
- **ZGW API Compatibility** — Zaken, Documenten, Catalogi, Besluiten APIs
- **Haal Centraal Integration** — BRP, BAG, BRK, HR base registries
- **FSC (Federatieve Service Connectiviteit)** — mutual TLS, contracts, directory
- **StUF → API Migration** — StUF is discontinued, use REST APIs only
- **BIO2 Security Controls** — input validation, auth, RBAC, logging, PII
- **AVG/GDPR Compliance** — data minimization, purpose binding, right to erasure

### Coding Standards Quick Reference

| Rule | Value |
|------|-------|
| PHP version | 8.1+ |
| Style | PSR-12 + PEAR base |
| Line length | 125 soft / 150 hard |
| Indentation | 4 spaces |
| Named arguments | MANDATORY (custom sniff) |
| Properties | `private readonly` promoted |
| Array syntax | Short `[]` only |
| Type hints | ALL method signatures |
| Return types | ALL methods |
| PHPDoc | All public methods |
| PHPStan level | 5 |
| Psalm errorLevel | 4 |
| Forbidden | `var_dump`, `die`, `error_log`, `print`, `sizeof`, `is_null` |

---

## Capture Learnings

After execution, review what happened and append new observations to [learnings.md](learnings.md) under the appropriate section:

- **Patterns That Work** — approaches that produced good results
- **Mistakes to Avoid** — errors encountered and how they were resolved
- **Domain Knowledge** — facts discovered during this run
- **Open Questions** — unresolved items for future investigation

Each entry must include today's date. One insight per bullet. Skip if nothing new was learned.
