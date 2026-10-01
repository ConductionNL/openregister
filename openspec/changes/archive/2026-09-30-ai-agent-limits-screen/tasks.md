# Tasks: ai-agent-limits-screen

## Implementation tasks

### Task 1: Apply the grant on MCP calls
- **spec_ref**: `openspec/changes/ai-agent-limits-screen/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path`
- **files**: `lib/Service/Mcp/McpAgentScope.php` (new), `lib/Controller/McpServerController.php`; test `tests/Unit/Controller/McpAgentLimitsTest.php`
- **acceptance_criteria**:
  - outside grant refused
  - inside grant runs
- [x] Implement
- [x] Test (red first)

### Task 2: Agents screen
- **spec_ref**: `openspec/changes/ai-agent-limits-screen/specs/agent-tool-governance/spec.md#requirement-an-agent-is-held-to-its-tool-grant-on-every-path`
- **files**: `src/views/agents/AgentsIndex.vue`, `src/modals/agent/EditAgentLimits.vue`, `src/manifest.json`, `src/registry.js`; test `src/views/agents/AgentsIndex.spec.js`
- **acceptance_criteria**:
  - lists agents
  - edits tools (views moved to change ai-agent-view-limits: the chat does not apply an agent's views yet)
- [x] Implement
- [x] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
