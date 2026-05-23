# Proposal: product-service-catalog

`kind: redirect` — Preserve the spec slug locally while deferring to canonical spec.

## Summary

Establish a redirect stub at `openspec/specs/product-service-catalog/spec.md` that points implementers to the canonical Product & Service Catalog specification owned by the Pipelinq app at `pipelinq/openspec/specs/product-service-catalog/spec.md`. This preserves the spec slug in the local registry while making clear that the normative requirements live elsewhere.

## Motivation

The Product & Service Catalog (PDC) is a cross-app capability that is canonically defined and maintained by Pipelinq. Rather than duplicate the specification or allow drift, this change establishes a local redirect that guides implementers and tools to the authoritative source, preventing confusion and ensuring consistency across the fleet.

## Affected Projects

- [x] Project: This app — one redirect stub spec file in `openspec/specs/product-service-catalog/spec.md`.

## Scope

### In Scope

- One redirect stub spec file (`openspec/specs/product-service-catalog/spec.md`) with clear guidance to the canonical source.
- No implementation, no backend/frontend code, no schema changes.

### Out of Scope

- The canonical PDC specification (owned by Pipelinq).
- Any PDC implementation work.

## Impact

- **Slug preservation** — Tools and documenters can reference `product-service-catalog` in this app without breaking links or creating ambiguity.
- **Zero implementation risk** — No code changes; only a documentation marker.
- **Clear authority chain** — Prevents drift by centralizing the source of truth at Pipelinq.
