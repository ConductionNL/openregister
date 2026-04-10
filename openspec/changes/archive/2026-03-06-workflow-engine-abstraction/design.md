# Design: workflow-engine-abstraction

## Architecture Overview

An interface + adapter pattern where OpenRegister defines a `WorkflowEngineInterface` and each supported engine has a dedicated adapter class. A `WorkflowEngineRegistry` service manages registered engines and resolves the correct adapter by engine type.

```
OpenRegister                          External Engines
  |                                       |
  WorkflowEngineRegistry                  |
  |-- resolveAdapter('n8n') --------> N8nAdapter
  |       |                              |-- POST {baseUrl}/rest/workflows
  |       |                              |-- POST {webhookUrl} (execute)
  |       |                              |-- via ExApp proxy (optional)
  |                                       |
  |-- resolveAdapter('windmill') --> WindmillAdapter
          |                              |-- POST {baseUrl}/api/w/{ws}/flows/create
          |                              |-- POST {baseUrl}/api/w/{ws}/jobs/run_wait_result/f/{path}
```

## API Design

### Engine CRUD -- `/api/engines/`

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| GET | `/api/engines/` | List registered engines (credentials redacted) | User |
| POST | `/api/engines/` | Register a new engine + run health check | Admin |
| GET | `/api/engines/{id}` | Get engine details (credentials redacted) | User |
| PUT | `/api/engines/{id}` | Update engine configuration | Admin |
| DELETE | `/api/engines/{id}` | Remove engine from registry | Admin |
| POST | `/api/engines/{id}/health` | Run health check on a specific engine | Admin |
| GET | `/api/engines/available` | List auto-discovered engine types from ExApps | Admin |

**POST /api/engines/ -- Request:**
```json
{
  "name": "Production n8n",
  "engineType": "n8n",
  "baseUrl": "http://localhost:5678",
  "authType": "bearer",
  "authConfig": { "token": "n8n-api-key-here" },
  "enabled": true,
  "defaultTimeout": 30
}
```

**POST /api/engines/ -- Response (201):**
```json
{
  "id": 1,
  "name": "Production n8n",
  "engineType": "n8n",
  "baseUrl": "http://localhost:5678",
  "authType": "bearer",
  "enabled": true,
  "defaultTimeout": 30,
  "healthStatus": true,
  "lastHealthCheck": "2026-03-06T10:00:00Z"
}
```

Note: `authConfig` is never returned in GET responses.

**POST /api/engines/{id}/health -- Response (200):**
```json
{
  "healthy": true,
  "responseTime": 45,
  "engineVersion": "1.94.1"
}
```

## Database

### Option A: New table `openregister_workflow_engines` (recommended)

```sql
CREATE TABLE openregister_workflow_engines (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    uuid        VARCHAR(36)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    engine_type VARCHAR(50)  NOT NULL,  -- 'n8n', 'windmill'
    base_url    VARCHAR(512) NOT NULL,
    auth_type   VARCHAR(50)  DEFAULT 'none',
    auth_config TEXT,                    -- encrypted JSON blob
    enabled     TINYINT(1)   DEFAULT 1,
    default_timeout INT      DEFAULT 30,
    health_status   TINYINT(1) DEFAULT NULL,
    last_health_check DATETIME DEFAULT NULL,
    created     DATETIME     NOT NULL,
    updated     DATETIME     NOT NULL
);
```

A dedicated table is preferred over OpenRegister's generic config system because:
- Engine configs have a fixed, known schema (not arbitrary key-value)
- Need efficient lookups by `engine_type`
- Credentials require encryption (easier to manage in a typed mapper)
- Migration is simple: one `CREATE TABLE`, one `DROP TABLE` for rollback

### Entity & Mapper

`WorkflowEngine` extends `OCP\AppFramework\Db\Entity`. `WorkflowEngineMapper` extends `OCP\AppFramework\Db\QBMapper`. Standard Nextcloud ORM pattern.

## Nextcloud Integration

