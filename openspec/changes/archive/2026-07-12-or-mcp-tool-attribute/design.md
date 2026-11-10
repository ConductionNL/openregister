# Design — or-mcp-tool-attribute

## Context

The dialect + derived provider cover coarse CRUD. Non-CRUD behaviour (writes with
side effects, aggregations, domain actions) cannot be expressed as
`{schema}.{verb}` and is today hand-coded in per-app `IMcpToolProvider`
implementations (pipelinq `TOOL_DESCRIPTORS` + if/else dispatch). ADR-063's
second pillar replaces the hand-written provider with a **declared attribute** on
the service method itself, discovered by OpenRegister. There is **no attribute
scanning anywhere in the fleet today** — this is net-new; the pattern is
php-mcp/server (schema inferred from type hints + docblocks).

This change reuses the catalog + dual-surface registration + audit + RBAC path
established by `or-mcp-derived-tool-provider`; it adds only the attribute, the
scanner, and the in-process invocation adapter.

## The `#[McpTool]` attribute

Net-new PHP 8 attribute, e.g. `OCA\OpenRegister\Mcp\Attribute\McpTool`, targeting
`Attribute::TARGET_METHOD`:

```php
#[McpTool(
    name: 'createLead',                       // optional; defaults to method name
    description: 'Create a sales lead from a contact moment.'  // optional; defaults to docblock summary
)]
public function createLead(string $email, ?string $company = null): array { ... }
```

- **`name`** (optional) — the tool's local name; the catalog id is
  `{appId}.{name}` (appId from the owning app). Defaults to the method name.
- **`description`** (optional) — LLM-facing; defaults to the method's docblock
  summary line.
- **`inputSchema`** — **inferred** from the method's parameter type hints +
  docblock `@param` tags: PHP scalar/array/nullable types map to JSON-schema
  types; parameter names become property names; parameters without defaults are
  `required`. `@param` descriptions become property descriptions.
- **`outputSchema`** — inferred from the return type + `@return` where present;
  best-effort (may be omitted when the return type is untyped `array`/`mixed`).
- Placed on **public methods** only. Non-public attributed methods MUST be
  ignored (with a warning) — a tool must be callable.

## Reflection scanner + registration

New scanner (e.g. `lib/Mcp/AttributeToolScanner.php`) wired into `Application`'s
MCP discovery alongside `collectPerAppMcpProviders()`:

1. For each installed app, resolve candidate service classes (the app declares
   which classes/namespaces to scan — see the discovery-scope decision below).
2. Reflect each class for public methods carrying `#[McpTool]`.
3. Build a descriptor `{ id: "{appId}.{toolName}", name, description, inputSchema }`
   per attributed method (schema inferred as above).
4. Register the descriptors in the same catalog as derived tools by wrapping them
   in a thin `IMcpToolProvider`-shaped adapter per owning app, so they flow
   through BOTH surfaces (`McpToolsService` + `ToolRegistry`/`McpProviderBridge`)
   with no new serving code — same as the derived provider.

### Discovery scope (how the scanner finds attributed classes)

Reflecting *every* class of *every* installed app on every boot is too expensive
and fragile. Decision: an app **opts its service classes into scanning**, reusing
the existing per-app MCP discovery convention. Candidates, in preference order:
- a manifest/DI-declared list of scannable service FQCNs (explicit, cheapest), or
- a conventional attributed-service namespace (e.g. `OCA\{App}\Service\*`)
  discovered via the app's autoload map.

The exact opt-in mechanism is a **DEFERRED_QUESTION** (see below) — the spec
fixes the *behaviour* (attributed public methods in the app's declared scannable
services are registered as `{appId}.{toolName}`), not the enumeration mechanism.

## In-process invocation (ADR-041 — no cross-app RPC)

