<?php

/**
 * OpenRegister Anonymisation Detector Evaluation Store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Anonymisation
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */


declare(strict_types=1);

namespace OCA\OpenRegister\Service\Anonymisation;

use OCP\IAppConfig;

/**
 * The latest evaluation of each backend, kept in the app config.
 *
 * One evaluation per backend method: a new run replaces the last, and whether
 * it still describes the backend is decided when it is read, against the
 * backend's current detector version record.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
final class DetectorEvaluationStore {

	/**
	 * The app config key prefix; the backend method follows it.
	 */
	public const CONFIG_PREFIX = 'anonymisation_evaluation_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Where the evaluations are kept.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Keep an evaluation as its backend's latest.
	 *
	 * @param DetectorEvaluation $evaluation The evaluation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function save(DetectorEvaluation $evaluation): void {
		$encoded = (string) json_encode($evaluation, JSON_PRESERVE_ZERO_FRACTION);
		$this->appConfig->setValueString('openregister', self::CONFIG_PREFIX . $evaluation->method, $encoded);
	}//end save()

	/**
	 * A backend's latest evaluation, or null when it was never measured.
	 *
	 * @param string $method The backend method.
	 *
	 * @return DetectorEvaluation|null
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function latest(string $method): ?DetectorEvaluation {
		$stored = $this->appConfig->getValueString('openregister', self::CONFIG_PREFIX . $method, '');

		return DetectorEvaluation::fromStored(stored: json_decode($stored, true));
	}//end latest()

	/**
	 * What backend-state reports for a backend: its latest evaluation against
	 * its current record, or null when it was never measured.
	 *
	 * @param string          $method  The backend method.
	 * @param DetectorVersion $current The backend's current record.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function reportFor(string $method, DetectorVersion $current): ?array {
		return $this->latest(method: $method)?->reportFor(current: $current);
	}//end reportFor()
}//end class
