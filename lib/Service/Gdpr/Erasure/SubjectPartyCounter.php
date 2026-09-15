<?php

/**
 * SubjectPartyCounter — how many party records inside an object name this subject.
 *
 * A zaak carries its betrokkenen in a list, and "how many of them are this
 * person" is a different question from "how many objects mention them". The
 * erasure preview reports both, so the counting lives here rather than inside
 * the preview: it is a pure walk over a payload with no collaborators, it is
 * the half of the preview that is worth testing on its own, and keeping it out
 * of ErasurePreviewService is what holds that class under the complexity
 * threshold rather than suppressing the warning.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

/**
 * Counts the party records naming one data subject inside an object payload.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
 */
class SubjectPartyCounter {
	/**
	 * The payload keys that hold a list of party records.
	 *
	 * The same list {@see \OCA\OpenRegister\Service\Portal\PortalPartyResolver}
	 * reads, so "party record" means one thing in this app.
	 *
	 * @var array<int, string>
	 */
	private const PARTY_LIST_KEYS = ['rollen', 'roles', 'parties', 'betrokkenen'];

	/**
	 * The subject's lower-cased values: the id plus every matched PII value.
	 *
	 * Public because the co-subject probe compares against the same set, and
	 * two definitions of "this subject's values" would drift.
	 *
	 * @param string            $subjectId The subject value.
	 * @param array<int, array> $matched   The PII hits.
	 *
	 * @return array<int, string> The needles, deduplicated.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function needles(string $subjectId, array $matched): array {
		$needles = [];
		if (trim($subjectId) !== '') {
			$needles[] = strtolower(trim($subjectId));
		}

		foreach ($matched as $hit) {
			$value = strtolower(trim((string)($hit['value'] ?? '')));
			if ($value !== '') {
				$needles[] = $value;
			}
		}

		return array_values(array_unique($needles));
	}//end needles()

	/**
	 * Count the party records in a payload that name the subject.
	 *
	 * A party record counts when any scalar in that entry is one of the
	 * subject's known values, which is the same match the pseudonymiser makes,
	 * so the count and the act agree.
	 *
	 * @param array<mixed>       $payload The object payload.
	 * @param array<int, string> $needles Lower-cased subject values.
	 *
	 * @return int The number of party records naming the subject.
	 *
	 * @spec openspec/changes/data-subject-rights-across-the-instance/specs/gdpr-data-subject-rights/spec.md
	 */
	public function count(array $payload, array $needles): int {
		if ($needles === []) {
			return 0;
		}

		$count = 0;
		foreach ($payload as $key => $value) {
			if (is_array($value) === false) {
				continue;
			}

			if (in_array((string)$key, self::PARTY_LIST_KEYS, true) === true) {
				$count += $this->countInList(parties: $value, needles: $needles);
				continue;
			}

			$count += $this->count(payload: $value, needles: $needles);
		}

		return $count;
	}//end count()

	/**
	 * Count the entries of one party list that name the subject.
	 *
	 * @param array<mixed>       $parties The party list.
	 * @param array<int, string> $needles Lower-cased subject values.
	 *
	 * @return int The matching entries.
	 */
	private function countInList(array $parties, array $needles): int {
		$count = 0;
		foreach ($parties as $party) {
			if (is_array($party) === true && $this->mentions(data: $party, needles: $needles) === true) {
				$count++;
			}
		}

		return $count;
	}//end countInList()

	/**
	 * Whether a sub-payload carries any of the subject's values.
	 *
	 * @param array<mixed>       $data    The sub-payload.
	 * @param array<int, string> $needles Lower-cased subject values.
	 *
	 * @return bool True when it names the subject.
	 */
	private function mentions(array $data, array $needles): bool {
		foreach ($data as $value) {
			if (is_array($value) === true) {
				if ($this->mentions(data: $value, needles: $needles) === true) {
					return true;
				}

				continue;
			}

			if (is_scalar($value) === true
				&& in_array(strtolower((string)$value), $needles, true) === true
			) {
				return true;
			}
		}

		return false;
	}//end mentions()
}//end class
