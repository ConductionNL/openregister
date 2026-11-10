# Design — retrofit-2026-05-24-2b-small-misc

**Retrofit change. Tasks describe retroactive triage outcomes, not new implementation work.**

## Why an all-DROP retrofit still gets a change record

The retrofit playbook's `--bundle` mode batches very-small directory clusters into a single triage pass. Bundling assumes most entries will DROP — that's the point: avoid creating one PR per trivial helper, but still leave a written justification so the coverage report doesn't keep re-surfacing the same plumbing.

If a `--bundle` pass produces 0 REQs (as here), the change still exists as a paper trail: a future re-scan or spec audit can read `proposal.md` and see *why* each method was skipped, without having to re-derive the triage.

## DROP categories used

1. **Pure DTO** — value object whose public surface mirrors its fields by definition (`DeletionAnalysis::__construct`, `empty`, `toArray`).
2. **Vue plumbing wrapper** — one-line `this.$router.push(path)` / `this.$root.$emit(...)` re-emit / `fetch().then(...)` style helpers (`handleNavigate`, `onConfigSetCreated`).
3. **Static configuration data** — exported constant misclassified as a method by the symbol scanner (`routeKeyByPath`).
4. **DI constructor** — Nextcloud autowiring boilerplate; the owning class's behavioral methods carry the spec (`AgentTool::__construct`).
5. **Internal helper of an already-specced public method** — class-level `@spec` already covers the public behavior; the private helper is implementation detail (`StreamingToolInstanceWrapper::detectIsError`).
6. **Suspected dead code** — flagged for cleanup follow-up rather than spec-locking unwanted behavior (`Configuration.vue::fetchData` — calls opencatalogi endpoint from openregister; component not registered in the current router).

## Trade-offs

- Could have minted a `dto-conventions` capability covering "every DTO has constructor / empty / toArray" — but that's a coding convention, not user-observable behavior. Belongs in an ADR if anywhere.
- Could have minted a `vue-navigation-helpers` capability — same issue, it would just enumerate the wrapper pattern. The actual navigation behavior is the route table, already implicit in router-level specs.
- Could have specified the suspected-dead-code `Configuration.vue` — explicitly rejected per the playbook's "Observed, not aspirational. Bugs stay bugs; TODO notes surface them" guardrail. Dead code is worse than bug code; specifying it would lock in an unwanted cross-app fetch.

## Follow-ups

- **Cleanup follow-up** (not part of this change): investigate whether `src/navigation/Configuration.vue` is reachable from any current route/menu. If not, delete the file. If yes, document what it's for and either fix the endpoint or migrate to an openregister-native equivalent.
