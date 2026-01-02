# Container API - Quick Reference

## Server Control

```bash
# Start
cd /home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts
nohup python3 container-api.py > /tmp/container-api.log 2>&1 &
echo $! > /tmp/container-api.pid

# Stop
kill $(cat /tmp/container-api.pid)

# Status
curl http://localhost:9090/

# Logs
tail -f /tmp/container-api.log
```

## Quick Commands

```bash
# Code Quality (PHPQA - Primary Use Case)
curl -X POST http://localhost:9090/phpqa        # Full analysis
curl -X POST http://localhost:9090/cs-fix       # Auto-fix style
curl -X POST http://localhost:9090/cs-check     # Check only
curl -X POST http://localhost:9090/phpstan      # PHPStan
curl -X POST http://localhost:9090/psalm        # Psalm

# Testing
curl -X POST http://localhost:9090/test-unit
curl -X POST http://localhost:9090/test-integration

# Dependencies
curl -X POST http://localhost:9090/composer-install
curl -X POST http://localhost:9090/composer-update
curl -X POST http://localhost:9090/npm-install

# Build
curl -X POST http://localhost:9090/build-js
curl -X POST http://localhost:9090/watch-js
```

## Add New Command

Edit `container-api.py`:

```python
COMMANDS['my-command'] = CommandConfig(
    name='my-command',
    command='composer my:command',
    timeout=120,
    description='What it does'
)
```

Then restart server. Done!

## n8n Usage

HTTP Request node URL: `http://host.docker.internal:9090/<command>`

## File Location

`/home/rubenlinde/nextcloud-docker-dev/workspace/server/apps-extra/openregister/scripts/container-api.py`



