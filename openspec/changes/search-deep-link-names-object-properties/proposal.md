# Proposal: search-deep-link-names-object-properties

## Summary

A deep-link template can name any top-level key of the object data, but the unified search formatter only ever handed the registry `@self` plus the uuid, register and schema. So a template like `/apps/dossiq/cases/{case}`, which sends a hit on a case-owned record (a document, a contact moment, a decision) to the case it belongs to, opened with a literal `{case}` in it.

The formatter now also passes the object's own scalar properties, URL-encoded, with `@self` and the resolved ids merged over them. A link whose placeholder the object cannot fill falls back to OpenRegister's own page instead of a broken URL.

## Why

Dossiq's `unified-search-opens-every-hit-in-dossiq` tasks 2.1 and 2.2: 47 case-owned schemas stay searchable but open OpenRegister's raw object page until this lands (decision 156: build the dependency in the other repo).

## What changes

- `ObjectSearchResultFormatter::format()` builds the link through `resolveObjectUrl()`, which merges `ownLinkProperties()` (declared, scalar, not `@self`, not `_`-prefixed, URL-encoded) under `@self` and the ids.
- An unfilled `{placeholder}` in the resolved link falls back to `openregister.objects.show`.

## Out of scope

`DeepLinkRegistration::resolveUrl()` is unchanged. Notification and smart-picker links build their own object data and are not touched.
