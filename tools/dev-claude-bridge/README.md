# Claude CLI bridge — local-dev chat backend

Tiny HTTP wrapper that lets the OpenRegister chat orchestrator route through
the `claude` CLI on your WSL/Linux host as a chat provider, instead of the
local Ollama instance.

**Local development only.** Uses your personal Claude Code OAuth subscription
quota. Not safe to expose beyond `localhost` and not appropriate for
production / multi-tenant deployments.

## Why

`qwen3.5-optimized` on Ollama takes 30–60s per chat turn on typical dev
hardware. Claude Sonnet via this bridge is ~2-3s per turn after warmup. Lets
you iterate on the chat UI / SSE plumbing / orchestrator without waiting for
the local model on every keystroke.

## How it works

- Spawns ONE long-lived `claude -p --input-format stream-json
  --output-format stream-json` subprocess at startup.
- The expensive CLI bootstrap (MCP + plugin discovery + OAuth handshake,
  ~10s) is paid once.
- Each `POST /api/chat` writes a single NDJSON user turn into the
  subprocess's stdin and reads the `result` NDJSON event from stdout.
- Exposes the same response shape as Ollama's `/api/chat` so the OR
  `ClaudeCliChat` provider can talk to it like an Ollama endpoint.

## Limitations (read before relying on it)

- **One Claude session is shared by all incoming requests.** All OR
  conversations leak context into each other. Fine for one operator driving
  one OR conversation at a time; not appropriate for multi-user testing.
  POST `/reset` to wipe the session.
- **No tool calling.** Claude's tool-use protocol differs from Ollama's; the
  bridge passes plain text only. The orchestrator's MCP tool fan-out (e.g.
  `openbuilt.createApp`) won't fire when this provider is selected.
- **Subscription quota.** Each turn consumes Claude Max usage credits.
  Don't leave this wired up overnight.
- **`claude` binary is on the WSL host.** The Nextcloud container reaches
  the bridge via `host.docker.internal:11500`. If you run the container in
  a different network mode this URL will need adjustment.

## Setup

Prerequisites: Node 18+ on the host (`node --version`), Claude CLI logged in
(`claude /login`).

```bash
cd openregister/tools/dev-claude-bridge
# No npm install needed — uses node:http + node:child_process only.
node server.js
# claude-bridge listening on :11500 (bin=claude, model=sonnet)
```

Detach for daily use (survives terminal close, restartable):

```bash
setsid -f node server.js > /tmp/claude-bridge.log 2>&1
```

Or a tiny systemd user unit at `~/.config/systemd/user/claude-bridge.service`:

```ini
[Unit]
Description=Claude CLI bridge for OpenRegister dev
After=network.target

[Service]
Type=simple
ExecStart=/usr/bin/env node /home/YOU/path/to/openregister/tools/dev-claude-bridge/server.js
Restart=on-failure
Environment=PATH=/usr/local/bin:/usr/bin:/home/YOU/.local/bin

[Install]
WantedBy=default.target
```

`systemctl --user daemon-reload && systemctl --user enable --now claude-bridge`.

## Wire it into OpenRegister

Update the `llm` appconfig key (or use the admin settings page once a UI
toggle exists):

```bash
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c \
  "UPDATE oc_appconfig SET configvalue=jsonb_set(
     configvalue::jsonb,
     '{chatProvider}',
     '\"claude\"'
   )::text || ',\"claudeConfig\":{\"url\":\"http://host.docker.internal:11500\",\"chatModel\":\"sonnet\"}}'
   WHERE appid='openregister' AND configkey='llm';"
```

Or set via the OR admin UI by hand: set `chatProvider=claude` and add a
`claudeConfig` block with `url` + `chatModel` (one of `sonnet`, `opus`,
`haiku`).

Reload Apache to clear OPcache:

```bash
docker exec nextcloud apache2ctl graceful
```

## Quick smoke test

```bash
# From host:
curl -s http://localhost:11500/health
curl -s -X POST http://localhost:11500/api/chat \
     -H "Content-Type: application/json" \
     -d '{"messages":[{"role":"user","content":"Reply just: PONG"}]}'

# From container:
docker exec nextcloud curl -s http://host.docker.internal:11500/health
```

Expected wall-time: first turn 1-3s, subsequent turns 1-3s. If you see
~10-15s the persistent subprocess died and respawned (check the bridge log).

## Switching back to Ollama

```bash
docker exec openregister-postgres psql -U nextcloud -d nextcloud -c \
  "UPDATE oc_appconfig SET configvalue=jsonb_set(
     configvalue::jsonb, '{chatProvider}', '\"ollama\"'
   )::text WHERE appid='openregister' AND configkey='llm';"
docker exec nextcloud apache2ctl graceful
```

## Troubleshooting

- **`cURL error 52: Empty reply from server`** — bridge process died. Check
  `/tmp/claude-bridge.log` or wherever you redirected stdout/stderr.
- **First request takes 10-15s** — claude subprocess hadn't finished
  bootstrapping yet. Wait ~12s after starting the bridge before the first
  request, or accept the cold-start.
- **Tools don't fire** — expected; this bridge is text-only. Switch back to
  Ollama for MCP tool-call testing.
- **`apiKeySource=none` in logs** — correct; the bridge uses your Claude
  Code OAuth credentials from `~/.claude/.credentials.json`, no API key.
