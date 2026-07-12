# Design — or-mcp-schema-dialect

## Context

`x-openregister-*` dialects are top-level keys inside `components.schemas.<Schema>`
in `lib/Settings/{app}_register.json`. On import, `Schema.php` folds every key
matching `x-openregister-*` out of the OpenAPI seed and into the schema's
`configuration` array (see the fold at `Schema.php` ~line 1191, guarded by
`str_starts_with($key, 'x-openregister-')`). A key is only *retained* if it is
listed in `Schema::ANNOTATION_VOCABULARY` (`Schema.php` ~line 1929); anything
else is collected as a "dropped unknown key" and surfaced by
`SchemaMapper::logDroppedAnnotationKeys()`. Each retained dialect gets a
save-time validator invoked from `SchemaMapper::cleanObject()` (`SchemaMapper.php`
~line 623) — e.g. `validateNotificationsAnnotation()`,
`validateCalculationsAnnotation()` — which pulls the annotation out of
`configuration`, hands it to a dedicated `*AnnotationValidator` service, and
throws a single aggregated `Exception` on malformed input so the schema save
fails loudly.

`x-openregister-mcp` joins this family. This change defines only the declaration,
its validation, and its storage — no tools are emitted (that is
`or-mcp-derived-tool-provider`).

## The `x-openregister-mcp` dialect shape

Modelled on `x-speakeasy-mcp` (Specter research 2026-07-12). Per schema:

```jsonc
"x-openregister-mcp": {
  "enabled": true,                       // REQUIRED. Default OFF: absent or false = no tools.
  "tools": {                             // OPTIONAL. Absent => all five verbs emitted with defaults.
    "search": {
      "description": "Search cases by status, assignee and free text.",
      "filters": ["status", "assignee", "createdAt"],   // search only; each MUST be a schema property
      "scope": "read",
      "readOnlyHint": true,
      "destructiveHint": false,
      "idempotentHint": true
    },
    "get":    { "description": "...", "scope": "read",   "readOnlyHint": true },
    "create": { "description": "...", "scope": "create", "destructiveHint": false, "idempotentHint": false },
    "update": { "description": "...", "scope": "update", "destructiveHint": false, "idempotentHint": true },
    "delete": { "description": "...", "scope": "delete", "destructiveHint": true,  "idempotentHint": true }
  }
}
```

Rules:
- **`enabled`** (boolean, REQUIRED when the block is present). Absent block, or
  `enabled:false`, means the schema exposes no MCP tools. This is the opt-in gate
  — default OFF fleet-wide.
- **`tools`** (object, OPTIONAL). Keys MUST be a subset of the fixed verb set
  `{search, get, create, update, delete}`. Any other key is a validation error
  (typo-safety, mirroring the vocabulary-drop pattern). When `tools` is absent
  and `enabled:true`, the derived provider (next change) emits all five verbs
  with sensible defaults; `tools` exists to override descriptions/filters/hints
  and to *narrow* the verb set by listing only the desired verbs.
- Per-verb config:
  - **`description`** (string, optional) — LLM-facing tool description. Falls
    back to a generated default in the provider.
  - **`filters`** (array of strings, `search` only) — each entry MUST name an
    existing property on the schema; unknown property names are a validation
    error. Constrains which fields the search verb accepts as query filters.
  - **`scope`** (string enum `read|create|update|delete`, optional) — declares
    the RBAC intent of the verb; advisory metadata that the derived provider
    maps to the authoritative `ObjectService` permission check. It does **not**
    itself grant or deny.
  - **`readOnlyHint` / `destructiveHint` / `idempotentHint`** (booleans,
    optional) — MCP 2025-11-25 tool annotations. These are **untrusted UX hints**
    passed through to the tool descriptor; the authoritative gate is always OR
    RBAC at invoke time. The validator checks their *type* only, never trusts
    their *value* for any security decision.

### Coarse-template rationale (why verbs, not endpoints)

