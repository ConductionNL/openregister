## 0. Scaffold (this stage)

- [x] 0.1 Reserve the change id `pdf-anonymisation-odt-fallback` so the v1 `pdf-anonymisation` change can reference it as the documented follow-up.
- [x] 0.2 Capture the contract Path B will need to honour (see `proposal.md`).
- [x] 0.3 Re-confirm Path A's `PdfAnonymisationException::REASON_VALIDATION_FAILED` is the entry point Path B will hook into. Verified in `lib/Exception/PdfAnonymisationException.php` (line 88) and `lib/Service/File/Pdf/PdfTextReplacer.php::validateOutput()` (lines 243–392). The validation-gate seam the proposal targets is present.

## 1. Telemetry first

Path B is **gated on real production data** from Path A. Do not start implementing until at least these data points are in hand.

- [ ] 1.1 Confirm Path A's `validate.assert` warning is wired through the application's central log shipper and is queryable per-tenant.
- [ ] 1.2 Run a one-quarter measurement window on the validation-gate failure rate across tenants that have NC Office installed AND tenants that don't.
- [ ] 1.3 Cluster the observed failures by `font_encoding_misses` / `cid_split_mismatch` / `encoding_dict_unhandled` / `contents_array_pages` to identify whether Path B would actually resolve them or whether the next step is iterating Path A's SAPP fork.

## 2. Proposal + design (this stage drops when ≥ one warranted escalation lands)

- [ ] 2.1 Write the full proposal — what NC Office calls Path B will use, what config flag toggles it, what failure modes Path B raises that aren't already in Path A's reason set.
- [ ] 2.2 Write the full design — the orchestration between SAPP → NC Office → ODT branch → NC Office → validation gate, the new exception reasons, and the operator escalation flow when Path B itself fails.
- [ ] 2.3 Write the spec delta — additions to the `pdf-anonymisation` capability ONLY (no new capability; Path B is a behavioural addition).

## 3. Implementation, validation, archive (deferred)

Filled in when the proposal lands.
