# Nextcloud ExApps

This directory contains Nextcloud External Applications (ExApps) that run as sidecar containers alongside Nextcloud.

## Available ExApps

| ExApp | Description | Port |
|-------|-------------|------|
| **n8n** | Workflow automation with 400+ integrations | 5678 |
| **ollama** | Local LLM inference (Llama, Mistral, etc.) | 11434 |
| **open-webui** | Chat interface for Ollama/OpenAI APIs | 8080 |

## Quick Start (Docker Compose)

The ExApps are included in the default OpenRegister docker-compose setup:

```bash
# Start Nextcloud with all services including ExApps
cd /path/to/openregister
docker-compose up -d

# Wait for containers to start, then run setup script
./scripts/setup-exapps.sh
```

This will:
1. Start HaRP (the AppAPI Deploy Daemon)
2. Build and start all ExApp containers (n8n, Ollama, Open WebUI)
3. Run the setup script to register ExApps with Nextcloud's AppAPI

> **Note:** The standalone versions of n8n and Open WebUI are available via `--profile standalone` if you prefer direct access without Nextcloud integration.

### Access Points

After setup, access the ExApps via Nextcloud:
- **n8n**: http://localhost:8080/index.php/apps/app_api/proxy/n8n
- **Ollama**: http://localhost:8080/index.php/apps/app_api/proxy/ollama/api/tags
- **Open WebUI**: http://localhost:8080/index.php/apps/app_api/proxy/open_webui

Or use the **External Apps** section in Nextcloud Admin Settings.

## Prerequisites

1. **Nextcloud 30+** with AppAPI enabled
2. **Docker** on the Nextcloud server
3. **HaRP** or **Docker Socket Proxy** configured as Deploy Daemon

### Setting up HaRP (Recommended)

```bash
# On Nextcloud server, register HaRP daemon
docker exec -u www-data nextcloud php occ app_api:daemon:register \
    harp_daemon \
    "HaRP Daemon" \
    docker-install \
    https \
    harp:8780 \
    https://your-nextcloud-url \
    --net nextcloud \
    --haproxy-password "your-secure-password"
```

## Building ExApps

### Build all ExApps

```bash
make all
```

### Build individual ExApps

```bash
make n8n
make ollama
make open-webui
```

### Push to registry

```bash
# Set your registry
export REGISTRY=ghcr.io/your-org

make push
```

## Installing ExApps

### Via Nextcloud Admin UI

1. Go to **Admin Settings** → **External Apps**
2. Click **Install from URL**
3. Enter the info.xml URL for the ExApp

### Via OCC command

```bash
# Register the ExApp
docker exec -u www-data nextcloud php occ app_api:app:register \
    n8n \
    harp_daemon \
    --info-xml https://raw.githubusercontent.com/ConductionNL/openregister/main/exapps/n8n/appinfo/info.xml \
    --force-scopes

# Or for local development
docker exec -u www-data nextcloud php occ app_api:app:register \
    n8n \
    docker_socket_daemon \
    --info-xml /var/www/html/custom_apps/openregister/exapps/n8n/appinfo/info.xml \
    --force-scopes
```

## Configuration

### n8n

| Variable | Description | Default |
|----------|-------------|---------|
| `N8N_EXTERNAL_DATABASE` | PostgreSQL connection string | SQLite |
| `N8N_ENCRYPTION_KEY` | Encryption key for credentials | Auto-generated |
| `N8N_TIMEZONE` | Timezone for schedules | Europe/Amsterdam |

### Ollama

| Variable | Description | Default |
|----------|-------------|---------|
| `OLLAMA_DEFAULT_MODEL` | Model to pull on startup | llama3.2:3b |
| `OLLAMA_NUM_PARALLEL` | Parallel inference requests | 2 |
| `OLLAMA_KEEP_ALIVE` | Model unload timeout | 15m |
| `OLLAMA_MAX_LOADED_MODELS` | Max models in memory | 1 |

### Open WebUI

| Variable | Description | Default |
|----------|-------------|---------|
| `OLLAMA_BASE_URL` | Ollama API URL | Auto-detect |
| `OPENAI_API_BASE_URL` | OpenAI-compatible API | Not set |
| `OPENAI_API_KEY` | API key for OpenAI endpoint | Not set |
| `ENABLE_SIGNUP` | Allow user registration | false |

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    Nextcloud Server                          │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐    │
│  │ AppAPI   │  │ n8n      │  │ Ollama   │  │ Open     │    │
│  │ Core     │──│ ExApp    │  │ ExApp    │──│ WebUI    │    │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘    │
│       │              │              │              │         │
│       └──────────────┼──────────────┼──────────────┘         │
│                      │              │                        │
│              ┌───────┴───────┬──────┴──────┐                │
│              ▼               ▼              ▼                │
│         ┌────────┐     ┌────────┐    ┌──────────┐           │
│         │  n8n   │     │ Ollama │    │ Open     │           │
│         │Container│    │Container│   │ WebUI    │           │
│         └────────┘     └────────┘    │Container │           │
│                             │         └──────────┘           │
│                             │              │                 │
│                             └──────────────┘                 │
│                           (Ollama API)                       │
└─────────────────────────────────────────────────────────────┘
```

## Recommended Installation Order

1. **Ollama** - Provides local LLM capabilities
2. **Open WebUI** - Chat interface (auto-connects to Ollama)
3. **n8n** - Workflow automation

## GPU Support

For GPU acceleration with Ollama:

```bash
# Register daemon with GPU support
docker exec -u www-data nextcloud php occ app_api:daemon:register \
    gpu_daemon \
    "GPU Daemon" \
    docker-install \
    https \
    harp:8780 \
    https://your-nextcloud-url \
    --gpu nvidia
```

## Development

### Local testing

```bash
# Build and run locally
cd exapps/ollama
docker build -t ollama-exapp:dev .
docker run -it --rm \
    -e APP_ID=ollama \
    -e APP_SECRET=test \
    -e NEXTCLOUD_URL=http://localhost:8080 \
    -p 9000:9000 \
    -p 11434:11434 \
    ollama-exapp:dev
```

### Testing endpoints

```bash
# Health check
curl http://localhost:9000/heartbeat

# Initialize
curl -X POST http://localhost:9000/init

# Check Ollama models (via proxy)
curl http://localhost:9000/api/tags
```

## Troubleshooting

### ExApp not starting

1. Check container logs: `docker logs <container_name>`
2. Verify AppAPI is enabled: `occ app:list | grep app_api`
3. Check daemon registration: `occ app_api:daemon:list`

### Models not loading (Ollama)

1. Check available memory: `docker stats`
2. Reduce model size: use `llama3.2:1b` instead of larger variants
3. Check GPU availability: `nvidia-smi` (if using GPU)

### Connection issues

1. Verify network: containers must be on same Docker network
2. Check proxy routes in info.xml
3. Enable AppAPI debug logging

## License

AGPL-3.0 - See LICENSE file for details.
