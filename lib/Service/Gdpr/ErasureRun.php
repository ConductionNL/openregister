<?php

/**
 * ErasureRun — the summary of one art-17 erasure while it is decided.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr;

use OCA\OpenRegister\Service\Gdpr\Subject\SubjectRetentionGate;

/**
 * One erasure in progress: the request, and the buckets its candidates land in.
 *
 * Built per call by {@see DataSubjectRequestService::erase()} and never shared.
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
 */
final class ErasureRun {
	/**
	 * The erasure being decided.
	 *
	 * @var ErasureRequest
	 */
	public readonly ErasureRequest $request;

	/**
	 * The summary filled while candidates are decided.
	 *
	 * @var array<string, mixed>
	 */
	private array $summary;

	/**
	 * Open the run with an empty summary.
	 *
	 * @param ErasureRequest $request      The erasure: subject, type filter, normalised mode, dry run.
	 * @param int            $matchedCount Number of objects the index matched.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function __construct(ErasureRequest $request, int $matchedCount) {
		$this->request = $request;
		$this->summary = [
			'subject' => $request->subjectId,
			'type' => $request->type,
			'eraseMode' => $request->eraseMode,
			'dryRun' => $request->dryRun,
			'matchedCount' => $matchedCount,
			'erased' => [],
			SubjectRetentionGate::BUCKET_HELD => [],
			'failed' => [],
			// RETENTION WINS OVER ERASURE: records the Archiefwet keeps are
			// refused, named here with their ground, and reported back with the
			// rest of the answer to the data subject.
			SubjectRetentionGate::BUCKET_WITHHELD => [],
		];
	}//end __construct()

	/**
	 * Put one candidate's row in a bucket.
	 *
	 * @param string               $bucket `erased`, `failed`, `held` or `withheld`.
	 * @param array<string, mixed> $row    The candidate's summary row.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function record(string $bucket, array $row): void {
		$this->summary[$bucket][] = $row;
	}//end record()

	/**
	 * Close the summary: completeness and per-bucket counts.
	 *
	 * A RUN THAT KEPT DATA BACK IS NOT A COMPLETE ERASURE. `complete` used to
	 * mean "nothing errored", so a request that lawfully held every single
	 * record still answered `complete: true` and the data subject was told
	 * their data was gone. Held and withheld rows now count against it too.
	 * The buckets stay separate because the answers differ: `failed` is
	 * retried, `held` waits for the hold to lift, `withheld` is explained.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md#requirement-erasure-honours-legal-hold-and-is-mode-parameterised
	 */
	public function close(): array {
		$summary = $this->summary;
		$summary['complete'] = ($summary['failed'] === []
			&& $summary['held'] === []
			&& $summary['withheld'] === []);
		$summary['failedCount'] = count($summary['failed']);
		$summary['heldCount'] = count($summary['held']);
		$summary['withheldCount'] = count($summary['withheld']);

		return $summary;
	}//end close()
}//end class
