<?php

/**
 * OpenRegister Anonymisation Detector Evaluation Service
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

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\TextEntityDetectorInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * Runs a backend over an evaluation corpus and keeps the figures with the
 * corpus and the detector version they were measured on.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
 */
class DetectorEvaluationService {

	/**
	 * Constructor.
	 *
	 * @param TextEntityDetectorInterface  $recognition The detection the backends share.
	 * @param AnonymisationBackendService  $backends    Names each backend's detector version record.
	 * @param IAppConfig                   $appConfig   Where the evaluations are kept.
	 * @param ITimeFactory                 $time        The moment of a run.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function __construct(
		private readonly TextEntityDetectorInterface $recognition,
		private readonly AnonymisationBackendService $backends,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The backend method a run measures when none is named: the effective one.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function effectiveMethod(): string {
		return $this->backends->getState()->effectiveMethod;
	}//end effectiveMethod()

	/**
	 * Run a backend over a corpus, keep the figures as its latest, and return them.
	 *
	 * @param EvaluationCorpus $corpus The corpus.
	 * @param string           $method The backend method.
	 *
	 * @return DetectorEvaluation
	 *
	 * @throws InvalidArgumentException When the method is not a backend.
	 *
	 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-precision-and-recall-are-measured-over-a-named-corpus-and-published-with-the-detector-version-req-adi-002
	 */
	public function evaluate(EvaluationCorpus $corpus, string $method): DetectorEvaluation {
		$backend = ($this->backends->getState()->backends[$method] ?? null);
		if ($backend === null) {
			throw new InvalidArgumentException('No backend is called "' . $method . '".');
		}

		$detections = [];
		foreach ($corpus->documents as $document) {
			$detections[$document['id']] = $this->recognition->detectInText(text: $document['text'], method: $method);
		}

		$evaluation = DetectorEvaluation::score(
			corpus: $corpus,
			detections: $detections,
			method: $method,
			detector: ($backend->detector ?? new DetectorVersion(fields: [])),
			measuredAt: $this->time->getDateTime()->format(DATE_ATOM)
		);
		(new DetectorEvaluationStore(appConfig: $this->appConfig))->save(evaluation: $evaluation);

		return $evaluation;
	}//end evaluate()
}//end class
