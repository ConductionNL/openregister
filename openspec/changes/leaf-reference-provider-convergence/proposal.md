# Proposal: leaf-reference-provider-convergence

## Summary

Resolve the open question ADR-041 deferred and ADR-066 re-narrowed: how the
OpenRegister cross-app **leaf** mechanism (the `RegisterLeafProvidersEvent`
collect-event, `LeafDescriptor`, the `app-local` storage strategy on
`IntegrationProvider`) relates to Nextcloud's native
`OCP\Collaboration\Reference\IReferenceProvider`, which is cross-app-by-design
for read-only references.

This is an **investigation and decision** change. It ships **no code**. Its
deliverable is a decision — recorded in `design.md` with a capability-comparison
table and rationale — plus a small normative boundary rule so the two
mechanisms do not become two ways to do one thing, and a follow-up note for
ADR-066.

**Decision: COEXIST with a documented boundary.** App-local read leaves do
**not** converge onto `IReferenceProvider`. The two mechanisms answer different
questions and stay separate, divided by an author-facing rule. A narrow
**bridge** (surfacing an existing `IReferenceProvider` as a leaf's single-entity
render) is recorded as a possible *future* option, explicitly **not adopted**
here because no driver needs it and it would add a second registration path.

## Why now

ADR-041 drew the collect-vs-command line and, in the same breath, left one
adjacent question open:

> Cross-app rendering of linked things SHOULD align with NC's native
> `IReferenceProvider` (cross-app by design) rather than growing a bespoke
> cross-app provider-contribution mechanism on the OR registry. Whether and how
> it converges with `IReferenceProvider` is deferred to a dedicated
> investigation.

ADR-066 then lifted the contribution moratorium narrowly (render-surface +
`app-local` data-provider leaves via `RegisterLeafProvidersEvent`) but
explicitly kept the convergence question open (decision #6) and listed it as
still-owed under *What the user should weigh*:

> IReferenceProvider convergence is still owed. This ADR narrows but does not
> answer it. The longer OR carries a bespoke read-only cross-app render layer
> alongside NC's native one, the more the two can drift; a follow-up
> investigation remains warranted.

With the leaf implementation now merged (openregister PR#2102 line, `e596e46`),
the concrete shapes exist to compare, so the question can be answered rather
than carried. This change is that dedicated investigation.

## What changes

- **No code.** No leaf, provider, capability, or reference-provider code is
  added or modified.
- **A decision** (`design.md`): the capability comparison, the three options
  (converge / coexist / bridge) argued against the actual contracts, the
  recommendation (coexist), and the author-facing boundary rule.
- **A small normative boundary rule** (`specs/leaf-reference-boundary`): a
  clarifying requirement fixing which mechanism authors use for which shape, so
  the deliberate coexistence cannot silently become redundant duplication.
- **An ADR-066 follow-up note** (recommended amendment, described in
  `design.md`): flip decision #6 from *deferred* to *decided: coexist*, keeping
  the bridge as a named-but-unbuilt future.

## Out of scope

- Building a bridge adapter. It is documented as a future option only.
- Migrating any built-in or contributed leaf onto `IReferenceProvider`.
- Any change to gate-27, gate-24, or the leaf registration mechanism.
