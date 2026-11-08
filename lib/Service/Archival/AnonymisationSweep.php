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
		$outcome = ['anonymised' => [], 'refused' => []];

		foreach ($candidates as $candidate) {
			$result = $this->one(candidate: $candidate, targetsFor: $targetsFor, salt: $salt, fingerprint: $fingerprint);
			$outcome[$result['kind']][] = $result['entry'];
		}

		return [
			'anonymised' => $outcome['anonymised'],
			'refused' => $outcome['refused'],
			'anonymisedCount' => count($outcome['anonymised']),
			'refusedCount' => count($outcome['refused']),
		];
	}//end run()

	/**
	 * Anonymise one candidate, or say why not.
	 *
	 * @param array<string, mixed> $candidate   The record to consider.
	 * @param callable             $targetsFor  Returns the targets for one candidate.
	 * @param string               $salt        The instance salt.
	 * @param string               $fingerprint The salt's fingerprint.
	 *
	 * @return array{kind: string, entry: array<string, mixed>} `anonymised` or `refused`, and its report entry.
	 */
	private function one(array $candidate, callable $targetsFor, string $salt, string $fingerprint): array {
		$uuid = (string)($candidate['uuid'] ?? '');
		if ($uuid === '') {
			// A candidate with no identity cannot be reported on afterwards,
			// and an anonymisation nobody can point at is not auditable.
			return $this->refused(uuid: '', reason: 'This candidate carries no uuid, so the act could not be recorded against it.');
		}

		$refusal = $this->refusalOf(candidate: $candidate, fingerprint: $fingerprint);
		if ($refusal !== null) {
			$this->logger->info('[AnonymisationSweep] Refused', ['object' => $uuid, 'reason' => $refusal]);
			return $this->refused(uuid: $uuid, reason: $refusal);
		}

		return $this->anonymise(candidate: $candidate, uuid: $uuid, targetsFor: $targetsFor, salt: $salt);
	}//end one()

	/**
	 * Plan and apply one candidate the run did not refuse.
	 *
	 * 🔴 ONE RECORD'S FAILURE DOES NOT STOP THE BATCH, AND DOES NOT DISAPPEAR
	 * EITHER. It is counted apart and its reason is kept, because a sweep that
	 * reports only its successes is the instrument reporting green over the
	 * work it did not do.
	 *
	 * @param array<string, mixed> $candidate  The record.
	 * @param string               $uuid       Its uuid.
	 * @param callable             $targetsFor Returns the targets for one candidate.
	 * @param string               $salt       The instance salt.
	 *
	 * @return array{kind: string, entry: array<string, mixed>} The outcome.
	 */
	private function anonymise(array $candidate, string $uuid, callable $targetsFor, string $salt): array {
		try {
			$plan = $this->planFor(candidate: $candidate, uuid: $uuid, salt: $salt);
			$marker = $this->run->apply(plan: $plan, targets: $targetsFor($candidate, $plan));
			return ['kind' => 'anonymised', 'entry' => ['uuid' => $uuid, 'marker' => $marker]];
		} catch (Throwable $error) {
			$this->logger->warning('[AnonymisationSweep] Stopped on one record', ['object' => $uuid, 'reason' => $error->getMessage()]);
			return $this->refused(uuid: $uuid, reason: $error->getMessage());
		}
	}//end anonymise()

	/**
	 * Why the run refuses this candidate, or null when it may run.
	 *
	 * @param array<string, mixed> $candidate   The record.
	 * @param string               $fingerprint The salt's fingerprint.
	 *
	 * @return string|null The refusal.
	 */
	private function refusalOf(array $candidate, string $fingerprint): string|null {
		return $this->run->refuse(
			marker: (array)($candidate['marker'] ?? []),
			hasLegalHold: (bool)($candidate['hasLegalHold'] ?? false),
			holdReason: (string)($candidate['holdReason'] ?? ''),
			saltFingerprint: $fingerprint
		);
	}//end refusalOf()

	/**
	 * The plan for one candidate.
	 *
	 * @param array<string, mixed> $candidate The record.
	 * @param string               $uuid      Its uuid.
	 * @param string               $salt      The instance salt.
	 *
	 * @return AnonymisationPlan The plan.
	 *
	 * @throws Throwable When the profile is unsound or the plan would change nothing.
	 */
	private function planFor(array $candidate, string $uuid, string $salt): AnonymisationPlan {
		return $this->run->plan(
			objectUuid: $uuid,
			payload: (array)($candidate['payload'] ?? []),
			annotation: (array)($candidate['annotation'] ?? []),
			salt: $salt,
			profileName: (string)($candidate['profileName'] ?? '')
		);
	}//end planFor()

	/**
	 * A refused outcome.
	 *
	 * @param string $uuid   The candidate's uuid.
	 * @param string $reason Why it was refused.
	 *
	 * @return array{kind: string, entry: array{uuid: string, reason: string}} The outcome.
	 */
	private function refused(string $uuid, string $reason): array {
		return ['kind' => 'refused', 'entry' => ['uuid' => $uuid, 'reason' => $reason]];
	}//end refused()
}//end class
