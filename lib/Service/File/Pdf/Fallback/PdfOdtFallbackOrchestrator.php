<?php

/**
 * PdfOdtFallbackOrchestrator
 *
 * Dormant implementation of the v1 `pdf-anonymisation`'s Path B fallback —
 * the NC Office (Collabora) ODT round-trip rescue path described in
 * `openspec/changes/pdf-anonymisation-odt-fallback/proposal.md`.
 *
 * **Gated by `pdf-anonymisation.path-b-enabled` (default false).** When the
 * flag is off (the default state shipping with this change) the orchestrator
 * is wireable but inert — every `attempt()` short-circuits and re-raises the
 * original Path A `validation_failed` exception unchanged. v1 fail-closed
 * behaviour is preserved.
 *
 * Activation path (when telemetry warrants it, per the scaffold proposal):
 *
 *   1. Operator installs Nextcloud Office (Collabora Online or Code).
 *   2. Operator binds a concrete {@see NcOfficeConverterInterface} (replacing
 *      the default {@see NullNcOfficeConverter}).
 *   3. Operator sets `pdf-anonymisation.path-b-enabled` to `true`.
 *   4. Path A's strict-mode `validation_failed` exceptions are caught by
 *      the FileTextController wrapper and routed through this orchestrator:
 *      PDF → ODT (NC Office) → entity-replace via {@see PdfTextReplacer}
 *      (which is also the ODT branch's existing replacer) → ODT → PDF
 *      (NC Office) → re-run Path A validation gate.
 *   5. Success returns the v1 success-response shape. Failure raises
 *      `REASON_VALIDATION_FAILED_AFTER_FALLBACK`.
 *
 * Per the scaffold proposal Path B MUST NOT trigger on:
 *   - Lenient `replaceWords` calls (docx parity).
 *   - `REASON_ENCRYPTED_PDF` (still terminal — fallback won't help).
 *   - `REASON_TEXT_LAYER_MISSING` (defers to OCR, not ODT).
 *
 * The orchestrator enforces these guards centrally so the controller wiring
 * cannot accidentally widen the trigger surface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Pdf\Fallback;

use OCA\OpenRegister\Exception\PdfAnonymisationException;
use OCA\OpenRegister\Service\File\Pdf\PdfTextReplacer;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dormant orchestrator for the Path B PDF anonymisation fallback.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */
class PdfOdtFallbackOrchestrator {

	/**
	 * App-config key gating the dormant feature.
	 *
	 * Default `false`. When `true` AND the NC Office bridge is available,
	 * Path B activates on Path A `validation_failed`.
	 *
	 * @var string
	 */
	public const FEATURE_FLAG_KEY = 'pdf-anonymisation.path-b-enabled';

