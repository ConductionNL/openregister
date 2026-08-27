# governed-cli-mcp-transport Specification

**Status**: planned
**Scope**: openregister (the ADR-099 §5 capability grammar); hermiq owns the rest of this capability
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

<!--
  RELOCATED SUBSET — the canonical home for this capability is hermiq.

  ADR-099 §5 moved the tool-grant grammar (ToolGrantSet, ToolGrantCodec,
  ToolGrantResolver, ToolReachResolver, ToolGrantResolutionException) into
  OCA\OpenRegister\Service\Capability. The requirements below are the ones that
  code implements, copied VERBATIM from hermiq so their headings — and therefore
  every `@spec` anchor pointing at them — resolve unchanged.

  🔴 THIS FILE IS NOT THE WHOLE SPEC. 4 further requirement(s) live in
  hermiq's `openspec/specs/governed-cli-mcp-transport/spec.md` and are NOT duplicated here: they
  describe behaviour hermiq still owns — the `Agent.tools` binding, the approval
  gate, the oversight surface, the CLI transport. Read that file for them.

  WHY A COPY AT ALL. A `@spec` tag is dereferenced by gate-46 against the
  REPOSITORY it sits in, so a cross-repo reference is not expressible: an
  openregister class citing a hermiq spec resolves to nothing, which is the
  ~300-dead-tag shape this fleet already carries from archived changes. The
  duplication is therefore structural rather than an oversight — and it is
  bounded to exactly the requirements the moved code implements, which is why
  this file is a subset and not the original.
-->

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
