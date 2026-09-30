# Tasks: ai-agent-view-limits

## Implementation tasks

### Task 1: Apply an agent's views in the chat search
- **spec_ref**: `openspec/changes/ai-agent-view-limits/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted`
- **files**: `lib/Service/Chat/ContextRetrievalHandler.php`
- **acceptance_criteria**:
  - an object outside every granted view is not returned as context
- [ ] Implement
- [ ] Test (red first)

### Task 2: Views on the agents screen
- **spec_ref**: `openspec/changes/ai-agent-view-limits/specs/agent-tool-governance/spec.md#requirement-an-agent-reads-only-the-views-it-is-granted`
- **files**: `src/modals/agent/EditAgentLimits.vue`, `src/views/agents/AgentsIndex.vue`
- **acceptance_criteria**:
  - the owner edits and saves an agent's views
- [ ] Implement
- [ ] Test (red first)

## Verification

- Each task has a PHPUnit or jest test that is red before the code and green after.
- `composer check:strict`, the npm checks and the hydra gates (hydra-gates@main) once before push.
