## Context

ADR-063 (MCP as Platform Abstraction) established three tool sources feeding
one catalog: built-in tools, schema-derived tools, and `#[McpTool]`-attributed
service methods. PR #373 gave schema-derived tools optional MCP 2025-11-25
annotation hints (`readOnlyHint`/`destructiveHint`/`idempotentHint`) and a
`scope`, validated against `McpAnnotationValidator::HINT_KEYS`/`SCOPES` and
forwarded through `SchemaDerivedToolProvider::buildDescriptor()` to both
serving surfaces. `McpProviderBridge::getFunctions()` was made to forward
*any* descriptor key present in those two vocabularies — it already loops
`HINT_KEYS` and checks `array_key_exists('scope', ...)` generically, not
tied to the derived provider. `#[McpTool]` never got the matching authoring
surface, so attribute-derived tools structurally cannot carry these fields
even though the forwarding machinery on the chat surface already accepts
them from any provider.

## Goals / Non-Goals

**Goals:**
- Let an app author optionally declare `readOnlyHint`/`destructiveHint`/
  `idempotentHint`/`scope` on `#[McpTool]`, using the exact same vocabulary
  PR #373 established (`McpAnnotationValidator::HINT_KEYS`/`SCOPES`).
- Forward whatever the author set, additively, through
  `AttributeToolScanner` → `AttributeToolProvider::getTools()` to both the
  JSON-RPC (`McpToolsService::listTools()`) and chat/facade
  (`ToolRegistryFacade::listTools()` via `McpProviderBridge`) surfaces.
- Reject (log + skip, not throw) an attributed method that declares an
  unknown `scope` value, mirroring the scanner's existing fail-soft handling
  of other malformed attribute input.

**Non-Goals:**
- No change to `McpProviderBridge` — it already forwards any descriptor key
  in `HINT_KEYS`/`scope` regardless of which provider produced the
  descriptor; confirmed at HEAD, so this change touches attribute-side code
  only.
- No change to invoke-time authorization. Hints/scope remain advisory UX
  metadata; `ObjectService`/service-method RBAC stays the sole authoritative
  gate (ADR-063, REQ-ATTR-003 unchanged).
- No new hint vocabulary and no default/inferred values — an omitted field
  stays omitted; nothing is fabricated on an author's behalf.
- No change to Hermiq's `ToolGrantResolver` — that is a downstream consumer
  in a different repo; this change only makes the signal available.

## Decisions

- **Reuse `McpAnnotationValidator::HINT_KEYS`/`SCOPES` verbatim.** The
  schema dialect already canonicalised this vocabulary; inventing a second
  one on the attribute would fragment the concept ADR-063 is trying to
  unify. `McpAnnotationValidator` lives in `Service\Mcp` (schema-dialect
  namespace) but its constants are plain `public const` arrays with no
  dialect-specific coupling, so `AttributeToolScanner` (namespace `Mcp`) can
  reference them directly without a circular or layering violation.
- **Validate `scope` at scan time, not construction time.** The attribute
  class itself stays a plain, dependency-free data holder (consistent with
  its current design — no validation logic today). `AttributeToolScanner`
  already validates/rejects malformed attributes (non-public, static,
  abstract methods); adding "unknown scope" to that same fail-soft path is
  the smallest, most consistent change. Hint booleans need no validation:
  the constructor's PHP `bool` type already makes an invalid value a
  TypeError at attribute-instantiation time.
- **Additive descriptor keys, never defaulted.** `AttributeToolScanner::
  buildDescriptor()` sets a key only `if ($attribute->readOnlyHint !== null)`
  (etc.) — identical omission discipline to
  `SchemaDerivedToolProvider::buildDescriptor()`. A fabricated hint on an
  unannotated tool would misinform exactly the consumer (Hermiq's grant
  gate) this change exists to help.
- **No `McpProviderBridge` change.** Verified at HEAD:
  `getFunctions()` loops `McpAnnotationValidator::HINT_KEYS` and checks
  `scope` on the descriptor array generically — it has no dependency on
  which provider produced the descriptor. This is provider-agnostic by
  construction.

## Risks / Trade-offs

- [Risk] A consumer might misread "advisory hint" as an authorization
  decision → Mitigation: docblocks on `McpTool`, `AttributeToolScanner`,
  and the new requirement's scenario all restate ADR-063's "RBAC remains
  authoritative" contract; unchanged from the existing REQ-ATTR-003 pattern.
- [Risk] Divergence between the dialect's `SCOPES`/`HINT_KEYS` and the
  attribute's usage if `McpAnnotationValidator` changes later → Mitigation:
  attribute code references the constants directly (no copy-paste), so a
  future change to the vocabulary propagates automatically.
- [Trade-off] Validation lives in the scanner (runtime discovery) rather
  than at attribute-declaration time (no static analysis catches a bad
  `scope` string until the class is scanned) — accepted because the
  attribute class has no validation today and adding one would be a larger,
  unrelated change to its contract.

## Migration Plan

Purely additive, backward compatible: existing `#[McpTool]` usages (both
positional and named-argument call sites) are unaffected since the four new
constructor parameters are optional with `null` defaults appended after the
existing two. No deploy or rollback steps beyond a normal PR merge.

## Open Questions

None — the vocabulary, forwarding pattern, and bridge behavior are all
already established and confirmed at HEAD by PR #373.
