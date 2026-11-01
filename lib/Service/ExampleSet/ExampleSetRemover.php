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
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Removes an installed example set.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The constructor takes one collaborator per part of a set, as the installer's does.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
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
	 * @param ExampleSetUserPreferences $userPreferences Restores the user preferences the set wrote.
	 * @param ExampleSetEngineKinds|null $engineRows     Deletes the tasks and saved views the set wrote; null names them as failed.
	 * @param ExampleSetLock|null        $lock           One install or removal of a set at a time; null holds nothing.
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
		private readonly ExampleSetUserPreferences $userPreferences,
		private readonly ?ExampleSetEngineKinds $engineRows=null,
		private readonly ?ExampleSetLock $lock=null,
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	public function remove(string $set): array {
		if ($this->lock !== null && $this->lock->hold(set: $set) === false) {
			return ['installed' => true, 'removed' => [], 'left' => [], 'failed' => [$this->lock->busy(set: $set)]];
		}

		try {
			return $this->removeHeld(set: $set);
		} finally {
			$this->lock?->release(set: $set);
		}
	}//end remove()

	/**
	 * Remove what the record holds, in a run that holds the set.
	 *
	 * @param string $set The set id.
	 *
	 * @return array{installed: bool, removed: array<string, int>, left: array<int, string>, failed: array<int, string>}
	 *         As remove() returns it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
	 */
	private function removeHeld(string $set): array {
		$record = $this->record->read(set: $set);
		$report = [
			'installed' => ($this->record->isEmpty(record: $record) === false),
			'removed'   => [
				'files'             => 0,
				'objects'           => 0,
				'changes'           => 0,
				'app config'        => 0,
				'user preferences'  => 0,
				'tasks'             => 0,
				'views'             => 0,
				'watchers'          => 0,
				'parties'           => 0,
				'notes'             => 0,
				'readStates'        => 0,
				'flows'             => 0,
				'accessLinks'       => 0,
				'grants'            => 0,
				'memberships'       => 0,
				'groups'            => 0,
				'accounts'          => 0,
				'register imports'  => 0,
				'organisations'     => 0,
			],
			'left'      => [],
			'failed'    => [],
		];

		// First: a task is on an object the set wrote, and goes before it.
		($this->engineRows ?? new ExampleSetEngineKinds())->delete(
			record: $record,
			report: $report,
			written: fn (array $now) => $this->record->write(set: $set, record: $now)
		);

		foreach (array_reverse($record['objects'], true) as $name => $held) {
			if ($this->deleteFiles(set: $set, name: $name, objectId: (string) $held['id'], record: $record, report: $report) === false) {
				$report['failed'][] = $held['register'].' '.$held['schema'].' '.$name.' ('.$held['id'].'): a file of it stays';
				continue;
			}

			$why = $this->store->deleteRecorded(register: $held['register'], schema: $held['schema'], id: $held['id']);
			if ($why !== '') {
				$report['failed'][] = $held['register'].' '.$held['schema'].' '.$name.' ('.$held['id'].'): '.$why;
				continue;
			}

			unset($record['objects'][$name]);
			$report['removed']['objects']++;
			$this->record->write(set: $set, record: $record);
		}

		$record = $this->revertChanges(set: $set, record: $record, report: $report);
		$record = $this->restoreAppConfig(set: $set, record: $record, report: $report);
		// Before the accounts: an account the set created goes, an adopted one keeps its own values.
		$record = $this->restoreUserPreferences(set: $set, record: $record, report: $report);

		foreach ($record['subAdmins'] as $index => [$gid, $uid]) {
			if ($this->accounts->removeSubAdmin(gid: $gid, uid: $uid) === false) {
				$report['failed'][] = 'sub-admin '.$gid.' / '.$uid;
				continue;
			}

			unset($record['subAdmins'][$index]);
		}

		$record['subAdmins'] = array_values($record['subAdmins']);

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

		$accounts = array_map('strval', array_keys($record['users']));
		$record = $this->removeGroupsAndAccounts(set: $set, record: $record, report: $report);
		$this->record->write(set: $set, record: $record);

		// Last: the set's own objects may point into a register it imported,
		// and may belong to an organisation it created.
		$record = $this->removeImports(set: $set, record: $record, report: $report);
		$this->removeOrganisations(set: $set, record: $record, report: $report, accounts: $accounts);

		return $report;
	}//end removeHeld()

	/**
	 * Revert the recorded changes, newest first.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts, paths left as somebody changed them, and failures.
	 *
	 * @return array<string, mixed> The record without the reverted changes.
	 */
	private function revertChanges(string $set, array $record, array &$report): array {
		foreach (array_reverse($record['changes'], true) as $name => $entry) {
			$reverted = $this->changes->revert(entry: $entry);
			if ($reverted['ok'] === false) {
				$report['failed'][] = 'change '.$name.' on '.$entry['register'].' '.$entry['schema'].' ('.$entry['id'].')';
				continue;
			}

			foreach ($reverted['left'] as $path) {
				$report['left'][] = 'change '.$name.' on '.$entry['register'].' '.$entry['schema'].' ('.$entry['id'].'), path '.$path
					.', which somebody changed after the install';
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
	 * The register itself and its schemas stay, as they may hold other people's
	 * data; the record keeps the slug without jobs so that an install imports
	 * the register's data again (REQ-EXS-009).
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

			// A slug whose jobs are all gone stays with no jobs: the register and its schemas remain on the instance, but
			// the data the import made does not, and the next install must import it again.
			$record['registers'][$slug] = $left;

			$this->record->write(set: $set, record: $record);
		}//end foreach

		return $record;
	}//end removeImports()

	/**
	 * Delete the files the set attached to one object; false when one stays.
	 *
	 * Every file that stays is named in the report with the reason.
	 *
	 * @param string               $set      The set id.
	 * @param string               $name     The object's record key.
	 * @param string               $objectId The object's id.
	 * @param array<string, mixed> $record   The record; loses the deleted files.
	 * @param array<string, mixed> $report   The report; counts and failures are added.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	private function deleteFiles(string $set, string $name, string $objectId, array &$record, array &$report): bool {
		$left = [];
		foreach (($record['files'][$name] ?? []) as $file) {
			$why = $this->files->delete(fileId: (string) $file['id'], name: (string) $file['name'], objectId: $objectId);
			if ($why !== '') {
				$report['failed'][] = 'file '.$file['name'].' on '.$name.': '.$why;
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
	 * Restore the user preferences the set wrote, newest first, while they still hold what the set wrote.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts, preferences left as somebody changed them, and failures.
	 *
	 * @return array<string, mixed> The record without the restored entries.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function restoreUserPreferences(string $set, array $record, array &$report): array {
		foreach (array_reverse($record['userPreferences'], true) as $name => $entry) {
			$outcome = $this->userPreferences->revert(entry: $entry);
			if ($outcome === 'failed') {
				$report['failed'][] = 'user preference '.$name;
				continue;
			}

			if ($outcome === 'left') {
				$report['left'][] = 'user preference '.$name.', which somebody changed after the install';
			}

			unset($record['userPreferences'][$name]);
			$report['removed']['user preferences']++;
			$this->record->write(set: $set, record: $record);
		}

		return $record;
	}//end restoreUserPreferences()

	/**
	 * Delete the OpenRegister organisations the set created.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts, organisations kept and failures are added.
	 * @param array<int, string>   $accounts The accounts the record held before remove deleted them; they, and any
	 *                                       member that is no account any more, do not keep an organisation.
	 *
	 * @return void
	 */
	private function removeOrganisations(string $set, array $record, array &$report, array $accounts): void {
		$left = [];
		foreach ($record['organisations'] as $uuid) {
			$outcome = $this->instance->delete(
				uuid: $uuid,
				ignorable: fn (string $member): bool => (
					in_array($member, $accounts, true) === true || $this->accounts->userExists(uid: $member) === false
				)
			);
			if ($outcome === ExampleSetInstance::FAILED) {
				$report['failed'][] = 'OpenRegister organisation '.$uuid;
				$left[]             = $uuid;
				continue;
			}

			if ($outcome === ExampleSetInstance::LEFT) {
				$report['left'][] = 'OpenRegister organisation '.$uuid.', which has members the set did not add';
				$left[]           = $uuid;
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
		// A group the set only named gets its old name back, while it still carries the set's.
		foreach ($record['groupNames'] as $gid => $name) {
			if ($this->accounts->groupName(gid: (string) $gid) === (string) $name['new']) {
				$this->accounts->setGroupName(gid: (string) $gid, displayName: (string) $name['old']);
			}

			unset($record['groupNames'][$gid]);
		}

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
