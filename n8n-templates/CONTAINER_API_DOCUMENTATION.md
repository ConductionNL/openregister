# OpenRegister Container API Server

A generic, extensible API server for executing commands in the Nextcloud container. Designed with PHPQA as the primary use case, but easily extensible for any containerized command.

## Features

- ✅ **Generic & Extensible**: Easy to add new commands
- ✅ **12 Pre-configured Commands**: PHPQA, testing, building, dependencies
- ✅ **Post-Processing**: Automatic parsing of command outputs (PHPQA JSON, test results, etc.)
- ✅ **Error Handling**: Timeouts, error responses, detailed logging
- ✅ **No Container Changes**: Runs on the host, calls Docker
- ✅ **RESTful API**: Simple HTTP POST endpoints

## Architecture

```
┌──────────────────┐
│  n8n / Client    │
└────────┬─────────┘
         │ HTTP POST /<command>
         ↓
┌──────────────────┐
│  Container API   │  ← You are here
│  (port 9090)     │
└────────┬─────────┘
         │ docker exec
         ↓
┌──────────────────┐
│  Nextcloud       │
│  Container       │
└──────────────────┘
```

## Quick Start

### Start the Server

```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts

# Start in background
nohup python3 container-api.py > /tmp/container-api.log 2>&1 &
echo $! > /tmp/container-api.pid

# Check status
curl http://localhost:9090/
```

### Test Commands

```bash
# Run PHPQA
curl -X POST http://localhost:9090/phpqa | jq .

# Auto-fix code style
curl -X POST http://localhost:9090/cs-fix | jq .

# Run unit tests
curl -X POST http://localhost:9090/test-unit | jq .

# Build JavaScript
curl -X POST http://localhost:9090/build-js | jq .
```

## Available Commands

### Code Quality (Primary Use Case)

| Endpoint | Command | Timeout | Description |
|----------|---------|---------|-------------|
| `POST /phpqa` | `composer phpqa` | 5 min | Full PHPQA suite (PHPCS, PHPMD, PHPStan, Psalm, PHPMetrics) |
| `POST /cs-fix` | `composer cs:fix` | 2 min | Auto-fix code style with PHP CS Fixer |
| `POST /cs-check` | `composer cs:check` | 1 min | Check code style (dry-run, no fixes) |
| `POST /phpstan` | `composer phpstan` | 2 min | Run PHPStan static analysis |
| `POST /psalm` | `composer psalm` | 2 min | Run Psalm static analysis |

### Testing

| Endpoint | Command | Timeout | Description |
|----------|---------|---------|-------------|
| `POST /test-unit` | `composer test:unit` | 3 min | Run PHPUnit unit tests |
| `POST /test-integration` | `composer test:integration` | 5 min | Run integration tests |

### Dependencies

| Endpoint | Command | Timeout | Description |
|----------|---------|---------|-------------|
| `POST /composer-install` | `composer install` | 3 min | Install PHP dependencies |
| `POST /composer-update` | `composer update` | 5 min | Update PHP dependencies |
| `POST /npm-install` | `npm install` | 3 min | Install Node.js dependencies |

### Build

| Endpoint | Command | Timeout | Description |
|----------|---------|---------|-------------|
| `POST /build-js` | `npm run build` | 2 min | Build JavaScript/Vue assets |
| `POST /watch-js` | `npm run watch` | 1 hour | Watch and rebuild on changes |

## Response Format

### Successful Response

```json
{
  "timestamp": "2025-12-29T20:30:00.000Z",
  "command": "phpqa",
  "full_command": "composer phpqa",
  "status": "success",
  "exit_code": 0,
  "output": "... command output ...",
  "container": "master-nextcloud-1",
  
  // Command-specific fields (via post-processors):
  "phpqa_report": { ... },
  "report_files": { ... }
}
```

### Error Response

```json
{
  "error": "Request timeout after 300 seconds",
  "command": "phpqa"
}
```

## Post-Processors

Post-processors automatically extract useful information from command output:

### PHPQA Post-Processor
- Extracts JSON report from `phpqa/phpqa.json`
- Adds `phpqa_report` and `report_files` to response

### CS Fix Post-Processor
- Counts files fixed
- Adds `files_fixed` and human-readable `message`

### Test Post-Processor
- Extracts test counts, assertions, failures
- Adds `tests_run`, `assertions`, `failures`, `success` fields

## Adding New Commands

To add a new command, edit `container-api.py` and add to the `COMMANDS` dictionary:

```python
COMMANDS['your-command'] = CommandConfig(
    name='your-command',
    command='composer your:command',  # Or any shell command
    timeout=120,
    description='Description for API docs',
    post_processor=your_post_processor_function  # Optional
)
```

