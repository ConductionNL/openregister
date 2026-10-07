<?php

/**
 * The caller that anonymises a batch, built last and deliberately timid.
 *
 * 🔴 IT IS THE LAST THING BUILT BECAUSE IT IS THE ONLY THING THAT ACTS WITHOUT
 * ANYBODY WATCHING. Every refusal in {@see AnonymisationRun} exists for this
 * class: a person running one record can read an error and decide, a sweep
 * cannot, so a sweep that resolves an ambiguity by guessing resolves it the
 * same wrong way across every record on the instance before anybody notices.
 *
 * SO IT REFUSES PER RECORD AND CARRIES ON. One record under a legal hold, one
 * already anonymised, one whose profile names a property its schema dropped:
 * each is skipped with its reason recorded, and the rest of the batch runs. The
 * alternative shapes are both worse. Stopping the whole sweep on the first
 * refusal means one held record blocks a retention obligation for everything
 * else. Skipping silently means the reasons never reach anybody and the sweep
 * reports a clean run over records it did not touch.
 *
 * 🔴 AND THE REPORT COUNTS THE SKIPS SEPARATELY FROM THE SUCCESSES. "412
 * anonymised" and "412 anonymised, 9 refused" are different sentences, and a
 * sweep that adds them together says neither. The nine are the ones somebody
 * has to do something about.
 *
 * IT NEVER RETRIES A REFUSAL ON ITS OWN. A refusal is a decision waiting for a
 * person: a hold to be lifted, a schema to be corrected, a salt rotation to be
 * reckoned with. Re-attempting it every night turns a decision into noise, and
 * the record still is not anonymised.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs anonymisation over a batch of candidates, refusing one at a time.
 */
class AnonymisationSweep {

	/**
	 * Wire the sweep.
	 *
	 * @param AnonymisationRun $run    The act, and its refusals.
	 * @param LoggerInterface  $logger Where a refusal is reported.
	 */
	public function __construct(
		private readonly AnonymisationRun $run,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Anonymise every candidate that may be anonymised.
	 *
	 * A candidate is `['uuid' => string, 'payload' => array, 'annotation' =>
	 * array, 'marker' => array, 'hasLegalHold' => bool, 'holdReason' => string,
	 * 'profileName' => string]`, and the caller supplies the targets each record
	 * must reach.
	 *
	 * @param array<int, array<string, mixed>> $candidates The records to consider.
	 * @param callable                         $targetsFor Returns the targets for one candidate.
	 * @param string                           $salt       The instance salt.
	 *
	 * @return array<string, mixed> The report: what was done, and what was refused and why.
	 *
	 * @spec openspec/changes/anonymising-as-an-archival-outcome/specs/retention-management/spec.md
	 */
	public function run(array $candidates, callable $targetsFor, string $salt): array {
		$fingerprint = $this->run->fingerprint(salt: $salt);
		$anonymised = [];
		$refused = [];

		foreach ($candidates as $candidate) {
			$uuid = (string)($candidate['uuid'] ?? '');
			if ($uuid === '') {
				// A candidate with no identity cannot be reported on afterwards,
				// and an anonymisation nobody can point at is not auditable.
				$refused[] = ['uuid' => '', 'reason' => 'This candidate carries no uuid, so the act could not be recorded against it.'];
				continue;
			}

			$refusal = $this->run->refuse(
				marker: (array)($candidate['marker'] ?? []),
				hasLegalHold: (bool)($candidate['hasLegalHold'] ?? false),
				holdReason: (string)($candidate['holdReason'] ?? ''),
				saltFingerprint: $fingerprint
			);

			if ($refusal !== null) {
				$refused[] = ['uuid' => $uuid, 'reason' => $refusal];
				$this->logger->info('[AnonymisationSweep] Refused', ['object' => $uuid, 'reason' => $refusal]);
				continue;
			}

			try {
				$plan = $this->run->plan(
					objectUuid: $uuid,
					payload: (array)($candidate['payload'] ?? []),
					annotation: (array)($candidate['annotation'] ?? []),
					salt: $salt,
					profileName: (string)($candidate['profileName'] ?? '')
				);

				$marker = $this->run->apply(plan: $plan, targets: $targetsFor($candidate, $plan));
				$anonymised[] = ['uuid' => $uuid, 'marker' => $marker];
			} catch (Throwable $error) {
				// 🔴 ONE RECORD'S FAILURE DOES NOT STOP THE BATCH, AND DOES NOT
				// DISAPPEAR EITHER. It is counted apart and its reason is kept,
				// because a sweep that reports only its successes is the
				// instrument reporting green over the work it did not do.
				$refused[] = ['uuid' => $uuid, 'reason' => $error->getMessage()];
				$this->logger->warning(
					'[AnonymisationSweep] Stopped on one record',
					['object' => $uuid, 'reason' => $error->getMessage()]
				);
			}
		}//end foreach

		return [
			'anonymised' => $anonymised,
			'refused' => $refused,
			'anonymisedCount' => count($anonymised),
			'refusedCount' => count($refused),
		];
	}//end run()
}//end class