This is the load-bearing constraint. Invoking `{appId}.{toolName}` MUST:
1. resolve the owning app's service instance from **that app's own DI container**
   (`\OC::$server->get(...)` / the app's `ContainerInterface`), and
2. call the attributed method **in-process, in the owning app's runtime**, with
   the JSON-decoded arguments mapped to the method parameters.

There MUST be **no HTTP call, no message bus, no OR-side re-implementation** of
the method — OpenRegister is the registry/catalog and the blessed inbound door
(`ToolRegistryFacade`), but the behaviour executes inside the app that owns it,
in the same PHP process, in the caller's ambient NC session. This is exactly
gate-27/ADR-041's "no phantom cross-app RPC": the tool call resolves to a direct
in-process method call on the owning app's own service, never a synthesized
cross-app request.

## Audit + RBAC parity

Identical rules to the derived provider (`or-mcp-derived-tool-provider`):
- **RBAC/IDOR:** the method runs in the caller's ambient session; OR MUST NOT
  impersonate or elevate. The owning app's method is responsible for its own
  authorization and IDOR checks (it may itself call `ObjectService`, whose RBAC
  applies). OR's contract is "invoke as the current principal, unchanged".
- **Audit (AI Act art.12/14):** every attributed-tool invocation writes exactly
  one immutable audit record (acting identity — agent non-human id when present
  else NC user; `toolId` `{appId}.{toolName}`; params digest, not raw args;
  result summary) through the same audit-trail abstraction
  (`AuditTrail`/`AuditHashService`) the derived provider uses. Read/write/failed
  invocations are all audited.

## Namespacing + precedence

- Attributed ids `{appId}.{toolName}` are disjoint from derived
  `{appId}.{schema}.{verb}` ids by construction (three-part vs two-part after the
  appId).
- Collision with a **hand-written** provider tool follows the same
  hand-written-wins precedence as derived tools.
- Collision between an **attributed** id and a **derived** id (e.g. an app names a
  tool `lead.search`) is a **developer error** surfaced at discovery/validation
  time, not silently resolved — the scanner MUST log/reject the ambiguous
  attributed tool so the developer renames it.

## Seed Data

No production seed data. Test coverage needs a **fixture service** carrying two
`#[McpTool]` methods — one with fully typed + docblocked params (to exercise
schema inference) and one minimal (name/description defaulted) — plus a
non-public attributed method (to assert it is ignored). These are test inputs,
not shipped code. The real first consumers are leaf apps annotating their own
services (pipelinq `createLead`/`logContactmoment`/`pipelineForecast`), delivered
in their own migration changes.

## Declarative vs imperative

This change sits on the **imperative** side of ADR-063's split: `#[McpTool]`
marks *handwritten behaviour* (a real service method, code-reviewed like any
other) for exposure, in contrast to the *declarative* `x-openregister-mcp` dialect
which derives CRUD from schema data with no per-tool code. The two are
complementary and deliberately partitioned:
- **Declarative (dialect):** coarse CRUD, no code, derivable from schema.
- **Imperative (attribute):** anything CRUD cannot express, as annotated code
  owned and executed by the app.
The attribute is *discovery metadata over existing imperative code* — it does not
itself add behaviour or a new execution path beyond calling the annotated method
in-process. OpenRegister never runs app logic; it catalogs it and routes the call
back to the app.

## Non-Goals

- No agent-side whitelist / progressive disclosure (Hermiq change).
- No OAuth 2.1 / streamable-HTTP transport (ADR-063 direction only).
- No change to the `IMcpToolProvider` ABI, `McpToolsService` semantics, or the
  derived provider's dialect.
- No cross-app RPC transport — in-process only (ADR-041).

## DEFERRED_QUESTIONS

- **Discovery scope / opt-in mechanism:** explicit DI-declared scannable-service
  list vs conventional namespace scan vs a manifest key. Affects boot cost and
  the leaf-app authoring ergonomics; coordinate with the manifest-v2 `mcp` block
  (nc-vue change) which may be the natural home for the scannable-service
  declaration.
- **Schema-inference fidelity:** how far to infer `outputSchema` from untyped
  `array`/`mixed` returns, and whether to support a `#[McpToolParam]`-style
  per-parameter refinement attribute (php-mcp/server has one). Deferred; v1
  infers from type hints + docblocks only.
- **Attributed↔derived id-collision policy:** reject at discovery (design
  recommendation) vs allow with hand-written-style precedence. Confirm with the
  ADR-063 author.
