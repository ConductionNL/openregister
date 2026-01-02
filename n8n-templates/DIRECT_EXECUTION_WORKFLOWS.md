# Direct Execution Workflows for OpenRegister

This document describes the n8n workflows that execute commands directly in the Nextcloud container using Docker exec, eliminating the need for an intermediate API server.

## Overview

These workflows use n8n's **Code node** with Node.js `child_process.execSync()` to run `docker exec` commands directly against the `master-nextcloud-1` container. This is possible because the n8n container has access to the Docker socket.

## Architecture

```
n8n Container → Docker Socket → Nextcloud Container → composer commands
```

**Advantages:**
- No intermediate API server required.
- Direct execution with real-time output.
- Simpler setup and maintenance.
- Full control over command execution.

**Requirements:**
- n8n container must have Docker socket access (already configured in your setup).
- Target container name must match (`master-nextcloud-1`).

## Available Workflows

### 1. OpenRegister PHPQA Direct Execution
**File:** `openregister-phpqa-direct.json`

**Purpose:** Runs `composer phpqa` in the Nextcloud container and returns the results as JSON.

**Webhook:** `POST /webhook/openregister-phpqa`

**Response Format:**
```json
{
  "timestamp": "2025-12-28T17:30:00.000Z",
  "status": "success",
  "command": "composer phpqa",
  "container": "master-nextcloud-1",
  "output": "... raw command output ...",
  "phpqa_data": {
    "files": { ... },
    "totals": { ... }
  },
  "reports": {
    "html": "/var/www/html/apps-extra/openregister/phpqa/phpqa-offline.html",
    "json": "/var/www/html/apps-extra/openregister/phpqa/phpqa.json"
  }
}
```

### 2. OpenRegister CS Fix Direct Execution
**File:** `openregister-cs-fix-direct.json`

**Purpose:** Runs `composer cs:fix` to automatically fix code style issues.

**Webhook:** `POST /webhook/openregister-cs-fix`

**Response Format:**
```json
{
  "timestamp": "2025-12-28T17:30:00.000Z",
  "status": "success",
  "command": "composer cs:fix",
  "container": "master-nextcloud-1",
  "files_fixed": 5,
  "output": "... raw command output ...",
  "message": "5 issues fixed"
}
```

### 3. OpenRegister PHPQA + Auto-fix
**File:** `openregister-phpqa-autofix-direct.json`

**Purpose:** Complete code quality workflow that:
1. Runs initial PHPQA analysis.
2. Auto-fixes issues with CS Fix.
3. Runs final PHPQA analysis.
4. Provides before/after comparison.

**Webhook:** `POST /webhook/openregister-quality-check`

**Response Format:**
```json
{
  "timestamp": "2025-12-28T17:30:00.000Z",
  "step": "initial_phpqa",
  "status": "success",
  "output": "...",
  "phpqa_data": { ... },
  "cs_fix": {
    "timestamp": "2025-12-28T17:31:00.000Z",
    "step": "cs_fix",
    "status": "success",
    "files_fixed": 5,
    "output": "..."
  },
  "final_phpqa": {
    "timestamp": "2025-12-28T17:32:00.000Z",
    "step": "final_phpqa",
    "status": "success",
    "output": "...",
    "phpqa_data": { ... }
  },
  "summary": {
    "container": "master-nextcloud-1",
    "workflow": "phpqa_autofix",
    "files_fixed": 5,
    "initial_issues": 23,
    "final_issues": 18,
    "improvement": 5
  }
}
```

## Installation

