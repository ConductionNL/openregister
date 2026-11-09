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

use Closure;

/**
 * Removes an installed example set.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-exactly-what-the-install-made-req-exs-007
 */
class ExampleSetRemover implements ExampleSetRemoverInterface {
	/**
	 * What a remove counts, by kind, in report order.
	 */
	private const REMOVED = [
		'files'            => 0,
		'objects'          => 0,
		'changes'          => 0,
		'app config'       => 0,
		'user preferences' => 0,
		'tasks'            => 0,
		'views'            => 0,
		'watchers'         => 0,
		'parties'          => 0,
		'notes'            => 0,
		'readStates'       => 0,
		'flows'            => 0,
		'memberships'      => 0,
		'groups'           => 0,
		'accounts'         => 0,
		'register imports' => 0,
		'organisations'    => 0,
	];

	/**
	 * Constructor.
	 *
	 * @param ExampleSetRemoverObjects   $objects    Register access, the record, files and changes.
	 * @param ExampleSetRemoverSettings  $settings   App config, user preferences, register imports and organisations.
	 * @param ExampleSetAccounts         $accounts   Accounts and groups.
	 * @param ExampleSetEngineKinds|null $engineRows Deletes the tasks and saved views the set wrote; null names them as failed.
	 * @param ExampleSetLock|null        $lock       One install or removal of a set at a time; null holds nothing.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetRemoverObjects $objects,
		private readonly ExampleSetRemoverSettings $settings,
		private readonly ExampleSetAccounts $accounts,
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
		$record = $this->objects->record->read(set: $set);
		$report = ['installed' => ($this->objects->record->isEmpty(record: $record) === false), 'removed' => self::REMOVED, 'left' => [], 'failed' => []];

		// First: a task is on an object the set wrote, and goes before it.
		($this->engineRows ?? new ExampleSetEngineKinds())->delete(
			record: $record,
			report: $report,
			written: fn (array $now) => $this->objects->record->write(set: $set, record: $now)
		);

		$record = $this->deleteObjects(set: $set, record: $record, report: $report);
		$record = $this->revertChanges(set: $set, record: $record, report: $report);
		$record = $this->restoreAppConfig(set: $set, record: $record, report: $report);
		// Before the accounts: an account the set created goes, an adopted one keeps its own values.
		$record = $this->restoreUserPreferences(set: $set, record: $record, report: $report);
		$record = $this->removeMemberships(set: $set, record: $record, report: $report);

		return $this->removeAccountsAndLast(set: $set, record: $record, report: $report);
	}//end removeHeld()

	/**
	 * Delete the groups and accounts, then (last: the set's own objects may point into a register it imported, and
	 * may belong to an organisation it created) the register imports and the organisations.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts, accounts left and failures are added.
	 *
	 * @return array{installed: bool, removed: array<string, int>, left: array<int, string>, failed: array<int, string>}
	 *         The report.
	 */
	private function removeAccountsAndLast(string $set, array $record, array $report): array {
		$accounts = array_map('strval', array_keys($record['users']));
		$record   = $this->removeGroupsAndAccounts(set: $set, record: $record, report: $report);
		$this->objects->record->write(set: $set, record: $record);

		$record = $this->removeImports(set: $set, record: $record, report: $report);
		$this->removeOrganisations(set: $set, record: $record, report: $report, accounts: $accounts);

		return $report;
	}//end removeAccountsAndLast()

	/**
	 * Delete the objects the set wrote, newest first, each with its files; one refused stays in the record.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return array<string, mixed> The record without the deleted objects.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	private function deleteObjects(string $set, array $record, array &$report): array {
		foreach (array_reverse($record['objects'], true) as $name => $held) {
			$why = $this->deleteObject(set: $set, name: $name, held: $held, record: $record, report: $report);
			if ($why !== '') {
				$report['failed'][] = $held['register'].' '.$held['schema'].' '.$name.' ('.$held['id'].'): '.$why;
				continue;
			}

			unset($record['objects'][$name]);
			$report['removed']['objects']++;
			$this->objects->record->write(set: $set, record: $record);
		}

		return $record;
	}//end deleteObjects()

	/**
	 * Delete one object the set wrote, its files first; why it stays, or ''.
	 *
	 * @param string               $set    The set id.
	 * @param string               $name   The object's record key.
	 * @param array<string, mixed> $held   The record entry: register, schema, id.
	 * @param array<string, mixed> $record The record; loses the deleted files.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return string
	 */
	private function deleteObject(string $set, string $name, array $held, array &$record, array &$report): string {
		if ($this->deleteFiles(set: $set, name: $name, objectId: (string) $held['id'], record: $record, report: $report) === false) {
			return 'a file of it stays';
		}

		return $this->objects->store->deleteRecorded(register: $held['register'], schema: $held['schema'], id: $held['id']);
	}//end deleteObject()

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
			if ($this->reverted(name: (string) $name, entry: $entry, report: $report) === false) {
				continue;
			}

