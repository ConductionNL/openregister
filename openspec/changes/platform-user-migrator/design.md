# Design: platform-user-migrator

## D-1. Personal state leaves, organisational data does not

The test for every item is whose it is. A saved view is the caseworker's
habit; a zaak is the gemeente's record. Exporting the second would make a
leaver's export the easiest bulk extraction in the product.

## D-2. The export says what it did not take

An export that quietly omits the cases looks complete and is not. It
carries a manifest naming what was exported and stating that objects were
not, so nobody discovers the gap on the far side.

## D-3. The import reports what it could not restore

A saved view over a register the new instance has never heard of cannot be
restored, and inventing a register to hold it is worse than saying so. The
import writes a report beside what it restored.

## D-4. Secrets are not migrated

Token metadata travels so a person can see what they had; token values do
not, because a token issued on one instance has no business authenticating
on another.

## D-5. kind

Code, in OpenRegister.
