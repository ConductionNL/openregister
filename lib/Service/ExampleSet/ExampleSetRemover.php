<?php

/**
 * OpenRegister Example Set Remover
 *
 * Deletes exactly what an install of an example set made, as its record says:
 * the objects in reverse order of writing, then the memberships it added, the
 * groups it created and the accounts it created. An adopted account and a
 * group that existed before are left in place. Whatever cannot be deleted
 * stays in the record, so a later remove can try again.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Removes an installed example set.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
class ExampleSetRemover {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetStore    $store    Register access.
	 * @param ExampleSetAccounts $accounts Accounts and groups.
	 * @param ExampleSetRecord   $record   What the install made.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetAccounts $accounts,
		private readonly ExampleSetRecord $record,
	) {
	}//end __construct()

	/**
	 * Remove what the record holds.
	 *
	 * @param string $set The set id.
	 *
	 * @return array{installed: bool, removed: array<string, int>, left: array<int, string>, failed: array<int, string>}
	 *         Whether there was anything to remove, counts per kind, adopted accounts left in place, and what could
	 *         not be removed (still in the record).
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	public function remove(string $set): array {
		$record = $this->record->read(set: $set);
		$report = [
			'installed' => ($this->record->isEmpty(record: $record) === false),
			'removed'   => ['objects' => 0, 'memberships' => 0, 'groups' => 0, 'accounts' => 0],
			'left'      => [],
			'failed'    => [],
		];

		foreach (array_reverse($record['objects'], true) as $name => $held) {
			if ($this->store->delete(register: $held['register'], schema: $held['schema'], id: $held['id']) === false) {
				$report['failed'][] = $held['register'].' '.$held['schema'].' '.$name.' ('.$held['id'].')';
				continue;
			}

			unset($record['objects'][$name]);
			$report['removed']['objects']++;
			$this->record->write(set: $set, record: $record);
		}

		foreach ($record['memberships'] as $index => [$gid, $uid]) {
			if ($this->accounts->removeFromGroup(gid: $gid, uid: $uid) === false) {
				$report['failed'][] = 'membership '.$gid.' / '.$uid;
				continue;
			}

			unset($record['memberships'][$index]);
			$report['removed']['memberships']++;
		}

		$record['memberships'] = array_values($record['memberships']);
		$this->record->write(set: $set, record: $record);

		$record = $this->removeGroupsAndAccounts(set: $set, record: $record, report: $report);
		$this->record->write(set: $set, record: $record);

		return $report;
	}//end remove()

	/**
	 * Delete created groups and created accounts; forget adopted accounts.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return array<string, mixed> The record without what is gone.
	 */
	private function removeGroupsAndAccounts(string $set, array $record, array &$report): array {
		$keptGroups = [];
		foreach ($record['groups'] as $gid) {
			if ($this->accounts->deleteGroup(gid: $gid) === false) {
				$report['failed'][] = 'group '.$gid;
				$keptGroups[]       = $gid;
				continue;
			}

			$report['removed']['groups']++;
		}

		$record['groups'] = $keptGroups;
		$this->record->write(set: $set, record: $record);

		foreach ($record['users'] as $uid => $how) {
			if ($how === 'adopted') {
				$report['left'][] = $uid;
				unset($record['users'][$uid]);
				continue;
			}

			if ($this->accounts->deleteUser(uid: $uid) === false) {
				$report['failed'][] = 'account '.$uid;
				continue;
			}

			unset($record['users'][$uid]);
			$report['removed']['accounts']++;
		}

		return $record;
	}//end removeGroupsAndAccounts()
}//end class
