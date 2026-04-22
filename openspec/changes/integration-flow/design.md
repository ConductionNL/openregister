# Design: Integration — Flow

> Umbrella decisions apply
>
> **Cross-repo note**: file paths under `nextcloud-vue/src/...` or bare component names (`CnXxxTab`, `CnXxxCard`) are **expected locations** in the `@conduction/nextcloud-vue` shared library, not binding spec. The frontend implementation PR lands in that separate repo and MAY choose different paths..

## Approach

`FlowService` wraps `workflowengine` Manager. Rules are stored in NC Flow's own tables; OR's link table maps rules to schemas/objects for discovery. Fire events are read from NC's event log.

## Architecture Decisions

### AD-1: Scoping via schema, not per-object

**Decision**: Default scope is schema (rule X applies to all objects of schema Y). Per-object rules are discouraged but supported.

**Why**: Flow rules are declarative and static. Most cases are "every incoming zaak triggers notification X" (schema-scoped). Per-object flow rules are edge-case and tend to be mis-designed as data-driven logic.

### AD-2: Coexistence with OR workflow engine

**Decision**: Tab has two sections: "NC Flow rules" (from workflowengine) and "OR workflow rules" (from OR's own engine). Unified display, separate configurations.

**Why**: Both engines exist; pretending one owns the space would confuse users. Unified display clarifies the distinction.

**Trade-off**: Admins must understand both systems. Mitigated by clear labelling.

## Files Affected

### Backend (new)
- `FlowService`, `FlowController`, `FlowLink` entity + mapper + migration, `FlowProvider`, unit tests

### Backend (modified)
- `Application.php`, `routes.php`

### Frontend (new)
- `CnFlowTab/*`, `CnFlowCard/*`, `src/integrations/builtin/flow.js`, barrels + tests

## Risks

| Risk | Mitigation |
|---|---|
| NC Flow event log isn't reliable for "recent events" at scale | Fallback to "last 24h" window; acknowledge limitation |
| Users confuse NC Flow with OR workflow engine | Clear labelling; link documentation explaining when to use which |
| Flow rules fire unexpectedly | OR doesn't change firing logic; this is observation-only |
