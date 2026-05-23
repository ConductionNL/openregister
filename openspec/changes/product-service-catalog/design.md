# Design: product-service-catalog

## Context

The Product & Service Catalog (PDC) is a capability that spans the Conduction app fleet. It is canonically specified and maintained by the Pipelinq app. To avoid duplication, drift, and confusion, this change establishes a local redirect stub that unambiguously points implementers and tools to the authoritative specification.

## Goals

- Preserve the local `product-service-catalog` spec slug so references in documentation, routing, and tools do not break.
- Make clear to implementers that the normative specification is canonical elsewhere.
- Prevent accidental drift or duplicate-editing of conflicting specs.

## Non-Goals

- Re-implementing or duplicating the PDC specification.
- Any implementation work on PDC itself.
- Changing how other apps refer to or depend on PDC.

## Decisions

### D1. Redirect vs. copy

**Chosen:** Single redirect stub pointing to `pipelinq/openspec/specs/product-service-catalog/spec.md`.

Rationale: Copying the spec into this app introduces drift risk (Pipelinq updates, this app doesn't, or vice versa). A redirect with explicit guidance is unambiguous.

### D2. Redirect placement

**Chosen:** Stub lives at `openspec/specs/product-service-catalog/spec.md` with YAML frontmatter `status: redirect`.

Rationale: Matches the pattern used elsewhere in the fleet for other externally-owned specs. Tools and humans expect to find a spec file at this path; the redirect makes the reason explicit.

### D3. Content of stub

**Chosen:** Minimal content (H1 title, Purpose section, single Requirements section with one scenario).

Rationale: Enough to signal intent without encouraging implementers to treat the stub as authoritative. No feature details, no data models, no requirements — all of which belong in Pipelinq's canonical spec.
