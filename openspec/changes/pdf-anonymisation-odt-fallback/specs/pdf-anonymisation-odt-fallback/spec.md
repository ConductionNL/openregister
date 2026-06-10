---
status: scaffold
---

# PDF Anonymisation — Path B (ODT fallback)

## Purpose

Reserves the change id for the discovery doc's Path B (NC Office ODT round-trip) fall-through to the v1 `pdf-anonymisation` change. **This is a scaffold** — the Requirements below sketch the contract Path B will need to honour when the real proposal lands; they do NOT lock until that proposal lands.

The follow-up activates when:

1. Path A (`pdf-anonymisation`) has been in production at multiple tenants long enough to gather validation-gate failure-rate telemetry, AND
2. The clustered failure modes are ones Path B (a Collabora-backed PDF → ODT → PDF round-trip) would actually resolve (not Path A SAPP-fork iterations like a missing CMap variant, where another SAPP PR is the cleaner fix), AND
3. At least one tenant has Nextcloud Office (Collabora Online or Code) installed and is willing to enable Path B fall-through.

Until those preconditions are met, the v1 fail-closed behaviour stands.

## ADDED Requirements

### Requirement: Path B activates only on Path A strict-mode validation-gate failure (scaffold)

Path B MUST NOT trigger on lenient `replaceWords` calls (docx parity). Path B MUST NOT trigger on `REASON_ENCRYPTED_PDF` (still terminal: encrypted in, encrypted out — fallback doesn't help). Path B MUST NOT trigger on `REASON_TEXT_LAYER_MISSING` (defers to OCR, not ODT).

This Requirement is a **scaffold** — it sketches the contract Path B will need to honour when the real proposal lands. The scenario below is illustrative; it does not lock until the proposal lands.

#### Scenario: Strict-mode validation-gate failure triggers Path B (scaffold)

- **GIVEN** a tenant with `pdf-anonymisation.path-b-enabled` set to `true` and NC Office installed
- **AND** Path A's strict-mode validation gate fails for a given input PDF
- **WHEN** the pipeline raises `PdfAnonymisationException(reason: 'validation_failed')`
- **THEN** the pipeline MUST attempt Path B before surfacing the exception to the caller
- **AND** Path B's success returns the v1 success-response shape (no Path-B-specific surface leaks)
- **AND** Path B's failure raises `PdfAnonymisationException(reason: 'validation_failed_after_fallback')`

### Requirement: Path B is gated by tenant configuration (scaffold)

A new config flag (`pdf-anonymisation.path-b-enabled`, default `false`) MUST be set explicitly per-tenant before Path B activates. Tenants without NC Office installed never see the flag honoured.

This Requirement is a **scaffold** — it sketches the contract Path B will need to honour when the real proposal lands.

#### Scenario: Default behaviour preserves v1 fail-closed (scaffold)

- **GIVEN** a tenant where `pdf-anonymisation.path-b-enabled` is `false` (the default)
- **WHEN** Path A's strict-mode validation gate fails
- **THEN** the pipeline MUST NOT attempt Path B
- **AND** `PdfAnonymisationException(reason: 'validation_failed')` surfaces to the caller (v1 behaviour preserved)
