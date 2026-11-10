# Design: calculations-resolve-references-regardless-of-saver

## The tenant rule

A reference is read without the session's scope, so the guard decides which
rows may feed a calculation. It mirrors what a member of the saving object's
organisation could read under
`MagicOrganizationHandler::resolveOrganizationScope()`, widened by
`admitOrganisationless()`.

A referenced object is admitted when one of these holds:

1. It has no organisation. Org-less rows belong to no tenant.
2. It is in the saving object's organisation.
3. It is in a parent of that organisation, read with
   `OrganisationMapper::findParentChain()`. That is the chain
   `OrganisationService::getUserActiveOrganisations()` adds for a member.
4. Its register or schema is shared master data held by its organisation and
   shared with the saving organisation or a parent
   (`SharedMasterDataService::holdersForResource()`, REQ-SLE-001).

Everything else is refused. A saving object with no organisation reaches only
org-less rows: it has no tenant from which to claim another tenant's data.

A parent chain that cannot be read narrows the scope to the organisation
itself. A failed lookup may cost a reference. It never widens one.

The guard does not consult the multitenancy setting. With multitenancy off
every object still carries the default organisation, so the rule admits it.

## Resolved, empty, or unresolved

The resolver now tells three outcomes apart:

- **Resolved**: the target was found and admitted.
- **Empty**: there was nothing to resolve. The foreign key is empty, or a
  lookup matched no rows. `@ref.<name>` is null and calculations evaluate
  against it, as before. A cleared foreign key must clear what it fed.
- **Unresolved**: there was something to resolve and it came back empty. The
  target is missing, outside the tenant, or the read threw.

Only the last one skips a calculation. The resolver reports it through
`resolveAllWithOutcome()`; the payload builder carries the list under a
synthetic key that `stripSyntheticKeys()` removes before persisting.

## Detecting which calculations read a reference

`CalculationPayloadBuilder::unresolvedReferencesUsedBy()` walks the JSON-AST
and collects every string starting with `@ref.`. The second path segment is
the reference name. A calculation that reads only resolved references, or
none, runs as before.

## Why a lookup skips foreign rows instead of failing

A `lookup` reads up to 50 rows, sorted. The guard drops rows outside the
tenant and keeps the order, so the most relevant admitted row wins. A lookup
whose every row is refused is unresolved.
