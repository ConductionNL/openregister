## Why

The shipped `pdf-anonymisation` change implements Path A from the discovery doc — SAPP byte-replace with strict-mode fail-closed validation. The discovery's recommendation was a two-tier architecture with Path A first and a Nextcloud Office (Collabora) ODT round-trip as Path B fall-through whenever Path A's validation gate fails (encoding edge cases, exotic fonts, image-only mixed content, etc.).

Path A is now in operators' hands. Once we have measured fall-through-rate data from real Woo PDFs (the validation-gate `validate.assert` log line counts in production), we can decide whether Path B is worth the operational cost (NC Office dependency) and which Path A failures it would actually rescue.

This scaffold reserves the change id and captures the contract the follow-up will need to honour — it is **not yet a proposal**. The follow-up's full proposal, design, and tasks land when operators escalate at least one validation-gate failure that genuinely warrants a fallback (≥ one real production case from a tenant where Path A cannot complete and the file is still inside the GDPR-anonymisation deadline window).

This change is explicitly NOT in the v1 `pdf-anonymisation` scope (see that change's proposal §2 and design §D6).

## What Changes

To be detailed when the proposal lands. Anticipated surface:

- **NEW path** — `PdfAnonymisationException(reason: 'validation_failed')` in **strict mode** is no longer a hard terminal failure. Before raising to the caller, the pipeline:
  1. Saves the input PDF to a temp file.
  2. Calls Nextcloud Office (Collabora Online) to convert PDF → ODT via the existing NC Office REST endpoint.
  3. Runs the ODT branch's existing PHPWord + sanitiser path (`OfficeDocumentSanitizer` → `DocxSanitizer`/`OdtSanitizer` strategy) on the converted document.
  4. Calls NC Office again to convert ODT → PDF.
  5. Re-runs the validation gate on the resulting PDF.
  6. Only raises `PdfAnonymisationException(reason: 'validation_failed_after_fallback')` if Path B also fails.
- **NEW dependency** on Nextcloud Office (Collabora Online or Code) being installed and reachable from the OpenRegister container. This is the cost the v1 change explicitly deferred.
- **NEW config flag** — `pdf-anonymisation.path-b-enabled` (default `false` for tenants without NC Office). Path B never triggers unless explicitly enabled.

## Impact

- **Depends on:** the v1 `pdf-anonymisation` validation-gate API (`PdfTextReplacer::validateOutput`).
- **Affects:** strict-mode anonymisation only. Lenient `replaceWords` is unaffected.
- **Operational dependency:** NC Office must be installed on the tenant. The v1 change explicitly avoided this dependency.
- **Telemetry prerequisite:** at least one quarter of production data on Path A validation-gate failures (`validate.assert` warnings) so we can answer:
  - Is the failure rate non-zero?
  - Are the failures concentrated in specific font/encoding combinations that Path B would actually resolve, or are they validation-gate false positives?
  - Is the operational cost of NC Office justified by the rescue rate?
