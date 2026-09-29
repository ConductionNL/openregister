# Tasks: ai-agent-limits-screen

## Implementation tasks

### Task 1: Apply the grant on MCP calls
- **spec_ref**: `openspec/changes/ai-agent-limits-screen/specs/agent-tool-governance/spec.md#requirement-req-aglim-001-an-agent-is-held-to-its-limits-on-every-path`
- **files**: `lib/Service/Capability/ToolGrantResolver.php`, `lib/Service/Mcp/`
- **acceptance_criteria**:
  - outside grant refused
  - inside grant runs
- [ ] Implement
- [ ] Test (red first)

### Task 2: Agents screen
- **spec_ref**: `openspec/changes/ai-agent-limits-screen/specs/agent-tool-governance/spec.md#requirement-req-aglim-001-an-agent-is-held-to-its-limits-on-every-path`
- **files**: `src/views/`, `src/manifest.d/`
- **acceptance_criteria**:
  - lists agents
  - edits tools, registers and views
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after, using the real sibling classes and, for any object written into OpenRegister, the real schema fragment and validator.
- `composer check:strict`, `npm run lint`, `format`, `stylelint`, `test:l10n`, `test:l10n:parity`, `check:schema-l10n`, `check:specs` and the hydra gates (hydra-gates@main) once before push.