			unset($record['changes'][$name]);
			$report['removed']['changes']++;
			$this->objects->record->write(set: $set, record: $record);
		}

		return $record;
	}//end revertChanges()

	/**
	 * Revert one recorded change; false when it could not be (named under `failed`). A path somebody changed after
	 * the install is left and named under `left`.
	 *
	 * @param string               $name   The change's record key.
	 * @param array<string, mixed> $entry  The record entry.
	 * @param array<string, mixed> $report The report.
	 *
	 * @return bool
	 */
	private function reverted(string $name, array $entry, array &$report): bool {
		$reverted = $this->objects->changes->revert(entry: $entry);
		if ($reverted['ok'] === false) {
			$report['failed'][] = $this->changeOn(name: $name, entry: $entry);
			return false;
		}

		foreach ($reverted['left'] as $path) {
			$report['left'][] = $this->changeOn(name: $name, entry: $entry).', path '.$path.', which somebody changed after the install';
		}

		return true;
	}//end reverted()

	/**
	 * A recorded change as the report names it.
	 *
	 * @param string               $name  The change's record key.
	 * @param array<string, mixed> $entry The record entry: register, schema, id.
	 *
	 * @return string
	 */
	private function changeOn(string $name, array $entry): string {
		return 'change '.$name.' on '.$entry['register'].' '.$entry['schema'].' ('.$entry['id'].')';
	}//end changeOn()

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
			// A slug whose jobs are all gone stays with no jobs: the register and its schemas remain on the instance, but
			// the data the import made does not, and the next install must import it again.
			$record['registers'][$slug] = $this->removeJobs(slug: (string) $slug, jobs: $jobs, report: $report);

			$this->objects->record->write(set: $set, record: $record);
		}

		return $record;
	}//end removeImports()

	/**
	 * Remove the import jobs of one register; the jobs that stay.
	 *
	 * @param string                           $slug   The register's slug.
	 * @param array<int, array<string, mixed>> $jobs   The import jobs the set made.
	 * @param array<string, mixed>             $report The report; counts and failures are added.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function removeJobs(string $slug, array $jobs, array &$report): array {
		$left = [];
		foreach ($jobs as $job) {
			if ($this->settings->registers->removeImport(appId: (string) $job['appId'], jobId: (string) $job['jobId']) === false) {
				$report['failed'][] = 'register '.$slug.' import job '.$job['jobId'];
				$left[]             = $job;
				continue;
			}

			$report['removed']['register imports']++;
		}

		return $left;
	}//end removeJobs()

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
		$left = $this->deleteFilesOf(name: $name, objectId: $objectId, files: ($record['files'][$name] ?? []), report: $report);
		unset($record['files'][$name]);
		if ($left !== []) {
			$record['files'][$name] = $left;
		}

		$this->objects->record->write(set: $set, record: $record);

		return $left === [];
	}//end deleteFiles()

	/**
	 * Delete the files of one object; the files that stay.
	 *
	 * @param string                           $name     The object's record key.
	 * @param string                           $objectId The object's id.
	 * @param array<int, array<string, mixed>> $files    The files the set attached.
	 * @param array<string, mixed>             $report   The report; counts and failures are added.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function deleteFilesOf(string $name, string $objectId, array $files, array &$report): array {
		$left = [];
		foreach ($files as $file) {
			$why = $this->objects->files->delete(fileId: (string) $file['id'], name: (string) $file['name'], objectId: $objectId);
			if ($why !== '') {
				$report['failed'][] = 'file '.$file['name'].' on '.$name.': '.$why;
				$left[]             = $file;
				continue;
			}

			$report['removed']['files']++;
		}

		return $left;
	}//end deleteFilesOf()

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
		$run = [
			'set'    => $set,
			'part'   => 'appConfig',
			'count'  => 'app config',
			'revert' => fn (array $entry): string => $this->settings->appConfig->revert(entry: $entry),
			'label'  => static fn (string $name, array $entry): string => 'app config '.$entry['app'].' '.$entry['key'],
		];

		return $this->restore(run: $run, record: $record, report: $report);
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
		$run = [
			'set'    => $set,
			'part'   => 'userPreferences',
			'count'  => 'user preferences',
			'revert' => fn (array $entry): string => $this->settings->userPreferences->revert(entry: $entry),
			'label'  => static fn (string $name, array $entry): string => 'user preference '.$name,
		];

		return $this->restore(run: $run, record: $record, report: $report);
	}//end restoreUserPreferences()

	/**
	 * Restore the entries of one part the set wrote, newest first: an entry that still holds what the set wrote is
	 * put back, one somebody changed since is left (and named), one that cannot be restored stays in the record.
	 *
	 * @param array{set: string, part: string, count: string, revert: Closure, label: Closure} $run    The set, the
	 *        record part, the report count, how an entry is reverted (`removed`, `left` or `failed`) and how it is named.
	 * @param array<string, mixed>                                                            $record The record.
	 * @param array<string, mixed>                                                            $report The report.
	 *
	 * @return array<string, mixed> The record without the restored entries.
	 */
	private function restore(array $run, array $record, array &$report): array {
		foreach (array_reverse($record[$run['part']], true) as $name => $entry) {
			if ($this->restored(run: $run, name: (string) $name, entry: $entry, report: $report) === false) {
				continue;
			}

			unset($record[$run['part']][$name]);
			$report['removed'][$run['count']]++;
			$this->objects->record->write(set: $run['set'], record: $record);
		}

		return $record;
	}//end restore()

	/**
	 * Restore one entry; false when it could not be (named under `failed`). One somebody changed since is left as it
	 * is and named under `left`.
	 *
	 * @param array{set: string, part: string, count: string, revert: Closure, label: Closure} $run    As restore() takes it.
	 * @param string                                                                          $name   The entry's record key.
	 * @param array<string, mixed>                                                            $entry  The record entry.
	 * @param array<string, mixed>                                                            $report The report.
	 *
	 * @return bool
	 */
	private function restored(array $run, string $name, array $entry, array &$report): bool {
		$outcome = ($run['revert'])($entry);
		if ($outcome === 'failed') {
			$report['failed'][] = ($run['label'])($name, $entry);
			return false;
		}

		if ($outcome === 'left') {
			$report['left'][] = ($run['label'])($name, $entry).', which somebody changed after the install';
		}

		return true;
	}//end restored()

	/**
	 * Take the sub-admins and the members the set added out of their groups, and keep the record.
	 *
	 * @param string               $set    The set id.
	 * @param array<string, mixed> $record The record.
	 * @param array<string, mixed> $report The report; counts and failures are added.
	 *
	 * @return array<string, mixed> The record without what was taken out.
	 */
	private function removeMemberships(string $set, array $record, array &$report): array {
		$removal               = new ExampleSetAccountsRemoval(accounts: $this->accounts);
		$record['subAdmins']   = $removal->removeSubAdmins(pairs: $record['subAdmins'], report: $report);
		$record['memberships'] = $removal->removeMembers(pairs: $record['memberships'], report: $report);
		$this->objects->record->write(set: $set, record: $record);

		return $record;
	}//end removeMemberships()

	/**
	 * Delete the OpenRegister organisations the set created.
	 *
	 * @param string               $set      The set id.
	 * @param array<string, mixed> $record   The record.
	 * @param array<string, mixed> $report   The report; counts, organisations kept and failures are added.
	 * @param array<int, string>   $accounts The accounts the record held before remove deleted them; they, and any
	 *                                       member that is no account any more, do not keep an organisation.
	 *
	 * @return void
	 */
	private function removeOrganisations(string $set, array $record, array &$report, array $accounts): void {
		$left = [];
		foreach ($record['organisations'] as $uuid) {
			if ($this->keepsOrganisation(uuid: $uuid, accounts: $accounts, report: $report) === true) {
				$left[] = $uuid;
			}
		}

		$record['organisations'] = $left;
		$this->objects->record->write(set: $set, record: $record);
	}//end removeOrganisations()

	/**
	 * Delete one organisation the set created; true when it stays (it could not be deleted, or has members the set
	 * did not add), which the report names.
	 *
	 * @param string             $uuid     The organisation's uuid.
	 * @param array<int, string> $accounts The accounts the record held before remove deleted them.
	 * @param array<string, mixed> $report The report; counts, organisations kept and failures are added.
	 *
	 * @return bool
	 */
	private function keepsOrganisation(string $uuid, array $accounts, array &$report): bool {
		$outcome = $this->settings->instance->delete(uuid: $uuid, ignorable: $this->ignorable(accounts: $accounts));
		if ($outcome === ExampleSetInstance::FAILED) {
			$report['failed'][] = 'OpenRegister organisation '.$uuid;
			return true;
		}

		if ($outcome === ExampleSetInstance::LEFT) {
			$report['left'][] = 'OpenRegister organisation '.$uuid.', which has members the set did not add';
			return true;
		}

		$report['removed']['organisations']++;

		return false;
	}//end keepsOrganisation()

	/**
	 * Which members do not keep an organisation: the accounts the record held, and any member that is no account
	 * any more.
	 *
	 * @param array<int, string> $accounts The accounts the record held before remove deleted them.
	 *
	 * @return Closure(string): bool
	 */
	private function ignorable(array $accounts): Closure {
		return fn (string $member): bool => (
			in_array($member, $accounts, true) === true || $this->accounts->userExists(uid: $member) === false
		);
	}//end ignorable()

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
		$removal              = new ExampleSetAccountsRemoval(accounts: $this->accounts);
		$record['groupNames'] = $removal->restoreGroupNames(names: $record['groupNames']);
		$record['groups']     = $removal->deleteGroups(groups: $record['groups'], report: $report);
		$this->objects->record->write(set: $set, record: $record);

		$record['users'] = $removal->removeUsers(users: $record['users'], report: $report);

		return $record;
	}//end removeGroupsAndAccounts()

}//end class
