---
status: scaffold
---

# PDF Anonymisation — Path B (ODT fallback)

## Purpose

Reserves the change id for the discovery doc's Path B (NC Office ODT round-trip) fall-through to the v1 `pdf-anonymisation` change. **This is a scaffold only** — no Requirements lock until the proposal lands.

The follow-up activates when:

1. Path A (`pdf-anonymisation`) has been in production at multiple tenants long enough to gather validation-gate failure-rate telemetry, AND
2. The clustered failure modes are ones Path B (a Collabora-backed PDF → ODT → PDF round-trip) would actually resolve (not Path A SAPP-fork iterations like a missing CMap variant, where another SAPP PR is the cleaner fix), AND
3. At least one tenant has Nextcloud Office (Collabora Online or Code) installed and is willing to enable Path B fall-through.

Until those preconditions are met, the v1 fail-closed behaviour stands.

## Anticipated Requirements

To be locked when the proposal lands. Sketched here so the contract is visible:

### Requirement (planned): Path B activates only on Path A strict-mode validation-gate failure

Path B MUST NOT trigger on lenient `replaceWords` calls (docx parity). Path B MUST NOT trigger on `REASON_ENCRYPTED_PDF` (still terminal: encrypted in, encrypted out — fallback doesn't help). Path B MUST NOT trigger on `REASON_TEXT_LAYER_MISSING` (defers to OCR, not ODT).

### Requirement (planned): Path B is gated by tenant configuration

A new config flag (`pdf-anonymisation.path-b-enabled`, default `false`) MUST be set explicitly per-tenant before Path B activates. Tenants without NC Office installed never see the flag honoured.

### Requirement (planned): Path B preserves the v1 success-response shape

When Path B succeeds, the controller response MUST be byte-equivalent to a Path A success response — the file id is the new anonymised PDF, `entities_replaced` reflects the substitution count, and no Path-B-specific surface leaks to the caller (operationally Path B is a fallback; the caller doesn't need to know which path produced the output).

### Requirement (planned): Path B failure is surfaced with a distinct reason

A new exception reason (`REASON_VALIDATION_FAILED_AFTER_FALLBACK`) MUST distinguish "Path A failed AND Path B failed" from "Path A failed (terminal under v1)". Both map to HTTP 500.
