# governed-cli-mcp-transport Specification

**Status**: planned
**Scope**: hermiq
**OpenSpec changes**:
- `cli-runner-governed-mcp-and-egress`

## Purpose

When a Hermiq turn runs through the `claude` CLI (`executionMode: cli`, the `hermiq-llm-runner` ExApp), the
CLI owns its own agent loop and its own MCP client — inverting the `http` path, where Hermiq is the MCP
client and calls tools itself. This capability keeps **all** governance in Hermiq across that inversion:
Hermiq exposes a governed MCP **server** that serves only the tools the run's agent is granted and executes
every call through the existing `FacadeToolInvoker` path, so guardrails, per-tool approval, redaction,
model-policy, evals, budgets and run tracing all still apply (ADR-001 — Hermiq owns the agent core and its
governance; ADR-023 — action authorization is the app's job).

It exists because the `claude` CLI **cannot** accept a tool schema: `--tools` selects from the built-in set,
`--allowedTools`/`--disallowedTools` filter names, and custom tools reach the CLI **only via MCP**. The
requirement in `llm-cli-runner-exapp` to dispatch a tool schema to `POST /run` is therefore not implementable
and is corrected by this change (see Notes).

## ADDED Requirements

### Requirement: Hermiq serves a governed MCP endpoint scoped to a single run
Hermiq MUST expose an MCP server endpoint that a `cli`-mode run's CLI can reach. `tools/list` MUST return
**only** the tools `ToolGrantResolver::resolve($agent->tools, $catalog)` yields for that run's agent —
honouring exact grants, `{app}.{schema}.*` read-only wildcards, `:write` opt-in, and default-deny on
write/destructive tools. `tools/call` MUST dispatch through `FacadeToolInvoker`, so guardrail classification,
the human-approval gate, redaction, dry-run neutralisation, tenant model-policy, budgets and run tracing
apply exactly as on the `http` path. The endpoint MUST NOT introduce a second tool-execution path.

The CLI MUST NOT be able to reach OpenRegister's MCP server directly; OpenRegister's tool registry is reached
only through Hermiq's governed dispatch.

@e2e exclude Container-to-backend JSON-RPC transport with no UI surface — covered by PHPUnit (controller, token service) + the ExApp's own container tests

#### Scenario: only granted tools are listed

- **GIVEN** an agent whose `Agent.tools` grants `openregister.contact.*` (a read-only wildcard) and nothing else
- **WHEN** the CLI calls `tools/list` on the governed MCP endpoint with a valid per-run token
- **THEN** the response contains only that schema's read verbs
- **AND** no write/destructive tool and no tool from another schema appears

#### Scenario: a tool call is governed, not passed through

- **GIVEN** a `cli`-mode run whose agent is granted a tool the org's guardrail policy classifies `confirm`
- **WHEN** the CLI calls `tools/call` for that tool
- **THEN** the call is routed through `FacadeToolInvoker` and the approval gate is enforced
- **AND** the result returned to the CLI is redacted and the call is recorded on the run trace

#### Scenario: a governed refusal is visible to the model, not a transport failure

- **GIVEN** a `tools/call` that a guardrail denies, or that names a tool outside the agent's grants
- **WHEN** the endpoint responds
- **THEN** it returns HTTP 200 with `result.isError: true` and an explanatory message, so the model can adapt
- **AND** the tool is not executed

#### Scenario: the CLI cannot reach OpenRegister's MCP

- **GIVEN** a running `cli`-mode turn
- **WHEN** the CLI's MCP client resolves its configured servers
- **THEN** Hermiq's governed endpoint is the only MCP server available to it

### Requirement: The runner-to-Hermiq call is authenticated by a short-lived run-scoped token
Hermiq MUST mint a per-run bearer token when dispatching an `executionMode: cli` turn, and **both** governed
endpoints — the tools (MCP) endpoint and the egress policy endpoint — MUST reject any request without a valid
one. A single token MUST serve both, so that one mint, one expiry and one revocation govern the whole run;
closing the run MUST invalidate both capabilities atomically. The token MUST be bound to its run (runId,
agentId, userId), MUST expire with the run, MUST be invalidated when the run closes, and MUST grant nothing
beyond the tools that run's agent was already granted and the hosts the policy already allows. The acting user
and agent MUST be resolved **from the token**, never from the request body. Token values MUST NOT appear in
any log line, error body, or process argument list.

The AppAPI shared secret authenticates Hermiq→runner and MUST remain required on `POST /run`; it does not
authenticate the reverse direction.

@e2e exclude Machine-to-machine bearer authentication with no UI surface — covered by PHPUnit (RunTokenService, McpRunController)

#### Scenario: a request without a valid token is rejected before any tool work

- **GIVEN** a request to the governed MCP endpoint with a missing, malformed, expired or already-consumed token
- **WHEN** the endpoint handles it
- **THEN** it is rejected with 401 before any tool is resolved or invoked
- **AND** the response body carries a static, generic message with no token value and no internal detail

#### Scenario: a token cannot reach another run's tools

- **GIVEN** a valid per-run token minted for run A
- **WHEN** it is used to call `tools/list` or `tools/call`
- **THEN** only run A's agent's granted tools are resolved, from the token's own binding
- **AND** no runId, agentId or userId supplied in the request body can change which run is served

#### Scenario: the token dies with the run, for both endpoints

- **GIVEN** a `cli`-mode run that has completed, errored, or timed out
- **WHEN** its token is presented to the governed MCP endpoint, and separately to the governed egress endpoint
- **THEN** both reject it — no tool is invoked and no tunnel is permitted
- **AND** the two capabilities died together, because one token backs both

### Requirement: The CLI is locked to Hermiq's governance by its invocation flags
When the runner invokes the `claude` CLI for a governed turn, the assembled arguments MUST include
`--tools ""` (disabling **every** built-in tool — `Bash`/`Read`/`Write`/`Edit` **and** `WebFetch`/`WebSearch`)
and `--strict-mcp-config` (restricting the CLI to the MCP servers Hermiq names). Together these MUST force
all tool use and all agent internet access through Hermiq's governed MCP endpoint, leaving the container
filesystem unreachable to the model and no native internet route available to it.

The MCP configuration MUST be passed as a file, not as an inline string: the config carries a live bearer
token, and an inline string would place it on the process command line. The file MUST be written mode `0600`
inside the per-call throwaway scratch directory and removed when the call ends.

@e2e exclude ExApp container CLI argv assembly — covered by the runner's own container tests

#### Scenario: the governed flags are present on every governed turn

- **GIVEN** a `cli`-mode turn dispatched with the governed MCP endpoint configured
- **WHEN** the runner assembles the CLI arguments
- **THEN** they include `--tools ""`, `--strict-mcp-config`, and `--mcp-config <path to the scratch file>`

#### Scenario: the token never reaches the process table

- **GIVEN** a governed turn is running
- **WHEN** the CLI child process's arguments are inspected
- **THEN** no bearer token value appears on them
- **AND** the MCP config file is mode `0600` and is removed after the call completes

#### Scenario: built-ins cannot reach the container filesystem

- **GIVEN** a governed turn whose model attempts to read or write a file, or to fetch a URL natively
- **WHEN** the CLI resolves the tool
- **THEN** no built-in tool is available to it, because `--tools ""` disabled the whole built-in set

### Requirement: Agent internet access is governed at two layers by one allowed-URL policy
Agent internet access MUST be governed at **two independent layers**, both fed by the **same** policy source
(`WebResearchEgressGuard`) so the allowlist can never fork or drift:

1. **Per-agent authorization (Endpoint 1).** An agent MUST reach the web only through Hermiq's governed web
   tools (`hermiq.webFetch` / `hermiq.webSearch`) served over the governed MCP endpoint. Every such request
   MUST pass `WebResearchEgressGuard` — the SSRF blocks (loopback, link-local, RFC1918, ULA), the
   admin-configured exact-hostname allowlist and the denylist, re-resolved per request to defeat DNS
   rebinding. This layer sees the **full URL**.
2. **Network-layer backstop (Endpoint 2).** The runner container MUST have **no default route**; a forward
   proxy MUST be its only path to any network. That proxy MUST consult Hermiq's governed egress endpoint on
   every connection and MUST deny unless Hermiq returns an explicit allow. This layer sees **host:port only**
   (a `CONNECT` hides the path inside TLS) and MUST NOT depend on any CLI flag remaining correct.

Both layers MUST fail closed. The allowlist MUST NOT be bypassable from inside the container.

@e2e exclude Backend egress governance for a container-hosted agent — covered by PHPUnit (WebResearchEgressGuard is already covered) + runner/proxy container tests

#### Scenario: a non-allowlisted host is refused at the tool layer

- **GIVEN** an admin-configured allowlist that does not contain `internal.example`
- **WHEN** the agent calls `hermiq.webFetch` for a URL on that host
- **THEN** the guard refuses it and no request leaves Hermiq
- **AND** the refusal is returned to the model as a tool error so it can adapt

#### Scenario: the proxy denies a non-allowlisted host at the network layer

- **GIVEN** the runner container on a network with no default route, reaching the world only via the governed proxy
- **WHEN** any process in the container opens a connection to a host that is neither `api.anthropic.com` nor a
  Hermiq endpoint nor allowlisted
- **THEN** the proxy consults Hermiq's governed egress endpoint, receives a deny, and refuses the tunnel
- **AND** no packet reaches the destination host

#### Scenario: a built-in-tool regression does not bypass the proxy

- **GIVEN** a runner build in which `--tools ""` is absent, so the CLI's built-in `WebFetch` is available again
- **WHEN** the model uses that built-in to fetch a non-allowlisted host
- **THEN** the proxy still denies the connection, because the network-layer backstop does not depend on the flag
- **AND** the agent does not obtain ungoverned internet access

#### Scenario: the proxy fails closed when the policy endpoint is unavailable

- **GIVEN** Hermiq's governed egress endpoint is unreachable, erroring, or timing out
- **WHEN** any process in the container attempts an outbound connection
- **THEN** the proxy denies it — an unavailable decision point MUST NOT be read as permission
- **AND** only an explicit allow from Hermiq permits a tunnel

#### Scenario: one policy source governs both layers

- **GIVEN** an admin removes a host from the allowlist in Hermiq's settings
- **WHEN** the agent calls `hermiq.webFetch` for that host, and separately when any process connects to it
- **THEN** both are refused, because both layers evaluate the same `WebResearchEgressGuard` policy
- **AND** no second, independently-maintained allowlist exists to drift out of sync

### Requirement: A turn that cannot be governed fails loudly and is never silently tool-less
If a turn requests tools but the selected transport cannot honour them, Hermiq MUST raise a clear error
before the CLI is spawned. It MUST NOT downgrade the turn to text-only, because a tool-less agent looks
healthy and simply never calls a tool. Specifically, Hermiq MUST raise when: the governed MCP endpoint is not
reachable from the runner; the per-run token cannot be minted or the MCP config cannot be written; the agent's
resolved tool set is **empty** while the turn requires tools; or `--tools ""` / `--strict-mcp-config` is
absent from the assembled arguments.

@e2e exclude Backend transport failure modes — covered by PHPUnit (ProviderFactory cli branch)

#### Scenario: a tool-requiring turn with an unreachable governed endpoint raises

- **GIVEN** `executionMode: cli` and a turn that requires tools
- **WHEN** the governed MCP endpoint cannot be reached from the runner
- **THEN** a `ProviderUnavailableException` (503) naming the cause is raised and no CLI turn is attempted

#### Scenario: an empty resolved tool set raises rather than running text-only

- **GIVEN** a tool-requiring `cli`-mode turn whose agent resolves to zero granted tools
- **WHEN** the turn is dispatched
- **THEN** it fails with a clear error
- **AND** no text-only turn is run in its place

#### Scenario: a missing lockdown flag raises

- **GIVEN** assembled CLI arguments that omit `--tools ""` or `--strict-mcp-config`
- **WHEN** the runner prepares to spawn the CLI
- **THEN** it refuses to spawn and reports the missing boundary

## Non-Functional Requirements

- **Performance:** `tools/list` MUST resolve from the already-loaded catalog and add no OpenRegister round-trip
  beyond what the `http` tool loop already makes. A `tools/call` MUST return within the run's remaining budget;
  the CLI is SIGKILLed at `RUNNER_TIMEOUT_MS` (default 120000ms) regardless.
- **Accessibility:** Not applicable — this capability has no UI surface. The governed MCP endpoint is a
  machine-to-machine JSON-RPC route with no rendered output.
- **Internationalization:** Dutch and English MUST be supported for any operator-facing error surfaced from a
  failed `cli` turn (ADR-007). JSON-RPC protocol errors and tool errors returned to the model are not
  translated — they are consumed by an LLM, not a person, and translating them would degrade model behaviour.

## Acceptance Criteria

- `tools/list` returns exactly `ToolGrantResolver::resolve()`'s output for the run's agent — verified against
  an agent with a read-only wildcard grant and an agent with an explicit write grant.
- `tools/call` reaches `FacadeToolInvoker`; a guardrail deny, a pending approval and a non-allowlisted URL each
  return `isError: true` and execute nothing.
- The proxy denies a non-allowlisted host, denies when the policy endpoint is down (fail closed), and still
  denies when `--tools ""` is absent — proving the backstop is independent of the CLI flags.
- Both layers refuse the same host after an admin removes it from the allowlist, proving one policy source.
- A missing, expired, consumed or foreign-run token is rejected 401/403 by **both** endpoints before any tool
  resolution or tunnel.
- The acting user and agent are resolved from the token; body-supplied ids cannot redirect the run served.
- The assembled CLI argv contains `--tools ""`, `--strict-mcp-config` and a `--mcp-config` **path**; no token
  appears on argv; the config file is `0600` and removed after the call.
- A tool-requiring turn raises rather than running text-only for each of the four failure modes in REQ-005.
- No token value appears in any log line, error body or process argument.
- `composer check:strict` and PHPUnit green; the runner's container tests green.

## Notes

- **This change corrects `llm-cli-runner-exapp`.** That change is still open (it is **not** archived — it has
  no canonical spec under `openspec/specs/`), so its incorrect requirement is corrected by editing
  `openspec/changes/llm-cli-runner-exapp/specs/llm-cli-runner-exapp/spec.md` in place rather than by a
  MODIFIED delta here. Two corrections: (1) the tool-schema dispatch requirement is removed — the CLI cannot
  accept tool schemas; (2) "no Nextcloud access" is narrowed to "exactly one token-gated Hermiq origin", since
  the governed MCP endpoint requires reachability. All other hardening — non-root, no host/user mounts, no
  general internet, per-call env-only credentials — is unchanged. Net egress allowlist becomes
  `api.anthropic.com` + the Hermiq host.
- **The orphaned-capability defect this closes:** `exapp/llm-runner/src/server.js:110` destructures no `tools`
  while `server.js:121` claims it does; `runner.js:112` has no `tools` parameter; so
  `providers.js pickToolCalls()` can only ever return `[]`. The false comment is removed as part of this work.
- **No schema change.** `Agent.tools` already carries the per-agent allowlist and `ToolGrantResolver` already
  resolves it (ADR-035 Decision 4 froze the shape as `string[]`). The allowed-URL list is already admin
  `IAppConfig` state via `WebResearchSettingsHandler`.
- **Chain position (ADR-032).** This is link 3 of 3. Predecessors: `cli-runner-credential-declaration`
  (`config` — the `anthropic-cli` inject-only provider + the Hermiq manifest declaration) and
  `cli-runner-text-turn-dispatch` (`code` — the text-only `cli` turn). Both MUST close before this link starts.
- **Two endpoints, decided.** Hermiq exposes a governed **tools** (MCP) endpoint and a governed **egress**
  (proxy PDP) endpoint. They are complementary: the tools endpoint is per-agent authorization over the full
  URL; the proxy is a network-layer backstop over host:port that does **not** depend on `--tools ""` staying
  correct. Both terminate in the same `WebResearchEgressGuard::assertSafe()` — one policy, never forked. A
  one-endpoint variant was proposed and rejected; see design.md "Two governed endpoints vs. one".
- **`WebResearchEgressGuard` needs no refactor.** `assertSafe()` (`WebResearchEgressGuard.php:105`) is already
  public and dependency-free — no constructor, no injected state — so both endpoints call it directly.
  Verified against HEAD.
- **The CONNECT limitation.** A `CONNECT` exposes only host:port, so the proxy layer enforces at host
  granularity; full-URL enforcement stays on the tools endpoint. TLS interception was rejected (it would break
  cert pinning, put a forged-cert authority in the sandbox, and expose prompt plaintext to the proxy).
- **ADR-005 deviation** — the per-run bearer token is a non-Nextcloud credential, justified and bounded in
  design.md "Authentication". Precedents: `WebhookSecretService`, `ScheduleWebhookSecretService`.
- **Anthropic ToS** — a Claude Max/Pro OAuth token is PERSONAL-SCOPE ONLY; reject at organisation scope.