Naive OpenAPI→MCP (one tool per REST endpoint) degrades LLM accuracy ~9.5% and
burns 30k+ tokens on large surfaces (Speakeasy 50-servers study; arXiv
2508.12566 — Specter research 2026-07-12). The dialect therefore fixes a
**coarse five-verb template** per schema and reuses the schema itself as
`inputSchema`/`outputSchema` (MCP 2025-06-18 `structuredContent`). The dialect
cannot express arbitrary per-endpoint tools by design; non-CRUD behaviour is the
domain of `#[McpTool]` service annotations (`or-mcp-tool-attribute`).

## Validation (`McpAnnotationValidator`)

New service `lib/Service/Mcp/McpAnnotationValidator.php`, constructed shape
mirrors `CalculationAnnotationValidator`: a pure validator with a single
`validate(array $shape): array` returning a list of human-readable error
strings; `SchemaMapper::validateMcpAnnotation()` throws an aggregated `Exception`
when the list is non-empty. `$shape` is `{ 'properties' => $schema->getProperties(),
'x-openregister-mcp' => $annotation }` so `filters` can be cross-checked against
real properties (same pattern `validateLifecycleAnnotation()` uses).

Checks:
1. `x-openregister-mcp` is an object; if not present or not an array, skip (no-op).
2. `enabled` present and boolean.
3. If `tools` present: it is an object; every key ∈ verb set; each verb value is
   an object.
4. Per verb: `description` (if present) string; `scope` (if present) ∈
   `{read,create,update,delete}`; the three `*Hint` keys (if present) boolean.
5. `filters` permitted on `search` only; is a list of strings; every entry names
   a property in `$shape['properties']`.
6. Unknown keys inside a verb config are reported (typo-safety).

Wired into `SchemaMapper::cleanObject()` as `validateMcpAnnotation()`, added to
the existing chain after `validateHandoffAnnotation()`.

## Vocabulary registration + import fold

Add `'x-openregister-mcp'` to `Schema::ANNOTATION_VOCABULARY`. This single line
makes the existing fold retain the key into `configuration` instead of dropping
it. No new fold code is needed — the generic `x-openregister-*` fold already
handles it.

## Seed Data

This change ships **no seed data of its own** — it defines a dialect, not
content. The dialect is exercised by seed data that lives in the *leaf apps'*
`lib/Settings/{app}_register.json` register seeds (e.g. pipelinq declaring
`x-openregister-mcp` on its `client`/`lead`/`ticket` schemas), delivered in the
separate `pipelinq/mcp-provider-declarative-migration` change. For OpenRegister's
own test/dev coverage, a **fixture** schema carrying a representative
`x-openregister-mcp` block (one schema, `enabled:true`, all five verbs, one
`search.filters` referencing a real property, one invalid variant for the
negative-path test) SHOULD be added under the test fixtures — it is test input,
not a shipped register. No OR production register schema is opted in by this
change (default-OFF preserved).

## Declarative vs imperative

This dialect is **purely declarative**: it is data in `configuration`, consumed
downstream by the `SchemaDerivedToolProvider` (`AnnotationNotifier`-style
consumption precedent — `x-openregister-notifications` → `AnnotationNotifier`).
No imperative code path in *this* change reads it beyond validation. The
declarative boundary is deliberate:
- **Declared here:** *which* schemas expose *which* CRUD verbs, with what
  descriptions/filters/hints.
- **NOT declared here (imperative, later changes):** how tools are emitted, how
  writes reach `ObjectService`, how RBAC is enforced, how invocations are
  audited. A schema author declares intent; they cannot inject behaviour.
- Anything the coarse CRUD template cannot express is out of scope for the
  declarative dialect and belongs in an imperative `#[McpTool]` service method
  (`or-mcp-tool-attribute`).

## Non-Goals

- No tool emission, no catalog entry, no serving-surface wiring (next change).
- No agent-side whitelist / progressive-disclosure UX (hermiq change).
- No manifest-v2 `mcp` block (nc-vue change; that block is *visibility hints*
  only — the register stays the source of CRUD truth).
- No OAuth 2.1 / streamable-HTTP transport work (ADR-063 notes it as direction).

## Open questions

- Should `x-openregister-mcp` support a per-verb `name` override
  (`{appId}.{schema}.{customName}`) or is the fixed `{verb}` suffix sufficient?
  Deferred to the derived-provider change; the dialect currently fixes the verb
  suffix.
