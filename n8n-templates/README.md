# n8n Workflow Templates for OpenRegister

This directory contains ready-to-use n8n workflow templates for integrating OpenRegister with external systems via Nextcloud webhooks.

## Requirements

- Nextcloud 28+ with OpenRegister app installed
- n8n instance (self-hosted or cloud)
- Nextcloud `webhook_listeners` app enabled

## Available Templates

### Example Templates

#### amsterdam-weather-webhook.json
**Description:** A simple example webhook that returns current weather data for Amsterdam in JSON format.

**Use Cases:**
- Learning how to create webhooks in n8n
- Testing n8n workflow execution
- Example of external API integration with data transformation

**Features:**
- Webhook trigger (GET request)
- External API call (wttr.in weather API)
- JavaScript data transformation
- JSON response

**Performance:** Average response time: ~7-8 seconds (depends on external API)

**Test URL:** `http://localhost:5678/webhook/amsterdam-weather`

---

### Code Quality Templates (Direct Execution)

**NEW:** These workflows use n8n's Code node to execute commands directly in the Nextcloud container via `docker exec`, eliminating the need for an intermediate API server.

📚 **Detailed Documentation:** [DIRECT_EXECUTION_WORKFLOWS.md](./DIRECT_EXECUTION_WORKFLOWS.md)

#### openregister-phpqa-direct.json
**Description:** Runs `composer phpqa` directly in the Nextcloud container using Docker exec from n8n.

**Use Cases:**
- Automated code quality checks without API server.
- CI/CD integration.
- Pre-commit quality gates.
- Code review automation.

**Features:**
- Direct container execution via Docker socket.
- No intermediate API server required.
- Real-time command output.
- Comprehensive JSON report.

**Performance:** ~30-60 seconds (depends on codebase size).

**Test URL:** `http://localhost:5678/webhook/openregister-phpqa`

---

#### openregister-cs-fix-direct.json
**Description:** Automatically fixes code style issues using `composer cs:fix` executed directly in the container.

**Use Cases:**
- Automated code style fixing.
- Pre-commit hooks.
- Continuous code cleanup.

**Features:**
- Direct execution without API server.
- Returns count of files fixed.
- Detailed output of all changes.

**Performance:** ~10-30 seconds.

**Test URL:** `http://localhost:5678/webhook/openregister-cs-fix`

---

#### openregister-phpqa-autofix-direct.json
**Description:** Complete 3-step workflow: Analyze → Auto-fix → Re-analyze, all via direct container execution.

**Use Cases:**
- Automated code improvement pipeline.
- CI/CD with auto-fixing.
- Before/after quality comparison.

**Features:**
- 3-step workflow with direct execution.
- Comprehensive before/after comparison.
- Shows improvement metrics.

**Performance:** ~60-120 seconds (2 full analyses + fixing).

**Test URL:** `http://localhost:5678/webhook/openregister-quality-check`

**Workflow Steps:**
1. Initial PHPQA analysis (~30-60s).
2. Run CS Fix to auto-fix issues (~10-30s).
3. Final PHPQA analysis to verify (~30-60s).
4. Format combined report with comparison.

---

### Code Quality Templates (API Server - Legacy)

**DEPRECATED:** The following workflows require a Python API server. Use the **Direct Execution** workflows above instead.

---

#### openregister-phpqa-workflow.json
**Description:** Runs composer phpqa in the OpenRegister app to perform automated code quality analysis and returns results as JSON.

**Use Cases:**
- Automated code quality checks
- CI/CD integration
- Pre-commit quality gates
- Code review automation
- Quality metrics tracking

**Features:**
- Webhook trigger (POST request)
- Runs PHPCS, PHPMD, PHPStan, Psalm, PHPMetrics, PDepend
- Returns comprehensive JSON report
- Includes command output and analysis results

**Requirements:**
- PHPQA API server running on host (see Setup section below)

**Performance:** Response time: ~60 seconds (full code analysis suite)

**Test URL:** `http://localhost:5678/webhook/openregister-phpqa`

---

#### openregister-cs-fix-workflow.json
**Description:** Automatically fixes code style issues in the OpenRegister app using `composer cs:fix` (PHP CS Fixer).

