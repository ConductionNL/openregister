# Retrofit — Bucket 2b small-misc (all-DROP triage)

Bundled triage of 9 methods across 5 tiny directory clusters (`lib/Dto/`, `src/navigation/`, `src/dialogs/`, `src/router/`, `lib/Tool/`). After sampling each method body, **all 9 entries DROP** — none warrants a new REQ.

This change is a paper-trail-only retrofit: no spec delta, no tasks, no annotations. It exists so a future re-scan against the coverage report has a record of *why* these methods were not specced, rather than appearing un-triaged.

## Triage results — 9 / 9 DROP

### `lib/Dto/DeletionAnalysis.php` (3 methods) — DROP

Pure DTO. The data class carries the result of `ReferentialIntegrityService::canDelete()`, which is the actual capability (already covered by the referential-integrity / archival-destruction-workflow specs).

- `__construct` — promoted-property constructor for a readonly value object. Plumbing.
- `empty` — static factory returning `new self(deletable: true)`. Plumbing.
- `toArray` — mechanical key→value flattening for JSON serialization. Plumbing.

A DTO is a data carrier; its public surface mirrors its fields by definition. Specifying the constructor/factory/serializer would just restate the field list.

### `src/navigation/Configuration.vue::fetchData` — DROP (with observation)

`fetchData(_newPage)` issues `GET /index.php/apps/opencatalogi/configuration` and assigns the response to `this.configuration`. The endpoint is in the **opencatalogi** app, not openregister — this looks like leftover scaffold from an early OR fork of opencatalogi. The component is also not referenced from the current router or MainMenu (router has `/configurations` → `ConfigurationsIndex`, not this `Configuration.vue`).

**Observation**: this file may be dead code. Flagging for cleanup but not specifying — specifying dead code would lock in unwanted behavior. Track as a cleanup follow-up rather than a retrofit REQ.

### `src/navigation/MainMenu.vue::handleNavigate` — DROP

`handleNavigate(path) { this.$router.push(path) }` — one-line wrapper around the Vue Router. Pure plumbing. The actual navigation behavior is owned by `vue-router` + the route table in `src/router/index.js`.

### `src/dialogs/Dialogs.vue::onConfigSetCreated` — DROP

`onConfigSetCreated() { this.$root.$emit('configset-updated') }` — re-emits a child component's event up through the root bus. Plumbing for the existing CreateConfigSetDialog capability; not a new behavior.

### `src/router/index.js::routeKeyByPath` — DROP

Not actually a method — it's an exported `const` lookup table mapping route paths to `navigationStore` keys for backward-compatibility. Static configuration data, not behavior. The coverage scanner picked it up because it's an exported symbol.

### `lib/Tool/AgentTool.php::__construct` — DROP (pre-triaged)

DI constructor — pulls `AgentMapper`, `IUserSession`, `LoggerInterface`, delegates to parent. The AgentTool's actual behavior (`getName`, `getDescription`, `runWithUser`, etc.) is already annotated against `retrofit-2026-04-28-b2b-crossrefs/tasks.md#task-29`. Plumbing.

### `lib/Tool/StreamingToolInstanceWrapper.php::detectIsError` — DROP (pre-triaged)

Private helper. Three-line check for the MCP `{isError: true}` envelope. The wrapper's class-level `@spec` already points at `ai-chat-companion-streaming/specs/chat-ai/spec.md#tool-call-and-tool-result-sse-events` — that requirement *is* this behavior. The helper is an internal detail of an already-specced requirement.

## Approach

- Sampled each method body to confirm it was plumbing / internal detail / dead code, not new observable behavior
- For methods already implicitly covered by an existing class-level `@spec` (the two `lib/Tool/` entries), confirmed the parent annotation suffices
- For DTO methods, confirmed the owning service capability is already specced
- For the suspicious Vue file (`Configuration.vue`), flagged as observation rather than spec-locking dead code

## What this change does NOT do

- No new REQs minted (cap was 5; DROPs hit 9/9).
- No code annotations added — there is no `task-N` to point at.
- No `.opsx-ignore` patches — leaving the matcher behavior unchanged so a future re-scan still surfaces these for review if their bodies change materially.

Source: `/tmp/or-scan/rspec-2b-small-misc.json` (batch produced 2026-05-24). See [retrofit playbook](../../../.github/docs/claude/retrofit.md).