### Option 1: Import via n8n UI
1. Open n8n (http://localhost:5678).
2. Click the **menu** (three dots) in the top right.
3. Select **Import from File**.
4. Choose one of the workflow JSON files from this directory.
5. Click **Import**.
6. Click **Save** to save the workflow.
7. Click **Active** to activate the webhook.

### Option 2: Import via API
```bash
API_KEY="your-n8n-api-key"
curl -X POST \
  -H "X-N8N-API-KEY: $API_KEY" \
  -H "Content-Type: application/json" \
  --data @openregister-phpqa-direct.json \
  'http://localhost:5678/api/v1/workflows'
```

Then activate the workflow:
```bash
WORKFLOW_ID="workflow-id-from-above"
curl -X POST \
  -H "X-N8N-API-KEY: $API_KEY" \
  "http://localhost:5678/api/v1/workflows/$WORKFLOW_ID/activate"
```

## Usage Examples

### Running PHPQA
```bash
curl -X POST http://localhost:5678/webhook/openregister-phpqa
```

### Auto-fixing Code Style
```bash
curl -X POST http://localhost:5678/webhook/openregister-cs-fix
```

### Full Quality Check (PHPQA + Auto-fix + Re-analysis)
```bash
curl -X POST http://localhost:5678/webhook/openregister-quality-check
```

## Customization

### Changing the Container Name
If your Nextcloud container has a different name, edit the `jsCode` parameter in each Code node and replace `master-nextcloud-1` with your container name.

### Changing the App Directory
Replace `/var/www/html/apps-extra/openregister` with your app's path.

### Adding More Commands
To add new commands, duplicate an existing workflow and modify the `docker exec` command in the Code node.

Example for running PHPUnit tests:
```javascript
const { execSync } = require('child_process');

try {
  const output = execSync(
    'docker exec master-nextcloud-1 bash -c \"cd /var/www/html/apps-extra/openregister && composer test:unit 2>&1\"',
    { 
      encoding: 'utf8',
      maxBuffer: 10 * 1024 * 1024,
      timeout: 120000
    }
  );

  return {
    timestamp: new Date().toISOString(),
    status: 'success',
    command: 'composer test:unit',
    container: 'master-nextcloud-1',
    output: output
  };
} catch (error) {
  return {
    timestamp: new Date().toISOString(),
    status: 'error',
    command: 'composer test:unit',
    container: 'master-nextcloud-1',
    error: error.message,
    stdout: error.stdout ? error.stdout.toString() : '',
    stderr: error.stderr ? error.stderr.toString() : ''
  };
}
```

## Troubleshooting

### Error: "docker: command not found"
The n8n container doesn't have Docker socket access. Check your docker-compose.yml:
```yaml
volumes:
  - /var/run/docker.sock:/var/run/docker.sock
```

### Error: "No such container: master-nextcloud-1"
The container name is incorrect. List running containers:
```bash
docker ps --format '{{.Names}}'
```

### Timeout Errors
Increase the timeout in the Code node:
```javascript
timeout: 300000  // 5 minutes.
```

### Buffer Errors (maxBuffer)
Increase the buffer size for commands with large output:
```javascript
maxBuffer: 50 * 1024 * 1024  // 50MB.
```

## Comparison with API Server Approach

| Feature | Direct Execution | API Server |
|---------|-----------------|------------|
| Setup Complexity | Simple | Moderate |
| Dependencies | None (built-in) | Python server |
| Maintenance | Low | Medium |
| Performance | Fast | Slightly slower |
| Debugging | Direct logs | Need to check server logs |
| Security | Container-level | Additional network layer |

## Security Considerations

- **Docker Socket Access:** The n8n container has full Docker access. Ensure n8n is properly secured.
- **Webhook Authentication:** Consider adding authentication to webhooks in production.
- **Command Injection:** The workflows use hardcoded commands. Be careful if adding dynamic command construction.
- **Container Permissions:** Commands run as the user inside the Nextcloud container (usually www-data/33).

## Performance

- **PHPQA Analysis:** ~30-60 seconds (depends on codebase size).
- **CS Fix:** ~10-30 seconds.
- **Full Auto-fix Workflow:** ~60-120 seconds.

## Migration from API Server

If you were previously using the Python API server approach:

1. Import the new direct execution workflows.
2. Test them to ensure they work.
3. Update any external integrations to use the new webhook URLs.
4. Stop the Python API server:
   ```bash
   pkill -f phpqa-api.py
   ```
5. Remove the API server script (optional):
   ```bash
   rm /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts/phpqa-api.py
   ```

## Related Documentation

- [Amsterdam Weather Webhook](./AMSTERDAM_WEATHER_WEBHOOK_REPORT.md) - Simple webhook example.
- [Code Quality Workflows Complete](./CODE_QUALITY_WORKFLOWS_COMPLETE.md) - Original API server approach (deprecated).
- [n8n Code Node Documentation](https://docs.n8n.io/integrations/builtin/core-nodes/n8n-nodes-base.code/) - Official n8n docs.
- [Docker Exec Reference](https://docs.docker.com/engine/reference/commandline/exec/) - Docker command documentation.



