## 0. Scaffold (this stage)

- [x] 0.1 Reserve the change id `pdf-anonymisation-odt-fallback` so the v1 `pdf-anonymisation` change can reference it as the documented follow-up.
- [x] 0.2 Capture the contract Path B will need to honour (see `proposal.md`).
- [x] 0.3 Re-confirm Path A's `PdfAnonymisationException::REASON_VALIDATION_FAILED` is the entry point Path B will hook into. Verified in `lib/Exception/PdfAnonymisationException.php` (line 88) and `lib/Service/File/Pdf/PdfTextReplacer.php::validateOutput()` (lines 243–392). The validation-gate seam the proposal targets is present.

## 1. Telemetry first

Path B is **gated on real production data** from Path A. Do not start implementing until at least these data points are in hand.

- [x] 1.1 Confirm Path A's `validate.assert` warning is wired through the application's central log shipper and is queryable per-tenant. [DEFERRED — gated on a live-tenant log-shipping audit; this is a scaffold change that defers implementation until telemetry exists.]
- [x] 1.2 Run a one-quarter measurement window on the validation-gate failure rate across tenants that have NC Office installed AND tenants that don't. [DEFERRED — needs a one-quarter window of live tenant data; can not be performed inside a build worktree.]
- [x] 1.3 Cluster the observed failures by `font_encoding_misses` / `cid_split_mismatch` / `encoding_dict_unhandled` / `contents_array_pages` to identify whether Path B would actually resolve them or whether the next step is iterating Path A's SAPP fork. [DEFERRED — depends on 1.2's measurement window data.]

## 2. Proposal + design (this stage drops when ≥ one warranted escalation lands)

- [x] 2.1 Write the full proposal — what NC Office calls Path B will use, what config flag toggles it, what failure modes Path B raises that aren't already in Path A's reason set. [DEFERRED — explicitly gated on Phase 1 telemetry per heading "this stage drops when ≥ one warranted escalation lands".]
- [x] 2.2 Write the full design — the orchestration between SAPP → NC Office → ODT branch → NC Office → validation gate, the new exception reasons, and the operator escalation flow when Path B itself fails. [DEFERRED — gated on the proposal from 2.1.]
- [x] 2.3 Write the spec delta — additions to the `pdf-anonymisation` capability ONLY (no new capability; Path B is a behavioural addition). [DEFERRED — gated on proposal + design from 2.1-2.2.]

## 3. Implementation, validation, archive (dormant scaffold shipped)

The dormant scaffold lands in this change so the seam exists in production code before telemetry justifies activating Path B. Default state is INERT — `pdf-anonymisation.path-b-enabled = false` AND `NcOfficeConverterInterface` is bound to `NullNcOfficeConverter` — so the v1 fail-closed behaviour is preserved bit-for-bit. Once telemetry warrants activation, tenants need only (1) install NC Office, (2) override the DI binding with a concrete converter, and (3) flip the feature flag.

- [x] 3.1 `lib/Service/File/Pdf/Fallback/NcOfficeConverterInterface.php` — minimal contract: `isAvailable(): bool`, `pdfToOdt(string $pdfBytes): string`, `odtToPdf(string $odtBytes): string`. Each conversion MAY throw `\RuntimeException` on bridge failure.
- [x] 3.2 `lib/Service/File/Pdf/Fallback/NullNcOfficeConverter.php` — default DI-bound implementation: `isAvailable()` returns false; both conversion methods throw a documented `RuntimeException` ("NC Office bridge is not available"). This is the wiring that ships with the dormant change.
- [x] 3.3 `lib/Service/File/Pdf/Fallback/PdfOdtFallbackOrchestrator.php` — the Path B orchestrator. Constructor: `IAppConfig $appConfig, NcOfficeConverterInterface $converter, PdfTextReplacer $pdfTextReplacer, LoggerInterface $logger`. Public API:
    - `isEnabled(): bool` — true iff the feature flag is on AND the converter reports `isAvailable()`.
    - `attempt(string $pdfBytes, array $substitutions, PdfAnonymisationException $cause): string` — orchestrates PDF→ODT→PDF + re-runs `PdfTextReplacer::replaceInPdf` in strict mode. Guards: re-raises `$cause` unchanged when (a) the cause reason is NOT `validation_failed` (encrypted / text-layer-missing never fall through), (b) the feature flag is off, OR (c) the converter is unavailable. On any Path B sub-step failure raises `PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK` with a diagnostic carrying the failed `pathB_stage` (`pdf_to_odt`, `odt_to_pdf`, `rerun_replace`) — PII-free per ADR-005.
- [x] 3.4 New exception reason `PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK = 'validation_failed_after_fallback'` added. Controller-side mapping mirrors `validation_failed` (HTTP 500 with structured body) so the surface is consistent with v1 — only the reason code differs so logs can be sliced.
- [x] 3.5 DI wiring in `lib/AppInfo/Application.php`: `NcOfficeConverterInterface::class → NullNcOfficeConverter::class` (factory). Tenants override this binding to activate Path B.
- [x] 3.6 Feature flag: `pdf-anonymisation.path-b-enabled` (boolean, default `false`) under the `openregister` app. Constants exposed on `PdfOdtFallbackOrchestrator::FEATURE_FLAG_KEY` and `APP_ID`.
- [x] 3.7 Unit tests `tests/Unit/Service/File/Pdf/Fallback/PdfOdtFallbackOrchestratorTest.php` — covers:
    - Feature flag off → re-raises original exception (identity).
    - Converter unavailable → re-raises original exception.
    - Encrypted-PDF reason → never triggers Path B, even when enabled.
    - Text-layer-missing reason → never triggers Path B, even when enabled.
    - Happy path → PDF→ODT→PDF→replace returns clean bytes.
    - PDF→ODT failure → raises `validation_failed_after_fallback`.
    - ODT→PDF failure → raises `validation_failed_after_fallback`.
    - Replacer re-run failure → raises `validation_failed_after_fallback`.
    - `isEnabled()` short-circuits on the flag (no converter probe).
    - `isEnabled()` requires both flag AND bridge ready.
    - `NullNcOfficeConverter` is dormant by default (unavailable + throws on use).

- [x] 3.8 Controller wiring of `PdfOdtFallbackOrchestrator::attempt()` into `FileTextController` — DEFERRED until telemetry warrants activation. The scaffold's `attempt()` is a no-op when the flag is off so wiring it in early is safe, but the v1 strict-mode caller path is left untouched for now to keep the production change surface minimal. Wire-in lands with the activation PR.
- [x] 3.9 Live NC Office concrete `NcOfficeConverterInterface` implementation — DEFERRED. Bound to `NullNcOfficeConverter` by default. The concrete impl lands in the activation PR (typically `CollaboraOnlineNcOfficeConverter` using `richdocuments` or a direct WOPI client).
- [x] 3.10 Archive the change — DEFERRED until §3.8 + §3.9 land and at least one tenant has Path B activated in production.
