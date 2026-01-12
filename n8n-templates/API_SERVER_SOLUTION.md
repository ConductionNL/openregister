# Solution: API Server Approach (No Container Changes)

## Problem
n8n's Code node is sandboxed and doesn't allow `child_process.execSync()` for security reasons. This means we can't run `docker exec` commands directly from n8n without modifying the container.

## Solution: API Server (Recommended)

Use a lightweight Python API server on the host that n8n calls via HTTP Request nodes. This approach:
- ✅ Requires **no changes to n8n container**
- ✅ Already implemented and working
- ✅ Simple to maintain
- ✅ Secure (runs on localhost only)

## Architecture

```
┌─────────────────┐
│  n8n Container  │
│  (unchanged)    │
└────────┬────────┘
         │ HTTP Request (localhost:9090)
         ↓
┌─────────────────┐
│  Host Machine   │
│  Python API     │
│  (phpqa-api.py) │
└────────┬────────┘
         │ docker exec
         ↓
┌─────────────────┐
│  Nextcloud      │
│  Container      │
└─────────────────┘
```

## Setup Instructions

### 1. Start the API Server

```bash
# Navigate to scripts directory
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts

# Start the API server in background
nohup python3 phpqa-api.py > /tmp/phpqa-api.log 2>&1 &
echo $! > /tmp/phpqa-api.pid

# Verify it's running
curl http://localhost:9090/
```

### 2. The API Server is Already Running

Status: ✅ **Running** (PID: 38091)

Endpoints:
- `POST http://localhost:9090/phpqa` - Run composer phpqa
- `POST http://localhost:9090/cs-fix` - Run composer cs:fix

### 3. Active n8n Workflows (Using API Server)

These workflows are **already active and working**:

1. **OpenRegister PHPQA Code Quality Check**
   - Webhook: `POST /webhook/openregister-phpqa-check`
   - Runs: `composer phpqa`

2. **OpenRegister CS Fix - Auto Code Style Fixer**
   - Webhook: `POST /webhook/openregister-cs-fix`
   - Runs: `composer cs:fix`

3. **OpenRegister PHPQA with Auto-Fix**
   - Webhook: `POST /webhook/openregister-phpqa-autofix`
   - Runs: PHPQA → CS Fix → PHPQA (full workflow)

## Usage Examples

### Run PHPQA Analysis
```bash
curl -X POST http://localhost:5678/webhook/openregister-phpqa-check
```

### Auto-fix Code Style
```bash
curl -X POST http://localhost:5678/webhook/openregister-cs-fix
```

### Full Quality Workflow (Analyze → Fix → Re-analyze)
```bash
curl -X POST http://localhost:5678/webhook/openregister-phpqa-autofix
```

## Managing the API Server

### Check Status
```bash
curl http://localhost:9090/
```

### View Logs
```bash
tail -f /tmp/phpqa-api.log
```

### Stop the Server
```bash
kill $(cat /tmp/phpqa-api.pid)
# Or
pkill -f phpqa-api.py
```

### Restart the Server
```bash
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
nohup python3 phpqa-api.py > /tmp/phpqa-api.log 2>&1 &
echo $! > /tmp/phpqa-api.pid
```

## Alternative Solutions (Comparison)

| Solution | Container Changes | Complexity | Status |
|----------|------------------|------------|--------|
| **API Server** | ❌ None | Low | ✅ Working |
| Direct Execution (Code node) | ✅ Required | Low | ❌ Blocked by sandbox |
| SSH Node | ❌ None | Medium | ⚠️ Needs SSH setup |
| Execute Command Community Node | ✅ Required | Medium | ⚠️ Security concerns |

## Why API Server is Best

1. **No Container Changes**: n8n container remains untouched
2. **Already Working**: You have 3 active workflows using it
3. **Simple**: Just a Python HTTP server
4. **Maintainable**: Easy to debug and modify
5. **Secure**: Only accessible from localhost
6. **Flexible**: Easy to add new commands

## Technical Details

### API Server Code
Location: `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts/phpqa-api.py`

The server:
- Runs on port 9090
- Executes `docker exec master-nextcloud-1` commands
- Returns JSON responses
- Has 2 minute timeout for long-running commands
- Includes error handling

### n8n Workflows
The workflows use:
- **Webhook Trigger**: Receives POST requests
- **HTTP Request Node**: Calls `http://host.docker.internal:9090/phpqa` or `/cs-fix`
- **Format/Transform Nodes**: Process the JSON response
- **Response Node**: Returns results to caller

## Troubleshooting

### API Server Not Responding
```bash
# Check if running
ps aux | grep phpqa-api.py

# Check logs
tail -f /tmp/phpqa-api.log

# Restart
pkill -f phpqa-api.py
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
nohup python3 phpqa-api.py > /tmp/phpqa-api.log 2>&1 &
echo $! > /tmp/phpqa-api.pid
```

### n8n Can't Reach API Server
- Make sure you're using `http://host.docker.internal:9090` in n8n
- Check if port 9090 is accessible: `curl http://localhost:9090/`
- Verify n8n container has host network access

### Container Name Changed
If your Nextcloud container has a different name:
1. Edit `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts/phpqa-api.py`
2. Replace `master-nextcloud-1` with your container name
3. Restart the API server

## Automatic Startup (Optional)

To start the API server automatically, add to your `~/.bashrc` or create a systemd service:

### Option 1: bashrc (Simple)
```bash
# Add to ~/.bashrc
if ! pgrep -f "phpqa-api.py" > /dev/null; then
    cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
    nohup python3 phpqa-api.py > /tmp/phpqa-api.log 2>&1 &
    echo $! > /tmp/phpqa-api.pid
fi
```

### Option 2: Systemd Service (Better)
Create `/etc/systemd/system/phpqa-api.service`:
```ini
[Unit]
Description=PHPQA API Server for OpenRegister
After=docker.service

[Service]
Type=simple
User=rubenlinde
WorkingDirectory=/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
ExecStart=/usr/bin/python3 phpqa-api.py
Restart=always

[Install]
WantedBy=multi-user.target
```

Then:
```bash
sudo systemctl daemon-reload
sudo systemctl enable phpqa-api
sudo systemctl start phpqa-api
```

## Conclusion

The **API Server approach is the recommended solution** because:
- It works right now
- Requires no n8n container modifications
- Is simple and maintainable
- You already have 3 working workflows using it

The "Direct Execution" approach (using Code node with child_process) is not possible due to n8n's security sandbox, which is actually a **good security feature** that prevents arbitrary command execution.

## Files

- API Server: `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts/phpqa-api.py`
- Workflow Templates: `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/openregister-phpqa-*.json`
- This Documentation: `/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/n8n-templates/API_SERVER_SOLUTION.md`



