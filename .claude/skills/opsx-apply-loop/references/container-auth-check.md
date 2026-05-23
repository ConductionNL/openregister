# Container Authentication Check

The container needs credentials to call the Claude API. The Claude CLI authenticates via a credentials file, not environment variables alone. Two methods are supported, checked in order of preference:

1. **Credentials file** (preferred) — `~/.claude/.credentials.json`, created automatically by `claude auth login`. Uses your existing Claude subscription (no extra cost).
2. **`ANTHROPIC_API_KEY`** (fallback) — uses prepaid API credits (costs money). Get one at console.anthropic.com.

**Important**: The `apply-loop` container image runs as user `claude` with `HOME=/home/claude`. The credentials file must be mounted to `/home/claude/.claude/.credentials.json` inside the container. Passing `CLAUDE_CODE_AUTH_TOKEN` as an env var alone is NOT sufficient — the CLI requires the actual credentials file.

```bash
CREDS_FILE="$HOME/.claude/.credentials.json"
if [ -f "$CREDS_FILE" ]; then
  echo "✅ Credentials file found at $CREDS_FILE (will mount into container)"
elif [ -n "${ANTHROPIC_API_KEY}" ]; then
  echo "⚠️ ANTHROPIC_API_KEY is set (using paid API credits — consider running 'claude auth login' instead)"
else
  echo "❌ No authentication configured"
fi
```

Store the result: `{AUTH_METHOD}` = `credentials_file` or `api_key`.

**If neither is available — stop immediately:**
> "⛔ The apply-loop container needs authentication. No credentials file (`~/.claude/.credentials.json`) or `ANTHROPIC_API_KEY` found.
>
> **Recommended: use your existing subscription (free)**
> 1. Run `claude auth login` in your terminal
> 2. This creates `~/.claude/.credentials.json` automatically
> 3. Re-run this command — the file will be mounted read-only into the container
>
> **Alternative: use API credits (costs money)**
> 1. Go to console.anthropic.com → API Keys → Create Key
> 2. Add credits to your account (Billing → Add credits)
> 3. Add the key to your shell profile:
>    ```bash
>    export ANTHROPIC_API_KEY='sk-ant-...'
>    ```
>
> There is no alternative — the loop always runs inside an isolated Docker container."

**Do not suggest running apply→verify on the host as an alternative. There is no fallback.**
