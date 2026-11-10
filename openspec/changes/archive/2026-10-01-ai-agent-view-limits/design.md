# Design: ai-agent-view-limits

Read at openregister development `574a0f35f4`.

## What exists

| Piece | Where |
|---|---|
| View filter list, computed and not applied | `lib/Service/Chat/ContextRetrievalHandler.php` (the TODO in `retrieveContext`) |
| Agents screen (tools only) | `src/views/agents/AgentsIndex.vue`, `src/modals/agent/EditAgentLimits.vue` |

## Approach

1. Resolve each granted view to its query and pass it as a filter to the object search the chat runs; red test first with a real view and two objects, one inside and one outside.
2. Add the Views select to the limits modal, saving `views` with `tools`.

## Tests

- PHPUnit: an agent granted one view gets context only from objects inside it.
- jest: the limits modal saves tools and views.
