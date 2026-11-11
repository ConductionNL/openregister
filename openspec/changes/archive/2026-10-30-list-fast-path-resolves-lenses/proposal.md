# Proposal: list-fast-path-resolves-lenses

## Why

dossiq DqZaakGerelateerd r37: the Objecten table drew "—" under Object and Object status for every linked object. Those columns are lenses on dossiq's `caseObject` schema (`objectTitle` and `objectStatus`, read through `objectUrl`). The same object read on its own carries them, and so does the service search, but GET /api/objects/{register}/{schema} on a magic-mapped schema takes a fast path that bypasses renderEntity, where lenses are resolved. The list answered the rows without them.

## What changes

RenderObject gains `applyLensesToRows()`, and the three ObjectsController fast paths call it next to the write-only redaction and the translation projection they already apply for the same bypass. A schema without lenses costs nothing. The reader's access to the referenced object is checked as on the single read: an unreadable one answers `{"@withheld": true}`.
