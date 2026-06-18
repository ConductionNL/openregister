---
status: done
---

# pdf-anonymisation-odt-fallback Specification

## Purpose
Adds an opt-in fallback (Path B) that retries PDF anonymisation by converting the document through ODT via NC Office when the primary path's strict-mode validation gate fails. It is gated per-tenant by a feature flag (default off) and bridge availability, so the v1 fail-closed behaviour is preserved by default, and it never triggers for encrypted-PDF or text-layer-missing reasons. On failure it raises a PII-free `validation_failed_after_fallback` exception whose diagnostic identifies only the failed stage and exception class.
## Requirements
### Requirement: Path B activates only on Path A strict-mode validation-gate failure

Path B MUST NOT trigger on lenient `replaceWords` calls (docx parity). Path B MUST NOT trigger on `REASON_ENCRYPTED_PDF` (still terminal: encrypted in, encrypted out — fallback doesn't help). Path B MUST NOT trigger on `REASON_TEXT_LAYER_MISSING` (defers to OCR, not ODT).

The orchestrator (`PdfOdtFallbackOrchestrator::attempt()`) enforces these guards centrally. The triggering exception's reason MUST be `REASON_VALIDATION_FAILED` and the feature flag + bridge availability MUST both be true; otherwise the original exception is re-raised unchanged (identity-preserving — `instanceof PdfAnonymisationException` and `getReason()` are stable for the caller).

#### Scenario: Strict-mode validation-gate failure triggers Path B

- **GIVEN** a tenant with `pdf-anonymisation.path-b-enabled` set to `true` and NC Office installed
- **AND** Path A's strict-mode validation gate fails for a given input PDF
- **WHEN** the pipeline raises `PdfAnonymisationException(reason: 'validation_failed')`
- **THEN** the pipeline MUST attempt Path B before surfacing the exception to the caller
- **AND** Path B's success returns the v1 success-response shape (no Path-B-specific surface leaks)
- **AND** Path B's failure raises `PdfAnonymisationException(reason: 'validation_failed_after_fallback')`

#### Scenario: Encrypted-PDF reason bypasses Path B

- **GIVEN** the feature flag is `true` and NC Office is installed
- **WHEN** Path A raises `PdfAnonymisationException(reason: 'encrypted_pdf')`
- **THEN** the orchestrator MUST re-raise the original exception unchanged
- **AND** no NC Office bridge call is attempted

#### Scenario: Text-layer-missing reason bypasses Path B

- **GIVEN** the feature flag is `true` and NC Office is installed
- **WHEN** Path A raises `PdfAnonymisationException(reason: 'text_layer_missing')`
- **THEN** the orchestrator MUST re-raise the original exception unchanged
- **AND** no NC Office bridge call is attempted (OCR is the right route)

### Requirement: Path B is gated by tenant configuration

A new config flag (`pdf-anonymisation.path-b-enabled`, default `false`) MUST be set explicitly per-tenant before Path B activates. Tenants without NC Office installed (the default DI binding is `NullNcOfficeConverter` which always reports `isAvailable() = false`) never see the flag honoured — `isEnabled()` returns `false` even with the flag on until a concrete `NcOfficeConverterInterface` implementation is bound.

#### Scenario: Default behaviour preserves v1 fail-closed

- **GIVEN** a tenant where `pdf-anonymisation.path-b-enabled` is `false` (the default)
- **WHEN** Path A's strict-mode validation gate fails
- **THEN** the pipeline MUST NOT attempt Path B
- **AND** `PdfAnonymisationException(reason: 'validation_failed')` surfaces to the caller (v1 behaviour preserved)

#### Scenario: NC Office unavailable preserves v1 fail-closed

- **GIVEN** the feature flag is `true` but the bound `NcOfficeConverterInterface` reports `isAvailable() = false`
- **WHEN** Path A's strict-mode validation gate fails
- **THEN** the pipeline MUST NOT attempt Path B
- **AND** the original `PdfAnonymisationException(reason: 'validation_failed')` surfaces to the caller

### Requirement: Path B failure surface is observable and PII-free

When Path B activates and any sub-step fails (PDF→ODT conversion, ODT→PDF conversion, or the strict-mode re-replace), the orchestrator MUST raise `PdfAnonymisationException(reason: 'validation_failed_after_fallback')` carrying a diagnostic surface that includes:

- `pathB_stage` — one of `pdf_to_odt`, `odt_to_pdf`, `rerun_replace`.
- `pathA_reason` — the reason code of the triggering Path A exception (always `validation_failed`).
- `pathB_previous` — the failed step's exception class name (e.g. `RuntimeException`, `PdfAnonymisationException`).

Per ADR-005 the diagnostic surface MUST NOT contain operator-supplied entity text or document content; only structural detail (stage label + class name + counts).

#### Scenario: Path B stage failure surfaces stage in diagnostic

- **GIVEN** Path B has been activated and the PDF→ODT conversion step throws
- **WHEN** the orchestrator wraps the failure
- **THEN** the raised exception MUST carry `reason = 'validation_failed_after_fallback'`
- **AND** the diagnostic MUST identify `pathB_stage = 'pdf_to_odt'`
- **AND** the diagnostic MUST NOT contain entity text or document content (PII-free per ADR-005)

