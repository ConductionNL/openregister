# PDF Anonymisation — checked-in fixtures

This directory holds pinned PDF fixtures consumed by
`tests/Integration/Pdf/AnonymisationFlowTest.php`.

## Fixture-checkin guard

The integration test enumerates `*.pdf` files in this directory and, for
each, runs the full `PdfTextReplacer` pipeline (SAPP byte-replace + smalot
re-extract validation gate).

When the directory is empty (the historical state — see
`openspec/changes/pdf-anonymisation/tasks.md` §9.3 — the deferral note) the
test skips cleanly with a documented `markTestSkipped` message. Dropping a
fixture into the directory auto-promotes the deferred test to a live
assertion on the next CI run.

## File layout

```
tests/fixtures/pdf-anonymisation/
├── README.md                       <-- this file
├── <slug>.pdf                      <-- the fixture
└── <slug>.expected.json            <-- sidecar (optional but recommended)
```

The sidecar carries the substitution map + the must-be-absent assertion list:

```json
{
  "substitutions": {
    "Jan Jansen": "[PERSON: 7]"
  },
  "must_be_absent": [
    "Jan Jansen",
    "Janssen"
  ]
}
```

`substitutions` is forwarded to `PdfTextReplacer::replaceInPdf()`.
`must_be_absent` is asserted (case-insensitive `mb_stripos`) against the
smalot re-extraction of the anonymised bytes. PASS = none of the needles
appear; FAIL = any needle survives.

## Newman / Postman counterpart

A Postman collection lives alongside this fixture directory at
`tests/postman/openregister-anonymisation-tests.postman_collection.json` —
it drives `POST /api/files/{fileId}/anonymize` against PDF inputs and shares
the same fixture-checkin guard. Both the PHPUnit and Newman flows promote
themselves once a fixture lands.

## Why no fixture is committed yet

The v1 `pdf-anonymisation` change shipped on a SAPP fork branch
(`Conduction/sapp:work/text-replacement`). The fixture-checkin transition
is paired with the upstream-tag SAPP pin transition tracked in #69 — at
which point a deterministic, license-clean fixture (synthesised, not a
real Woo PDF) lands here and the deferred §9.3 / §9.4 tasks tick to `[x]`.

## Adding a fixture

1. Drop the `.pdf` + `.expected.json` into this directory.
2. Run `composer test:integration` (PHPUnit) or
   `newman run tests/postman/openregister-anonymisation-tests.postman_collection.json`.
3. Confirm both runs are green; commit the fixture.
4. Tick the matching tasks.md checkbox.
