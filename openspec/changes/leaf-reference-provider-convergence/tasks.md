# Tasks: leaf-reference-provider-convergence

## Decision (this change — no code)

- [ ] Human review of the decision: coexist with a documented boundary; reject
      convergence; do not build a bridge now.
- [ ] Confirm the capability comparison table in `design.md` matches the merged
      leaf contracts (`LeafDescriptor`, `IntegrationProvider` `app-local`,
      `RegisterLeafProvidersEvent`) and the current `IReferenceProvider` surface.
- [ ] Confirm the author-facing boundary rule reads cleanly for leaf authors.

## Follow-up (tracked, not landed by this change)

- [ ] Amend ADR-066 decision #6 from *deferred* to *decided: coexist* with the
      status note drafted in `design.md` (hydra change; human governance review).
- [ ] Add the boundary rule to the leaf-authoring docs
      (`openregister/docs/Integrations/leaf-system.md`) so authors see it at the
      point of choice.
- [ ] If and only if a concrete driver appears (an app with an existing
      `IReferenceProvider` wanting a single-entity OR widget for free), open a
      separate change to spec the bridge adapter, scoped strictly to single-entity
      read-only render. Not in scope here.

## Validation

- [ ] `openspec validate leaf-reference-provider-convergence --strict` passes.