**Use Cases:**
- Automated code style fixing
- Pre-commit hooks
- Continuous code cleanup
- PSR-2 compliance automation

**Features:**
- Webhook trigger (POST request)
- Runs PHP CS Fixer in dry-run mode first, then fixes
- Returns count of files fixed
- Detailed output of all changes

**Requirements:**
- PHPQA API server running on host (see Setup section below)

**Performance:** Response time: ~15-20 seconds

**Test URL:** `http://localhost:5678/webhook/openregister-cs-fix`

---

#### openregister-phpqa-autofix-workflow.json
**Description:** Complete code quality workflow that analyzes, fixes, and re-analyzes code automatically.

**Use Cases:**
- Automated code improvement pipeline
- CI/CD with auto-fixing
- Quality gate with automatic remediation
- Before/after quality comparison

**Features:**
- 3-step workflow: Analyze → Fix → Re-analyze
- Comprehensive before/after comparison
- Shows improvement metrics
- Full detailed reports for each step

**Requirements:**
- PHPQA API server running on host (see Setup section below)

**Performance:** Response time: ~120 seconds (2 full analyses + fixing)

**Test URL:** `http://localhost:5678/webhook/openregister-phpqa-autofix`

**Workflow Steps:**
1. Initial PHPQA analysis (60s)
2. Run CS Fix to auto-fix issues (15s)
3. Final PHPQA analysis to verify (60s)
4. Format combined report with comparison

---

### OpenRegister Integration Templates

#### 1. openregister-object-sync.json
**Description:** Sync OpenRegister objects to an external system whenever they are created or updated.

**Use Cases:**
- Keep external databases synchronized with OpenRegister data
- Trigger external processes when objects change
- Archive object data to external storage

**Events:** `ObjectCreatedEvent`, `ObjectUpdatedEvent`

---

#### 2. openregister-to-database.json
**Description:** Write OpenRegister objects directly to an external database with transformation logic.

**Use Cases:**
- Data warehousing
- Analytics platforms
- External reporting systems

**Events:** `ObjectCreatedEvent`, `ObjectUpdatedEvent`

---

#### 3. openregister-bidirectional-sync.json
**Description:** Two-way synchronization between OpenRegister and an external system.

**Use Cases:**
- Sync with CRM systems
- Integration with ERP platforms
- Multi-system data consistency

**Events:** `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`

---

#### 4. openregister-schema-notifications.json
**Description:** Send notifications when schemas are created, updated, or deleted.

**Use Cases:**
- Schema change alerts
- Team collaboration notifications
- Automated documentation updates

**Events:** `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent`

---

## Setup for PHPQA Workflows

The OpenRegister PHPQA workflows require a simple API server running on the host machine to execute docker commands.

### Start the PHPQA API Server

```bash
# Navigate to the scripts directory.
cd /path/to/openregister/scripts

# Start the API server (runs on port 9090).
python3 phpqa-api.py &

# Or use nohup to run in background.
nohup python3 phpqa-api.py > /tmp/phpqa-api.log 2>&1 &
echo $! > /tmp/phpqa-api.pid
```

### API Endpoints

The API server provides the following endpoints:

- **POST /phpqa** - Run full PHPQA analysis (~60 seconds)
- **POST /cs-fix** - Run composer cs:fix to auto-fix code style (~15-20 seconds)

### Test the API Server

```bash
# Check status.
curl http://localhost:9090/

# Run PHPQA (takes ~60 seconds).
curl -X POST http://localhost:9090/phpqa | jq .

# Run CS Fix (takes ~15-20 seconds).
curl -X POST http://localhost:9090/cs-fix | jq .
```

### Stop the API Server

```bash
# If you saved the PID.
kill $(cat /tmp/phpqa-api.pid)

# Or find and kill the process.
pkill -f phpqa-api.py
```

---

## How to Use These Templates

### Step 1: Enable Nextcloud webhook_listeners

```bash
docker exec -u 33 <nextcloud-container> php occ app:enable webhook_listeners
```

### Step 2: Register Webhooks in Nextcloud

