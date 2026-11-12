# Design: repeating-groups-and-recorded-corrections

## D-1. A repeating group is a property kind, not a second schema

Modelling meerdere gemachtigden as their own schema makes them objects
somebody has to find, permission and relate. As a property kind they stay
part of the record they belong to, validate with it, and version with it.
Parties are the exception and have their own model, because a party
outlives the case.

## D-2. A violation names the position

"One of the percelen is invalid" is not a message anybody can act on. The
validator reports the index and the member property, which is the whole
difference between an authorable repeating group and an array.

## D-3. A correction is not an update

Every municipality corrects a mis-registered case, and an auditor's
question is which changes were corrections. Making it a distinct verb with
a required reason answers that without reading every diff, and it also
lets an organisation grant correction narrowly.

## D-4. Not supplied is not empty

An empty field says nobody filled it in. A field marked not supplied, with
an administered reason, says somebody decided. It also stops the
validation dance where a required field gets "onbekend" typed into it,
which is the failure this prevents.

## D-5. Aggregation defaults to off

OpenProject merges consecutive edits by default, which is convenient and
loses the order. An audit trail that merges is answering a different
question than the auditor asked, so the default is no merging and the
merged entry says how many edits it covers when a window is set.

## D-6. File metadata is one form, one entry per file

Tidying a dossier means renaming six files. Six round trips is why nobody
does it. One form and one audit entry per file changed keeps the record
honest and the act practical.

## D-7. kind

Code, in OpenRegister.
