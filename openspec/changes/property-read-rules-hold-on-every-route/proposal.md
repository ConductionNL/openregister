## Why

A property read rule (`authorization.read` on a schema property) stripped the
property from the object body and nowhere else. Lane oc-pub found three routes on
the Rotterdam stack where an anonymous caller still read the values of stackiq
properties ruled `authorization.read: ["authenticated"]`:

1. `@self.relations`: the reference mirror keeps every reference-shaped scalar.
   `RenderObject` stripped only write-only keys from it, never rule-withheld ones
   (a contact person's uuid and two document references).
2. `@self.description` (and the other metadata copies): `objectDescriptionField`
   copies a property into the object's metadata at save time, so `longDescription`
   came back as `@self.description`.
3. An explicit facet: `_facets[<prop>][type]=terms` returned the column's
   distinct values. `MagicFacetHandler::callerMayFacet()` guarded only the
   auto-discovered `facetable` loop.

This is a data exposure: fields a schema marks non-public reached anonymous
callers on every published object.

## What Changes

- `RenderObject`: whatever the read filter removes from the body is removed from
  its copies by the same decision: the matching `@self.relations` keys (the
  property and dotted paths under it) and the `@self` name, description, summary
  or image whose source field it is. Applied on the single-object render and both
  list paths (entity rows and array rows).
- `MagicFacetHandler`: an explicitly requested facet asks `callerMayFacet()`
  like the auto-discovered ones, on the single-table path and the union path (a
  union facet is omitted when any of its schemas withholds the property).

## Impact

- Callers a rule admits see no change. Write-only handling is unchanged.
- No schema or API change.
