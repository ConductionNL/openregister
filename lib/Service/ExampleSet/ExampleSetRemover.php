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
	 * @param ExampleSetRecord    $record    What the install made.
	 * @param ExampleSetChanges   $changes   Reverts changes to objects the set did not create.
	 * @param ExampleSetRegisters $registers Removes the register imports the set made.
	 * @param ExampleSetFiles     $files     Deletes the files the set attached.
	 * @param ExampleSetAppConfig $appConfig Restores the app config the set wrote.
	 * @param ExampleSetInstance  $instance  Deletes the OpenRegister organisations the set created.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetAccounts $accounts,
		private readonly ExampleSetRecord $record,
		private readonly ExampleSetChanges $changes,
		private readonly ExampleSetRegisters $registers,
		private readonly ExampleSetFiles $files,
		private readonly ExampleSetAppConfig $appConfig,
		private readonly ExampleSetInstance $instance,
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
			'removed'   => [
				'files'             => 0,
				'objects'           => 0,
				'changes'           => 0,
				'app config'        => 0,
				'memberships'       => 0,
				'groups'            => 0,
				'accounts'          => 0,
				'register imports'  => 0,
				'organisations'     => 0,
			],
			'left'      => [],
			'failed'    => [],
		];

		foreach (array_reverse($record['objects'], true) as $name => $held) {
			if ($this->deleteFiles(set: $set, name: $name, record: $record, report: $report) === false) {
				continue;
			}

			if ($this->store->delete(register: $held['register'], schema: $held['schema'], id: $held['id']) === false) {
				$report['failed'][] = $held['register'].' '.$held['schema'].' '.$name.' ('.$held['id'].')';
				continue;
			}

			unset($record['objects'][$name]);
			$report['removed']['objects']++;
			$this->record->write(set: $set, record: $record);
		}

		$record = $this->revertChanges(set: $set, record: $record, report: $report);
		$record = $this->restoreAppConfig(set: $set, record: $record, report: $report);

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

		// Last: the set's own objects may point into a register it imported,
		// and may belong to an organisation it created.
		$record = $this->removeImports(set: $set, record: $record, report: $report);
		$this->removeOrganisations(set: $set, record: $record, report: $report);

		return $report;
	}//end remove()

	/**
	 * Revert the recorded changes, newest first.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return array<string, mixed> The record without the reverted changes.
	 */
	private function revertChanges(string $set, array $record, array &$report): array {
		foreach (array_reverse($record['changes'], true) as $name => $entry) {
			if ($this->changes->revert(entry: $entry) === false) {
				$report['failed'][] = 'change '.$name.' on '.$entry['register'].' '.$entry['schema'].' ('.$entry['id'].')';
				continue;
			}

			unset($record['changes'][$name]);
			$report['removed']['changes']++;
			$this->record->write(set: $set, record: $record);
		}

		return $record;
	}//end revertChanges()

	/**
	 * Remove the register imports this set made.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return array<string, mixed> The record without the removed imports.
	 */
	private function removeImports(string $set, array $record, array &$report): array {
		foreach ($record['registers'] as $slug => $jobs) {
			$left = [];
			foreach ($jobs as $job) {
				if ($this->registers->removeImport(appId: (string) $job['appId'], jobId: (string) $job['jobId']) === false) {
					$report['failed'][] = 'register '.$slug.' import job '.$job['jobId'];
					$left[]             = $job;
					continue;
				}

				$report['removed']['register imports']++;
			}

			$record['registers'][$slug] = $left;
			if ($left === []) {
				unset($record['registers'][$slug]);
			}

			$this->record->write(set: $set, record: $record);
		}//end foreach

		return $record;
	}//end removeImports()

	/**
	 * Delete the files the set attached to one object; false when one stays.
	 *
	 * @param string               $set    The set id.
	 * @param string               $name   The object's record key.
	 * @param array<string, mixed> $record The record; loses the deleted files.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return bool
	 */
	private function deleteFiles(string $set, string $name, array &$record, array &$report): bool {
		$left = [];
		foreach (($record['files'][$name] ?? []) as $file) {
			if ($this->files->delete(fileId: (string) $file['id']) === false) {
				$report['failed'][] = 'file '.$file['name'].' on '.$name;
				$left[]             = $file;
				continue;
			}

			$report['removed']['files']++;
		}

		unset($record['files'][$name]);
		if ($left !== []) {
			$record['files'][$name] = $left;
		}

		$this->record->write(set: $set, record: $record);

		return $left === [];
	}//end deleteFiles()

	/**
	 * Restore the app config the set wrote, newest first, while it still holds what the set wrote.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts, keys left as somebody changed them, and failures.
	 *
	 * @return array<string, mixed> The record without the restored entries.
	 */
	private function restoreAppConfig(string $set, array $record, array &$report): array {
		foreach (array_reverse($record['appConfig'], true) as $name => $entry) {
			$outcome = $this->appConfig->revert(entry: $entry);
			if ($outcome === 'failed') {
				$report['failed'][] = 'app config '.$entry['app'].' '.$entry['key'];
				continue;
			}

			if ($outcome === 'left') {
				$report['left'][] = 'app config '.$entry['app'].' '.$entry['key'].', which somebody changed after the install';
			}

			unset($record['appConfig'][$name]);
			$report['removed']['app config']++;
			$this->record->write(set: $set, record: $record);
		}

		return $record;
	}//end restoreAppConfig()

	/**
	 * Delete the OpenRegister organisations the set created.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return void
	 */
	private function removeOrganisations(string $set, array $record, array &$report): void {
		$left = [];
		foreach ($record['organisations'] as $uuid) {
			if ($this->instance->delete(uuid: $uuid) === false) {
				$report['failed'][] = 'OpenRegister organisation '.$uuid;
				$left[]             = $uuid;
				continue;
			}

			$report['removed']['organisations']++;
		}

		$record['organisations'] = $left;
		$this->record->write(set: $set, record: $record);
	}//end removeOrganisations()

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
