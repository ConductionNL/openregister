## 1. Facade class

- [x] 1.1 Create `lib/Service/Mcp/ToolRegistryFacade.php` — constructor takes
  `ToolRegistry $toolRegistry, LoggerInterface $logger` only (both autowired by
  Nextcloud's DI container; no `Application.php` registration needed, matching
  `ToolRegistry` itself).
  - Acceptance: class exists under `OCA\OpenRegister\Service\Mcp`, SPDX
    `@license`/`@copyright` docblock present, class docblock states the
    stability contract per proposal.md.
- [x] 1.2 Implement `listTools(array $toolWhitelist = []): array` — iterate
  `ToolRegistry::getAllTools()` ids (optionally intersected with
  `$toolWhitelist`), resolve each via `getTool()`, flatten `getFunctions()`
  results into one list.
  - Acceptance: empty whitelist returns every registered tool's functions;
    non-empty whitelist returns only functions from matching registry ids.
- [x] 1.3 Implement `invokeTool(string $toolId, array $arguments): array` —
  resolve `$toolId` against the same flattened function index by matching
  descriptor `name`, call the owning tool's `executeFunction($toolId,
  $arguments)`, return `{result, isError}`.
  - Acceptance: unknown id returns `['result' => ['error' => 'Unknown tool:
    {id}'], 'isError' => true]` with no exception; a `\Throwable` from
    `executeFunction()` is caught, logged at error level, and returned in the
    same envelope shape — never re-thrown.
- [x] 1.4 Confirm no `$userId`/`$actingUser`/agent parameter exists anywhere on
  the public signature — `invokeTool()` calls straight through in the ambient
  request/session context (design.md "No impersonation" decision).

## 2. Unit tests

- [x] 2.1 `tests/Unit/Service/Mcp/ToolRegistryFacadeTest.php` — `listTools()`
  with a fake single-function `ToolInterface` mock returns that function's
  descriptor unchanged.
- [x] 2.2 `listTools()` with two registered tools and a whitelist containing
  only one registry id returns only that tool's functions.
- [x] 2.3 `listTools()` with a bridge-shaped mock (single function, `mcpId` key
  present) round-trips the `mcpId` field through untouched.
- [x] 2.4 `invokeTool()` delegates to the correct owning tool's
  `executeFunction()` with the given arguments and returns
  `['result' => <raw return>, 'isError' => false]`.
- [x] 2.5 `invokeTool()` with an unregistered id returns the not-found envelope
  and does not call any tool's `executeFunction()`.
- [x] 2.6 `invokeTool()` catches a `\Throwable` thrown from `executeFunction()`
  and returns `['result' => ['error' => <message>], 'isError' => true]` instead
  of propagating.

## 3. Docs

- [x] 3.1 Add one row to `openspec/platform-capabilities.md`'s AI/MCP section
  pointing at the `ai-mcp` capability spec, describing the facade as OR's
  supported read/invoke surface for cross-app tool-loop consumers.
- [x] 3.2 Add `@spec openspec/specs/ai-mcp/spec.md#req-006` (or the matching
  requirement anchor) to the new class's public methods.

## 4. Quality + verification

- [x] 4.1 Run `composer check:strict` (PHPCS, PHPMD, Psalm, PHPStan); fix any
  pre-existing issues touched by this change.
- [x] 4.2 Run the Hydra mechanical gates (`hydra-gates` skill), including
  gate-27 (`hydra-gate-no-phantom-cross-app-rpc`) against the diff — the new
  class must not itself trip the phantom-RPC denylist.
- [x] 4.3 Run the full PHPUnit suite; confirm no regressions vs the pre-change
  baseline count and report the actual before/after numbers.
