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
