# object-lenses Specification

## Purpose
A schema can declare lenses: named views onto its objects. Every list row carries the lenses of its schema, also on the fast list path, so a client renders the same lens choices for each row.

## Requirements

### Requirement: A list row carries its schema's lenses

A list read of objects SHALL carry every lens the schema declares, resolved as on a read of the single object, on every list path, including the magic-table fast paths that skip the full render.

#### Scenario: Objects linked to a case
- GIVEN dossiq's caseObject schema declares the lenses objectTitle and objectStatus through objectUrl
- AND case 2026-0110 links Lichtmast 14, whose status is Gepland
- WHEN the case's objects are listed through GET /api/objects/dossiq/caseObject
- THEN the row carries objectTitle "Lichtmast 14" and objectStatus "Gepland"

#### Scenario: A schema without lenses
- GIVEN a schema that declares no lens
- WHEN its objects are listed
- THEN no referenced object is looked up