	/**
	 * App-id the feature flag lives under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App-config carrying the feature flag.
	 * @param NcOfficeConverterInterface $converter NC Office bridge (Null by default).
	 * @param PdfTextReplacer $pdfTextReplacer The Path A replacer (re-used for the ODT-derived PDF).
	 * @param LoggerInterface $logger PII-free logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly NcOfficeConverterInterface $converter,
		private readonly PdfTextReplacer $pdfTextReplacer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the dormant feature is enabled AND the NC Office bridge reports
	 * itself reachable.
	 *
	 * The controller wiring SHOULD short-circuit on `false` and surface the
	 * original Path A exception unchanged — both for performance (no NC Office
	 * round-trip) and to make the dormant state observable in CI / staging.
	 *
	 * @return bool True only when both the flag and the bridge are ready.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function isEnabled(): bool {
		$flag = $this->appConfig->getValueBool(
			app: self::APP_ID,
			key: self::FEATURE_FLAG_KEY,
			default: false
		);

		if ($flag === false) {
			return false;
		}

		return $this->converter->isAvailable();
	}//end isEnabled()

	/**
	 * Attempt the Path B rescue for a Path A `validation_failed` exception.
	 *
	 * Re-raises the ORIGINAL exception unchanged when:
	 *
	 *  - the feature flag is disabled (the default state shipping with this change), OR
	 *  - the NC Office bridge is unavailable, OR
	 *  - the triggering exception's reason is anything OTHER than
	 *    {@see PdfAnonymisationException::REASON_VALIDATION_FAILED}.
	 *
	 * Performs the PDF → ODT → entity-replace → ODT → PDF round-trip when
	 * activated. Raises {@see PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK}
	 * if Path B itself fails (any sub-step throws OR the re-run validation
	 * gate also rejects the output).
	 *
	 * @param string $pdfBytes The original input PDF bytes.
	 * @param array<string, string> $substitutions The substitution map applied by Path A.
	 * @param PdfAnonymisationException $cause The Path A exception that triggered the fallback.
	 *
	 * @return string Anonymised PDF bytes from Path B.
	 *
	 * @throws PdfAnonymisationException Either re-raises $cause (dormant /
	 *                                   inapplicable) or raises
	 *                                   REASON_VALIDATION_FAILED_AFTER_FALLBACK.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function attempt(string $pdfBytes, array $substitutions, PdfAnonymisationException $cause): string {
		// Guard: never trigger Path B on encrypted-in or text-layer-missing.
		if ($cause->getReason() !== PdfAnonymisationException::REASON_VALIDATION_FAILED) {
			throw $cause;
		}

		// Guard: dormant + feature flag off + null bridge.
		if ($this->isEnabled() === false) {
			$this->logger->debug(
				'[PdfOdtFallbackOrchestrator] Path B dormant; re-raising Path A validation_failed',
				['feature_flag' => self::FEATURE_FLAG_KEY]
			);
			throw $cause;
		}

		$this->logger->info(
			'[PdfOdtFallbackOrchestrator] Path A validation_failed — attempting Path B ODT round-trip',
			['inputBytes' => strlen($pdfBytes)]
		);

		try {
			$odt = $this->converter->pdfToOdt(pdfBytes: $pdfBytes);
		} catch (Throwable $e) {
			throw $this->wrapAfterFallback(cause: $cause, stage: 'pdf_to_odt', previous: $e);
		}

		// Re-run the SAPP-side replacer against the round-tripped PDF. The
		// ODT->PDF conversion happens NEXT — we deliberately do not invoke
		// an ODT entity-walker here, because the Path B contract is that the
		// ODT-PDF re-roundtrip MUST defeat the same encoding edge case that
		// tripped Path A, so feeding the round-tripped PDF back through the
		// SAPP replacer is the equivalent assertion.
		try {
			$pdf = $this->converter->odtToPdf(odtBytes: $odt);
		} catch (Throwable $e) {
			throw $this->wrapAfterFallback(cause: $cause, stage: 'odt_to_pdf', previous: $e);
		}

		try {
			$output = $this->pdfTextReplacer->replaceInPdf(
				pdfBytes: $pdf,
				substitutions: $substitutions,
				strict: true
			);
		} catch (PdfAnonymisationException $e) {
			throw $this->wrapAfterFallback(cause: $cause, stage: 'rerun_replace', previous: $e);
		}

		$this->logger->info(
			'[PdfOdtFallbackOrchestrator] Path B succeeded',
			['outputBytes' => strlen($output)]
		);

		return $output;
	}//end attempt()

	/**
	 * Wrap a Path-B-side failure with the after-fallback reason code.
	 *
	 * @param PdfAnonymisationException $cause The original Path A exception (preserved as previous).
	 * @param string $stage Which Path B step failed (`pdf_to_odt`, `odt_to_pdf`, `rerun_replace`).
	 * @param Throwable $previous The Path-B-side exception.
	 *
	 * @return PdfAnonymisationException A new exception carrying
	 *                                   REASON_VALIDATION_FAILED_AFTER_FALLBACK.
	 */
	private function wrapAfterFallback(
		PdfAnonymisationException $cause,
		string $stage,
		Throwable $previous,
	): PdfAnonymisationException {
		$diagnostic = [
			'pathB_stage' => $stage,
			'pathA_reason' => $cause->getReason(),
			'pathB_previous' => $previous::class,
		];

		return new PdfAnonymisationException(
			reason: PdfAnonymisationException::REASON_VALIDATION_FAILED_AFTER_FALLBACK,
			message: sprintf('Path B fallback failed at stage %s', $stage),
			diagnostic: $diagnostic,
			previous: $previous
		);
	}//end wrapAfterFallback()
}//end class