### Example: Add PHPUnit Coverage

```python
def process_coverage_result(result: subprocess.CompletedProcess) -> Dict[str, Any]:
    """Extract coverage percentage from PHPUnit output."""
    match = re.search(r'Lines:\s+(\d+\.\d+)%', result.stdout)
    coverage = float(match.group(1)) if match else 0.0
    return {"coverage_percentage": coverage}

COMMANDS['test-coverage'] = CommandConfig(
    name='test-coverage',
    command='composer test:coverage',
    timeout=300,
    description='Run tests with code coverage',
    post_processor=process_coverage_result
)
```

That's it! The command is now available at `POST /test-coverage`.

## Configuration

Edit these constants in `container-api.py`:

```python
PORT = 9090                                    # API server port
CONTAINER_NAME = 'master-nextcloud-1'          # Target container
APP_PATH = '/var/www/html/apps-extra/openregister'  # App directory in container
```

## Management

### Start Server
```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
nohup python3 container-api.py > /tmp/container-api.log 2>&1 &
echo $! > /tmp/container-api.pid
```

### Stop Server
```bash
kill $(cat /tmp/container-api.pid)
# Or
pkill -f container-api.py
```

### View Logs
```bash
tail -f /tmp/container-api.log
```

### Check Status
```bash
curl http://localhost:9090/
```

## Using with n8n

Your existing n8n workflows work with the new API! The endpoints are backward compatible:
- `/phpqa` still works
- `/cs-fix` still works

For new workflows, you can use any of the 12 available endpoints.

### Example n8n HTTP Request Node

```json
{
  "method": "POST",
  "url": "http://host.docker.internal:9090/phpqa",
  "options": {
    "timeout": 360000
  }
}
```

## Troubleshooting

### Command Not Found
**Error:** `Unknown command: xyz`

**Solution:** The command isn't registered. Check available commands:
```bash
curl http://localhost:9090/ | jq '.endpoints'
```

### Timeout
**Error:** `Request timeout after X seconds`

**Solution:** Increase the timeout in the command config:
```python
COMMANDS['your-command'].timeout = 600  # 10 minutes
```

### Container Not Found
**Error:** `No such container: master-nextcloud-1`

**Solution:** Update `CONTAINER_NAME` in `container-api.py`:
```python
CONTAINER_NAME = 'your-container-name'
```

Find your container:
```bash
docker ps --format '{{.Names}}' | grep nextcloud
```

### Permission Denied
**Error:** `docker: permission denied`

**Solution:** Add your user to the docker group:
```bash
sudo usermod -aG docker $USER
newgrp docker
```

## Security Considerations

- **Localhost Only**: Server binds to `localhost`, not accessible from outside
- **No Authentication**: Add authentication if exposing beyond localhost
- **Command Whitelist**: Only pre-defined commands can be executed
- **Timeouts**: All commands have maximum execution time
- **No Arbitrary Commands**: Users can't execute arbitrary shell commands

## Performance

| Command | Typical Duration | Max Timeout |
|---------|-----------------|-------------|
| PHPQA | 30-60s | 5 min |
| CS Fix | 10-30s | 2 min |
| Unit Tests | 5-30s | 3 min |
| Build JS | 10-20s | 2 min |

## Migration from Old API

The new API is backward compatible. No changes needed to existing n8n workflows!

### What Changed
- ✅ More commands available (12 vs 2)
- ✅ Better code organization
- ✅ Extensible architecture
- ✅ Same endpoints work (`/phpqa`, `/cs-fix`)

### Old Server
```bash
pkill -f phpqa-api.py  # Stop old server
```

### New Server
```bash
python3 container-api.py  # Start new server
```

## Examples

### PHPQA Analysis
```bash
curl -X POST http://localhost:9090/phpqa | jq '{
  command,
  status,
  exit_code,
  errors: .phpqa_report.totals.errors,
  warnings: .phpqa_report.totals.warnings
}'
```

### Fix Code Style
```bash
curl -X POST http://localhost:9090/cs-fix | jq '{
  command,
  files_fixed,
  message
}'
```

### Run Tests
```bash
curl -X POST http://localhost:9090/test-unit | jq '{
  command,
  tests_run,
  assertions,
  failures,
  success
}'
```

### Build Assets
```bash
curl -X POST http://localhost:9090/build-js | jq '{
  command,
  status,
  exit_code
}'
```

## Related Documentation

- [API Server Solution](./API_SERVER_SOLUTION.md) - Why this approach is best
- [n8n Workflow Templates](./README.md) - Using with n8n
- [Implementation Summary](./IMPLEMENTATION_SUMMARY.md) - Development history

## File Location

`/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts/container-api.py`

