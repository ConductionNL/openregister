# Design: import-preview-and-conflict-policy

## D-1. The preview writes nothing, and the write uses the preview

A preview produced by a different code path than the write is a preview
that can be wrong. The preview runs the import in a mode that decides
every row and commits none, keeps those decisions, and the write applies
them. A file that changed in between is refused, because the decisions no
longer describe it.

## D-2. A conflict policy is declared, because upsert is an opinion

Upsert is the right answer for a monthly correction and the wrong one for
a first migration, where an unexpected match means the key is wrong. Four
policies cover the corpus: create only, update only, upsert, refuse on
conflict. The import says which, and the preview counts against it.

## D-3. A row matching two objects is refused, never resolved

Picking the first match silently merges two records. The row is refused,
naming both candidates, and the operator fixes the key or the data. This
is the same rule `dedup-check-before-create` applies at create time.

## D-4. The copy before destruction is a precondition, not a side effect

An archivaris signs a verklaring of vernietiging. A destruction that
happened and a copy that failed is exactly what they cannot sign. The copy
is written first, its location is recorded with the destruction, and a
failed copy stops the destruction.

## D-5. The instance serialisation excludes secrets and says so

A portable instance file carrying credentials is a breach in a zip. The
serialisation excludes them, the file records that it did, and the load
asks for them again. Silence here would be read as "there were none".

## D-6. Mapping and preview are bulk jobs

A municipal migration file is not small. Running the mapping preview and
the load through `bulk-action-jobs` gives progress, skips and a per-row
outcome for free, and keeps one long-running mechanism in the platform.

## D-7. kind

Code, in OpenRegister.