Register a webhook for the events you want to listen to. For example, to listen to `ObjectCreatedEvent`:

```bash
curl -X POST http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "admin:admin" \
  -H "Content-Type: application/json" \
  -d '{
    "httpMethod": "POST",
    "uri": "https://<n8n-host>/webhook/<webhook-path>",
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "eventFilter": []
  }'
```

Replace:
- `<nextcloud-host>` with your Nextcloud hostname
- `<n8n-host>` with your n8n hostname
- `<webhook-path>` with the webhook path from the imported workflow

### Step 3: Import Template into n8n

1. Open n8n web interface
2. Click "Add workflow" or use the "+" button
3. Click the three-dot menu (⋮) in the top right
4. Select "Import from File"
5. Choose one of the JSON templates from this directory
6. Click "Import"

### Step 4: Configure Credentials

After importing, configure the following in n8n:

1. **Webhook Node:**
   - Copy the webhook URL from the node
   - Use this URL when registering the webhook in Nextcloud

2. **HTTP Request Nodes:**
   - Add HTTP Basic Auth credentials for OpenRegister API
   - Username: `admin` (or your Nextcloud admin user)
   - Password: Your Nextcloud admin password
   - Base URL: `http://<nextcloud-container>/apps/openregister/api`

3. **Database/External Service Nodes:**
   - Configure credentials for your external systems

### Step 5: Activate Workflow

1. Click "Save" in n8n
2. Toggle the workflow to "Active"
3. Test by creating/updating an object in OpenRegister

---

## Available OpenRegister Events

| Event | Description | Payload Getter |
|-------|-------------|----------------|
| `ObjectCreatedEvent` | When an object is created | `getObject()` |
| `ObjectUpdatedEvent` | When an object is updated | `getNewObject()`, `getOldObject()` |
| `ObjectDeletedEvent` | When an object is deleted | `getObject()` |
| `ObjectLockedEvent` | When an object is locked | `getObject()` |
| `ObjectUnlockedEvent` | When an object is unlocked | `getObject()` |
| `RegisterCreatedEvent` | When a register is created | `getRegister()` |
| `RegisterUpdatedEvent` | When a register is updated | `getNewRegister()`, `getOldRegister()` |
| `RegisterDeletedEvent` | When a register is deleted | `getRegister()` |
| `SchemaCreatedEvent` | When a schema is created | `getSchema()` |
| `SchemaUpdatedEvent` | When a schema is updated | `getNewSchema()`, `getOldSchema()` |
| `SchemaDeletedEvent` | When a schema is deleted | `getSchema()` |
| `ApplicationCreatedEvent` | When an application is created | `getApplication()` |
| `ApplicationUpdatedEvent` | When an application is updated | `getNewApplication()`, `getOldApplication()` |
| `ApplicationDeletedEvent` | When an application is deleted | `getApplication()` |

See the [Events Documentation](../website/docs/Features/events.md) for a complete list.

---

## Webhook Event Payload Structure

When Nextcloud dispatches a webhook, it sends a JSON payload with the following structure:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "register": "my-register",
      "schema": "my-schema",
      "data": {
        "field1": "value1",
        "field2": "value2"
      },
      "created": "2024-01-15T10:30:00+00:00",
      "updated": "2024-01-15T10:30:00+00:00"
    }
  }
}
```

---

## Troubleshooting

### Webhook not triggering

1. Verify `webhook_listeners` app is enabled
2. Check webhook registration with:

```bash
curl -X GET http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "admin:admin"
```

3. Check Nextcloud logs:

```bash
docker logs -f <nextcloud-container>
```

### n8n workflow errors

1. Check n8n execution logs
2. Verify credentials are correct
3. Test webhook URL manually with curl
4. Ensure OpenRegister API is accessible from n8n

---

## Contributing

Have an idea for a new template? Submit a pull request or open an issue on GitHub.

---

## Support

For issues and questions:
- OpenRegister Documentation: [website/docs](../website/docs)
- n8n Documentation: https://docs.n8n.io
- Nextcloud Webhooks: https://docs.nextcloud.com/server/latest/admin_manual/webhook_listeners