### Dependency Injection (container registration)

All workflow engine classes are auto-wired by Nextcloud's DI container. No manual `registerService()` calls are needed in `Application.php` because:
- `WorkflowEngineRegistry`, `N8nAdapter`, `WindmillAdapter`, `WorkflowEngineMapper`, and `WorkflowEngineController` all have constructor dependencies that Nextcloud can resolve automatically
- `WorkflowEngineInterface` is NOT registered directly — adapters are resolved at runtime via `WorkflowEngineRegistry::resolveAdapter()`

### Credential Encryption

Use `OCP\Security\ICrypto` to encrypt/decrypt `authConfig` on write/read:

```php
// On store
$engine->setAuthConfig($this->crypto->encrypt(json_encode($authConfig)));

// On resolve
$authConfig = json_decode($this->crypto->decrypt($engine->getAuthConfig()), true);
```

### ExApp Auto-Discovery

Use `OCA\AppAPI\Service\AppAPIService` (if available) to list installed ExApps. Check for known app IDs (`n8n`, `windmill`) and extract their configured URLs.

```php
public function discoverEngines(): array {
    if (!$this->appManager->isEnabledForUser('app_api')) {
        return [];
    }
    // Query installed ExApps, filter for known workflow engine app IDs
    // Return array of ['engineType' => 'n8n', 'suggestedBaseUrl' => '...']
}
```

### HTTP Client

Adapters use `OCP\Http\Client\IClientService` for outbound HTTP calls to engines. This respects Nextcloud's proxy settings and SSL configuration.

## File Structure

```
openregister/lib/
  Controller/
    WorkflowEngineController.php    # NEW -- CRUD + health check endpoints
  Db/
    WorkflowEngine.php              # NEW -- Entity
    WorkflowEngineMapper.php        # NEW -- QBMapper
  Service/
    WorkflowEngineRegistry.php      # NEW -- Manages engines, resolves adapters
  WorkflowEngine/
    WorkflowEngineInterface.php     # NEW -- The interface
    WorkflowResult.php              # NEW -- Value object
    N8nAdapter.php                  # NEW -- n8n implementation
    WindmillAdapter.php             # NEW -- Windmill implementation

openregister/lib/Migration/
    VersionXXXXDate_CreateWorkflowEngines.php  # NEW -- DB migration
```

## Security Considerations

- **Credential storage**: All engine credentials are encrypted at rest via `ICrypto`. Never returned in API responses. Never logged.
- **Auth to engines**: Adapters support multiple auth types (none, basic, bearer, cookie). Bearer tokens are recommended for n8n; Windmill uses its own token system.
- **Admin-only write access**: Only admins can create/update/delete engines. Regular users can list engines (without credentials) to see available engine types.
- **ExApp proxy routing**: When n8n runs as an ExApp, the adapter routes through Nextcloud's ExApp proxy, inheriting Nextcloud's authentication and authorization. This avoids exposing n8n directly.
- **Timeout enforcement**: All synchronous calls enforce a configurable timeout (default 30s) to prevent hanging requests.
- **Input validation**: Engine configuration is validated on create/update (valid URL, known engine type, valid auth type).

## Trade-offs

| Alternative | Why not |
|---|---|
| Direct n8n integration (no interface) | Locks OpenRegister to a single engine. Adding Windmill or others would require duplicating integration code everywhere. |
| Event-based only (fire and forget) | Hooks need synchronous responses (approve/reject/modify). Async-only would prevent validation workflows. |
| Store engines in IAppConfig (key-value) | IAppConfig is unstructured. Engine configs have a fixed schema with encrypted credentials -- a proper entity/mapper is cleaner. |
| Generic "webhook" adapter instead of per-engine | Engine APIs differ significantly (deploy, activate, workspace scoping). A generic webhook can't handle deploy/activate/list. |
| Store engine configs in OpenRegister's own object system | Circular dependency risk. Engine configs should be available before any register/schema is loaded. |
