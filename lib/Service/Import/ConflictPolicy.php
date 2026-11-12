<?php

/**
 * The conflict policy an import declares, and the decision it reaches.
 *
 * Upsert is an opinion, not a law: it is the right answer for a monthly
 * correction and the wrong one for a first migration, where an unexpected
 * match means the key is wrong rather than that the record is already there
 * (D-2). So an import says which of four policies it runs under, and this
 * class is the one place that turns a policy plus a match count into a
 * decision.
 *
 * Two rules hold whatever the policy. A row matching more than one existing
 * object is refused, naming the candidates, because picking the first match
 * silently merges two records (D-3). And an import that declares no policy
 * keeps the upsert behaviour the import path had before this change, so no
 * caller breaks by not knowing about policies.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Import;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreviewRow;

/**
 * The four conflict policies, and the decision each reaches.
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */
final class ConflictPolicy implements ConflictPolicyNames {

	/**
	 * Resolve a declared policy, falling back to the default.
	 *
	 * @param string|null $policy The policy the caller declared, if any.
	 *
	 * @return string The policy to run under.
	 *
	 * @throws InvalidArgumentException When the policy is named but unknown.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function resolve(?string $policy): string {
		if ($policy === null || $policy === '') {
			return self::DEFAULT_POLICY;
		}

		if (in_array($policy, self::POLICIES, true) === false) {
			throw new InvalidArgumentException(
				'Unknown conflict policy "'.$policy.'". Declare one of: '.implode(', ', self::POLICIES).'.'
			);
		}

		return $policy;
	}//end resolve()

	/**
	 * Decide what one row does, given the policy and the objects its match
	 * key hit.
	 *
	 * @param string $policy The resolved policy.
	 * @param array<int, string> $candidates The uuids the match key hit.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null} The decision.
	 *
	 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
	 */
	public static function decide(string $policy, array $candidates): array {
		$candidates = array_values($candidates);
		$matchCount = count($candidates);

		// D-3: ambiguity is never resolved by guessing, under any policy.
		if ($matchCount > 1) {
			return [
				'decision' => ImportPreviewRow::DECISION_REFUSE,
				'reason' => 'The match key hits more than one object: '.implode(', ', $candidates)
					.'. Narrow the key or correct the data; nothing was written for this row.',
				'targetUuid' => null,
			];
		}

		if ($matchCount === 0) {
			return self::decideUnmatched(policy: $policy);
		}

		return self::decideMatched(policy: $policy, candidate: $candidates[0]);
	}//end decide()

	/**
	 * The decision for a row whose match key hit nothing.
	 *
	 * @param string $policy The resolved policy.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null} The decision.
	 */
	private static function decideUnmatched(string $policy): array {
		if ($policy === self::UPDATE_ONLY) {
			return [
				'decision' => ImportPreviewRow::DECISION_SKIP,
				'reason' => 'Policy update-only, and the match key hits no existing object.',
				'targetUuid' => null,
			];
		}

		return [
			'decision' => ImportPreviewRow::DECISION_CREATE,
			'reason' => null,
			'targetUuid' => null,
		];
	}//end decideUnmatched()

	/**
	 * The decision for a row whose match key hit exactly one object.
	 *
	 * @param string $policy The resolved policy.
	 * @param string $candidate The uuid it hit.
	 *
	 * @return array{decision: string, reason: string|null, targetUuid: string|null} The decision.
	 */
	private static function decideMatched(string $policy, string $candidate): array {
		if ($policy === self::CREATE_ONLY || $policy === self::REFUSE_ON_CONFLICT) {
			return self::refusedMatch(policy: $policy, candidate: $candidate);
		}

		return [
			'decision' => ImportPreviewRow::DECISION_UPDATE,
			'reason' => null,
			'targetUuid' => $candidate,
		];
	}//end decideMatched()

	/**
	 * The refusal of a row whose match key hits an existing object under a policy that does not update.
	 *
	 * @param string $policy    The policy: create-only or refuse-on-conflict.
	 * @param string $candidate The uuid of the existing object.
	 *
	 * @return array{decision: string, reason: string, targetUuid: null} The decision.
	 */
	private static function refusedMatch(string $policy, string $candidate): array {
		return [
			'decision' => ImportPreviewRow::DECISION_REFUSE,
			'reason' => 'Policy '.$policy.', and the match key hits existing object '.$candidate.'.',
			'targetUuid' => null,
		];
	}//end refusedMatch()
}//end class
