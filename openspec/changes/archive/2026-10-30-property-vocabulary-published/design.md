# Design: property-vocabulary-published

## D-1. Publish the validator's list, do not write a second one

The vocabulary is generated from what `PropertyValidatorHandler` accepts,
not maintained beside it. A hand-maintained catalogue drifts from the
validator within one release, and then the published contract is a lie in
the most expensive possible place. The endpoint reads the same source the
save path reads.

## D-2. A narrowing is declared, so it is visible

A leaf app is allowed to offer six types out of twenty. What it may not do
is offer six silently, because then nobody can tell a deliberate product
decision from an oversight that has cost ten capability rows. The app
declares the keys its form forwards, and the declaration is a fact anybody
can read.

## D-3. An unknown key fails the save, in both directions

A type the vocabulary does not hold fails the schema save. A forwarded key
the vocabulary does not hold fails the form declaration. Both name the
offending key. Silence here is how a property becomes an untyped string
that validates nothing for a year.

## D-4. The caveats travel with the entry

"Which conversions are possible" is the question an administrator asks
second, right after "what types are there". Our own notes already record
that adding a `format` to a property is breaking. Publishing that beside
the type is cheaper than publishing it in a wiki nobody reads, and it is
the hook the conversion preview needs.

## D-5. kind

Code, in OpenRegister. The consuming apps read one endpoint and widen
their own editors.

## D-6. The count is nineteen, not twenty

The study says twenty types and the proposal quotes it. The list it quotes
holds nineteen: string, number, integer, boolean, array, object, null, file,
geo, color, recurrence and the eight `Nc*` types. We took the recount rather
than adding a twentieth entry to match a number, because a vocabulary that
invents a type to satisfy a headline is exactly the drift this change exists
to stop. The spec scenario now reads "every type the validator accepts"
instead of a literal count, so the test cannot pass by counting wrong.

The gap the study was measuring is unchanged: a leaf editor offers eight.

## D-7. An unknown key is refused, a vendor extension is not

D-3 refuses a key the vocabulary does not hold. Applied literally that would
refuse `x-openregister-calculations` and every annotation any app has ever
written, because the vocabulary cannot enumerate other people's namespaces.

So a key starting with `x-` passes through. That is the JSON Schema convention
for vendor extensions, it is already how every `x-openregister-*` annotation in
this repository travels, and it means an app can annotate a property without
waiting on a release here. Everything without the prefix has to be a type, a
constraint, a modifier or a named pass-through keyword.

The pass-through set is published with `enforced: false`. Standard JSON Schema
keywords we store and hand on but do not check belong in the contract, marked
as unchecked. A contract that hides which half it enforces is worse than no
contract.

## D-8. The map reads role to field, because the consumer already does

The first draft of this change defined the map the other way round: the app's
field name on the left, the vocabulary key on the right. That was wrong, and
it was wrong in the most expensive direction available, because the consumer
already ships.

`propertiesFromDefinitions` in `@conduction/nextcloud-vue` does
`mapped($record, $map, $role)`, which is `$map[$role]` and then
`$record[$field]`, over a `DEFAULT_MAP` of `title: 'name'`,
`type: 'propertyType'`, `enum: 'enumValues'`. The key is the vocabulary role
this platform owns. The value is the app's own field name, which it does not.
Defined backwards, the validator would have refused five of the six keys
dossiq ships, by name, on save.

The rule this leaves behind: when the platform defines a key a consumer
already uses, the shipped consumer is the authority on its shape, and the
definition is read off the code that reads it, not off the proposal.

`definitions` is required for the same reason: the consumer skips a
declaration without it, so accepting one would store an annotation that reads
as configured and renders no field.

`definition` is declared as a source alias for `description`, not refused.
The consumer reads it as the fallback source for a description, and a shipped
role refused by name is the same breakage as a mis-read map, one release later.

## D-9. `x-openregister-property-source` stays unpublished

dossiq carries `x-openregister-property-source`, and integriq's
`registry-backed-field-source` (integriq#1997) defines it as a thing distinct
from `x-openregister-object-source`. It is not in this vocabulary, and it is
not being added here.

Nothing breaks meanwhile: every key starting with `x-` passes the save as a
vendor extension (D-7), so dossiq's annotation is stored and handed on exactly
as it is today. What it does not do is appear in the published list, which is
correct until the change that owns its meaning lands. Publishing it now would
mean this lane inventing semantics for a key another lane is defining, which
is the drift this whole change exists to stop, and dossiq's contract test
fails the day the key appears.

Whoever lands integriq#1997 adds the entry here, in the same edit.

