---
kind: code
depends_on: []
---

# Proposal: property-vocabulary-published

## Summary

OpenRegister validates twenty property types and six constraint keys. A
leaf app's property editor offers eight types and no constraints, because
nothing publishes what the layer accepts. Every app then writes its own
shorter list and the gap is invisible until somebody counts. This change
publishes the property vocabulary as a contract, and defines what it means
for a leaf app's own form to declare a property against it.

## Candidates and cluster

Cluster CT-1 of `procest/_round4/discovery/build-plan.md`, from the depth
study `procest/_round4/discovery/casetype-configurability.md`
(ConductionNL/market-intelligence, 2026-09-14): "The field vocabulary is
eight types wide and OpenRegister already has twenty". Rows A1, A2, A3,
A5, A9, A11, A12, A13, B7 and B10 of that study. The study calls it the
cheapest row per hour in the whole exercise: ten rows move on one file.

The study puts the editor and the map on dossiq. This change is the
openregister half: the vocabulary itself, published and validated. Size M.

## Why

The study's own reading, verbatim:

> `propertyDefinition.propertyType` is
> `["string","number","boolean","date","url","email","enum","json"]`. One
> layer down, `PropertyValidatorHandler.php:44-63` allows `string, number,
> integer, boolean, array, object, null, file, geo, color, recurrence,
> NcFile, NcMail, NcContact, NcNote, NcTodo, NcCalendarEvent, NcTalk,
> NcDeck`, plus `pattern`, `minimum`, `maximum`, `format`, `$ref` and
> `items.$ref`.

Ten of the study's rows sit in that gap. A5, a multi-choice answer, is
faked as json or a comma string although the validator takes an array. A9,
a document as the value of a field, is absent although `file` and `NcFile`
are supported. A11, rich text, is absent although the layer has the
`markdown` and `html` formats. A12, geo, is absent although the layer has
`geo`. A13, a reference to another object, is absent although the layer
has `$ref` and `items.$ref`. B7, validation by pattern or range, is
absent although the layer validates all of them. The competitor column
that makes these `must` rows is broad rather than deep: eleven systems in
the study's table A, with xxllnc-zaken, glpi, tuleap, frappe and itop
passing most of the row.

**What exists here and does not close it.** `linked-entity-types` names
the eight `Nc*` types and says they "MUST be added to
`PropertyValidatorHandler::$validTypes`", which is the closest the
repository comes to an inventory, and it covers eight of the twenty.
`schema-property-exploration` infers a shape from stored data, which
answers a different question. `runtime-schema-api` creates and updates
schemas at runtime and never says what a property may contain.

**One finding to write down.** The study names the wall between the two
vocabularies as `schemas.case.properties.caseType.x-openregister-extends-form.map`,
which forwards eight keys. That annotation is in OpenRegister's `x-` namespace
and OpenRegister does not define it: a code search of
`ConductionNL/openregister` for `extends-form` on 2026-09-14 returned zero
hits. A leaf app is carrying an annotation that looks like a platform
contract and is not one. Either it becomes one or it stops using the
namespace, and this change makes it one.

## What changes

- **The vocabulary is published.** One read returns every property type
  the layer accepts, the constraint keys each type takes, the formats each
  type supports, and a sentence per entry. A property editor in any app is
  generated from that read instead of a hand-written list.
- **A declared property is validated against the published vocabulary.**
  A type or a key that is not in the vocabulary fails the schema save
  naming it, so a typo cannot silently become an untyped string.
- **The extending form is a defined contract.** A leaf app that lets an
  administrator author properties declares which vocabulary keys its form
  forwards. The declaration is validated, an unknown key is refused, and
  the forwarded property is validated like any other. An app that forwards
  a subset says so, so the narrowing is visible rather than accidental.
- **The vocabulary carries its own caveats.** Each entry states whether
  changing to it on populated objects is supported, which is where the
  conversion work of `code-list-lifecycle-and-hierarchy` plugs in, and
  which of our own notes already records: adding a `format` to a property
  is breaking.

## Consumers

- **dossiq**: `property-definition-management`, which the study names.
  dossiq widens `propertyDefinition.propertyType` and its forwarding map
  against the published vocabulary rather than against a list somebody
  retyped, and `PropertiesTab.vue` gains an input per forwarded key.
- **opencatalogi, stackiq, humaniq, pipelinq, decidiq**: every app with a
  property editor gets the same list without maintaining one.
- **nextcloud-vue**: one property editor component, generated from the
  vocabulary.

## ADRs

- ADR-022: the property vocabulary is the object layer's, and an app that
  offers a shorter one is narrowing a platform contract.
- ADR-031: what a property may be is declared, and the declaration is the
  same one the validator reads.
- ADR-011: before an app implements a type, it reads what the layer
  already validates. A published vocabulary is what makes that possible.

## Impact

- Extends `runtime-schema-api` with the vocabulary read, the validation of
  a declared property against it, and the extending-form declaration.
- Affected code: `PropertyValidatorHandler` and its valid types, the
  schema save validator, the schema read, and a new discovery endpoint.
- Backwards compatible: every schema that validates today still validates,
  because the vocabulary is the validator's own list published rather than
  a new list imposed.
- Size: M.

## Out of scope

- The dossiq property editor, its enum and its map. That is the consuming
  half and it is where the ten rows actually move.
- Registry-backed property types (study rows A6, A7, A8, B1). Decision D2
  keeps those as a small type plus a declared source, owned by integriq.
- Repeating groups and sub-forms (study row A10). The layer stores an
  array of objects; rendering a repeating group is a form question under
  D16.
- Field order, grouping and layout (study row B16), which is CT-6 and
  belongs to buildiq.
