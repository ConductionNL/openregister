<?php

/**
 * OpenRegister destruction list approval
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

/**
 * One approval of a destruction list: the route id, the list data, its status and the approver,
 * with the list edits an approval makes.
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class DestructionListApproval {
	/**
	 * The destruction list id from the route.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The destruction list data as it will be stored.
	 *
	 * @var array
	 */
	public array $listData = [];

	/**
	 * The list status before this approval.
	 *
	 * @var string
	 */
	public string $status = '';

	/**
	 * The approving user id.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * Record one requested exclusion on the list.
	 *
	 * @param mixed $excl One entry of the request's excluded list
	 *
	 * @return string|null The excluded uuid, or null when the entry names none
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function exclude(mixed $excl): ?string {
		$exclUuid = $excl['uuid'] ?? null;
		if ($exclUuid === null) {
			return null;
		}

		$this->listData['excluded'][] = [
			'uuid' => $exclUuid,
			'reason' => $excl['reason'] ?? 'No reason provided',
			'excludedBy' => $this->userId,
		];
		return $exclUuid;
	}//end exclude()

	/**
	 * Drop the list entries with the given uuids, reindexing the rest.
	 *
	 * @param array $uuids The uuids to drop
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function dropEntries(array $uuids): void {
		$this->listData['objects'] = array_values(
			array_filter(
				$this->listData['objects'] ?? [],
				function ($obj) use ($uuids) {
					return in_array($obj['uuid'] ?? '', $uuids, true) === false;
				}
			)
		);
	}//end dropEntries()

	/**
	 * Whether the approving user also gave the first of at least two approvals.
	 *
	 * @return bool True when the approver repeats the first approval
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function repeatsFirstApprover(): bool {
		$approvals = $this->listData['approvals'] ?? [];
		if (count($approvals) < 2) {
			return false;
		}

		return ($approvals[0]['userId'] ?? '') === $this->userId;
	}//end repeatsFirstApprover()
}//end class
