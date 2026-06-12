# Design — retrofit-2026-05-25-fe-sidebars

## Context

Retrofit of the `fe-sidebars` coverage cluster: 149 uncovered methods across eight `src/sidebars/**/*.vue` files. Sidebars are predominantly presentation surfaces (tab switching, filter pickers, statistics display) that drive backend capabilities already specified elsewhere. The two genuinely novel surfaces are saved views and search-trail analytics.

## Decisions

### Annotate-existing over mint
Most sidebar methods are the frontend half of a backend capability. Rather than mint UI-mirror REQs, they cross-reference the owning capability through this ghost change's tasks:
- Conversation handlers → `chat-ai` (REQ-002 lifecycle).
- Register/schema cascade + route-query serialisation → `files-sidebar-tabs` (the prior 2026-05-24 retrofit already minted the canonical REQs for exactly these patterns; we reuse them).
- Facet UI → `faceting-configuration`. Search execution → `zoeken-filteren`.
- Register/schema edit modals → `entity-management-modals`. OAS export → `openapi-generation`. Stats display → `built-in-dashboards` (local redirect stub).

### Mint only for novel territory (3 REQs, ≤3 cap)
- `saved-search-views` (new capability, 2 REQs): named view CRUD + favorite/default/activation. No prior spec describes the `/api/views` UI contract.
- `zoeken-filteren` +1 REQ: search-trail analytics dashboard. The canonical search-trail REQ covers persistence and explicitly notes analytics reporting is unspecified; this closes the read/display gap.

### Exclude presentation/plumbing
Date/byte formatters, breakdown formatters, tab-switch state, fire-and-forget `mounted` loaders that only call already-annotated methods, and scanner-captured computed getter/setter/watch-handler nodes (`get`, `set`, `handler`) carry `@spec exclude <reason>` — never a bare exclude.

## Risks
- The `built-in-dashboards` local spec is a redirect stub; stats methods cross-reference it as their nominal owner. The canonical dashboard spec lives in the root openspec.
- Observed drift (debug `console.info`, the `OC.getCurrentUser` favoriting TODO) is captured as-is, not corrected.
