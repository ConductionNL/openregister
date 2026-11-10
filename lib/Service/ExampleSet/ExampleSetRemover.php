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
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

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
	 * Accounts and groups.
	 *
	 * @var ExampleSetAccounts
	 */
	private readonly ExampleSetAccounts $accounts;

	/**
	 * Deletes the tasks and saved views the set wrote; null names them as failed.
	 *
	 * @var ExampleSetEngineKinds|null
	 */
	private readonly ?ExampleSetEngineKinds $engineRows;

	/**
	 * Constructor.
	 *
	 * @param ExampleSetRemoverObjects  $objects  Register access, the record, files and changes.
	 * @param ExampleSetRemoverSettings $settings App config, user preferences, register imports and organisations.
	 * @param ExampleSetRemoverRows     $rows     The accounts and the engine rows.
	 * @param ExampleSetLock|null       $lock     One install or removal of a set at a time; null holds nothing.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetRemoverObjects $objects,
		private readonly ExampleSetRemoverSettings $settings,
		ExampleSetRemoverRows $rows,
		private readonly ?ExampleSetLock $lock=null,
	) {
		$this->accounts   = $rows->accounts;
		$this->engineRows = $rows->engineRows;
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
		$run         = new ExampleSetRemovalRun(set: $set, record: $this->objects->record->read(set: $set));
		$run->report = [
			'installed' => ($this->objects->record->isEmpty(record: $run->record) === false),
			'removed'   => self::REMOVED,
			'left'      => [],
			'failed'    => [],
		];

		// First: a task is on an object the set wrote, and goes before it.
		($this->engineRows ?? new ExampleSetEngineKinds())->delete(
			record: $run->record,
			report: $run->report,
			written: fn (array $now) => $this->objects->record->write(set: $set, record: $now)
		);

		$this->deleteObjects(run: $run);
		$this->revertChanges(run: $run);
		$appConfig = fn (array $entry): string => $this->settings->appConfig->revert(entry: $entry);
		$this->restore(run: $run, part: new ExampleSetRestorePart(part: 'appConfig', revert: $appConfig));
		// Before the accounts: an account the set created goes, an adopted one keeps its own values.
		$preferences = fn (array $entry): string => $this->settings->userPreferences->revert(entry: $entry);
		$this->restore(run: $run, part: new ExampleSetRestorePart(part: 'userPreferences', revert: $preferences));
		$this->removeMemberships(run: $run);
		$this->removeAccountsAndLast(run: $run);

		return $run->report;
	}//end removeHeld()

	/**
	 * Delete the groups and accounts, then (last: the set's own objects may point into a register it imported, and
	 * may belong to an organisation it created) the register imports and the organisations.
	 *
	 * @param ExampleSetRemovalRun $run The remove; its record and report change.
	 *
	 * @return void
	 */
	private function removeAccountsAndLast(ExampleSetRemovalRun $run): void {
		$run->accounts = array_map('strval', array_keys($run->record['users']));
		$this->removeGroupsAndAccounts(run: $run);
		$this->written(run: $run);

		$this->removeImports(run: $run);
		$this->removeOrganisations(run: $run);
	}//end removeAccountsAndLast()

	/**
	 * Write the record as the run holds it now.
	 *
	 * @param ExampleSetRemovalRun $run The remove.
	 *
	 * @return void
	 */
	private function written(ExampleSetRemovalRun $run): void {
		$this->objects->record->write(set: $run->set, record: $run->record);
	}//end written()

	/**
	 * Delete the objects the set wrote, newest first, each with its files; one refused stays in the record.
	 *
	 * @param ExampleSetRemovalRun $run The remove; loses the deleted objects, counts and failures are added.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	private function deleteObjects(ExampleSetRemovalRun $run): void {
		foreach (array_reverse($run->record['objects'], true) as $name => $held) {
			$why = $this->deleteObject(run: $run, held: new ExampleSetRecordedObject(name: (string) $name, entry: $held));
			if ($why !== '') {
				$run->report['failed'][] = $held['register'].' '.$held['schema'].' '.$name.' ('.$held['id'].'): '.$why;
				continue;
			}

			unset($run->record['objects'][$name]);
			$run->report['removed']['objects']++;
			$this->written(run: $run);
		}
	}//end deleteObjects()

	/**
	 * Delete one object the set wrote, its files first; why it stays, or ''.
	 *
	 * @param ExampleSetRemovalRun     $run  The remove; loses the deleted files, counts and failures are added.
	 * @param ExampleSetRecordedObject $held The object's record key and entry: register, schema, id.
	 *
	 * @return string
	 */
	private function deleteObject(ExampleSetRemovalRun $run, ExampleSetRecordedObject $held): string {
		if ($this->deleteFiles(run: $run, held: $held) === false) {
			return 'a file of it stays';
		}

		$entry = $held->entry;
		return $this->objects->store->deleteRecorded(ref: new ObjectRef(register: $entry['register'], schema: $entry['schema'], id: $entry['id']));
	}//end deleteObject()

	/**
	 * Revert the recorded changes, newest first.
	 *
	 * @param ExampleSetRemovalRun $run The remove; loses the reverted changes; counts, paths left as somebody changed
	 *                                  them, and failures are added.
	 *
	 * @return void
	 */
	private function revertChanges(ExampleSetRemovalRun $run): void {
		foreach (array_reverse($run->record['changes'], true) as $name => $entry) {
			if ($this->reverted(run: $run, change: new ExampleSetRecordedObject(name: (string) $name, entry: $entry)) === false) {
				continue;
			}

			unset($run->record['changes'][$name]);
			$run->report['removed']['changes']++;
			$this->written(run: $run);
		}
	}//end revertChanges()

	/**
	 * Revert one recorded change; false when it could not be (named under `failed`). A path somebody changed after
	 * the install is left and named under `left`.
	 *
	 * @param ExampleSetRemovalRun     $run    The remove; its report is added to.
	 * @param ExampleSetRecordedObject $change The change's record key and entry.
	 *
	 * @return bool
	 */
	private function reverted(ExampleSetRemovalRun $run, ExampleSetRecordedObject $change): bool {
		$reverted = $this->objects->changes->revert(entry: $change->entry);
		if ($reverted['ok'] === false) {
			$run->report['failed'][] = $this->changeOn(change: $change);
			return false;
		}

		foreach ($reverted['left'] as $path) {
			$run->report['left'][] = $this->changeOn(change: $change).', path '.$path.', which somebody changed after the install';
		}

		return true;
	}//end reverted()

	/**
	 * A recorded change as the report names it.
	 *
	 * @param ExampleSetRecordedObject $change The change's record key and entry: register, schema, id.
	 *
	 * @return string
	 */
	private function changeOn(ExampleSetRecordedObject $change): string {
		$entry = $change->entry;
		return 'change '.$change->name.' on '.$entry['register'].' '.$entry['schema'].' ('.$entry['id'].')';
	}//end changeOn()

	/**
	 * Remove the register imports this set made.
	 *
	 * The register itself and its schemas stay, as they may hold other people's
	 * data; the record keeps the slug without jobs so that an install imports
	 * the register's data again (REQ-EXS-009).
	 *
	 * @param ExampleSetRemovalRun $run The remove; loses the removed imports, counts and failures are added.
	 *
	 * @return void
	 */
	private function removeImports(ExampleSetRemovalRun $run): void {
		foreach (array_keys($run->record['registers']) as $slug) {
			// A slug whose jobs are all gone stays with no jobs: the register and its schemas remain on the instance, but
			// the data the import made does not, and the next install must import it again.
			$run->record['registers'][$slug] = $this->removeJobs(run: $run, slug: (string) $slug);

			$this->written(run: $run);
		}
	}//end removeImports()

	/**
	 * Remove the import jobs of one register; the jobs that stay.
	 *
	 * @param ExampleSetRemovalRun $run  The remove; counts and failures are added.
	 * @param string               $slug The register's slug.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function removeJobs(ExampleSetRemovalRun $run, string $slug): array {
		$left = [];
		foreach ($run->record['registers'][$slug] as $job) {
			if ($this->settings->registers->removeImport(appId: (string) $job['appId'], jobId: (string) $job['jobId']) === false) {
				$run->report['failed'][] = 'register '.$slug.' import job '.$job['jobId'];
				$left[] = $job;
				continue;
			}

			$run->report['removed']['register imports']++;
		}

		return $left;
	}//end removeJobs()

	/**
	 * Delete the files the set attached to one object; false when one stays.
	 *
	 * Every file that stays is named in the report with the reason.
	 *
	 * @param ExampleSetRemovalRun     $run  The remove; loses the deleted files, counts and failures are added.
	 * @param ExampleSetRecordedObject $held The object's record key and entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-remove-deletes-what-the-set-wrote-even-in-an-archival-schema-and-says-why-anything-stays-req-exs-022
	 */
	private function deleteFiles(ExampleSetRemovalRun $run, ExampleSetRecordedObject $held): bool {
		$name = $held->name;
		$left = $this->deleteFilesOf(run: $run, held: $held);
		unset($run->record['files'][$name]);
		if ($left !== []) {
			$run->record['files'][$name] = $left;
		}

		$this->written(run: $run);

		return $left === [];
	}//end deleteFiles()

	/**
	 * Delete the files of one object; the files that stay.
	 *
	 * @param ExampleSetRemovalRun     $run  The remove; counts and failures are added.
	 * @param ExampleSetRecordedObject $held The object's record key and entry: its id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function deleteFilesOf(ExampleSetRemovalRun $run, ExampleSetRecordedObject $held): array {
		$left = [];
		foreach (($run->record['files'][$held->name] ?? []) as $file) {
			$attached = new AttachedFile(id: (string) $file['id'], name: (string) $file['name']);
			$why      = $this->objects->files->deleteAttached(file: $attached, objectId: (string) $held->entry['id']);
			if ($why !== '') {
				$run->report['failed'][] = 'file '.$file['name'].' on '.$held->name.': '.$why;
				$left[] = $file;
				continue;
			}

			$run->report['removed']['files']++;
		}

		return $left;
	}//end deleteFilesOf()

	/**
	 * Restore the entries of one part the set wrote (app config, user preferences), newest first: an entry that still
	 * holds what the set wrote is put back, one somebody changed since is left (and named), one that cannot be
	 * restored stays in the record (and is named as failed).
	 *
	 * @param ExampleSetRemovalRun  $run  The remove; loses the restored entries, counts, entries left and failures.
	 * @param ExampleSetRestorePart $part The record part and how an entry of it is reverted.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function restore(ExampleSetRemovalRun $run, ExampleSetRestorePart $part): void {
		foreach (array_reverse($run->record[$part->part], true) as $name => $entry) {
			$outcome = ($part->revert)($entry);
			$held    = new ExampleSetRecordedObject(name: (string) $name, entry: $entry);
			if ($outcome === 'failed') {
				$run->report['failed'][] = $this->restoreLabel(part: $part, entry: $held);
				continue;
			}

			if ($outcome === 'left') {
				$run->report['left'][] = $this->restoreLabel(part: $part, entry: $held).', which somebody changed after the install';
			}

			unset($run->record[$part->part][$name]);
			$run->report['removed'][$part->count()]++;
			$this->written(run: $run);
		}
	}//end restore()

	/**
	 * A restored entry as the report names it.
	 *
	 * @param ExampleSetRestorePart    $part  The record part.
	 * @param ExampleSetRecordedObject $entry The entry's record key and entry.
	 *
	 * @return string
	 */
	private function restoreLabel(ExampleSetRestorePart $part, ExampleSetRecordedObject $entry): string {
		if ($part->part === 'appConfig') {
			return 'app config '.$entry->entry['app'].' '.$entry->entry['key'];
		}

		return 'user preference '.$entry->name;
	}//end restoreLabel()

	/**
	 * Take the sub-admins and the members the set added out of their groups, and keep the record.
	 *
	 * @param ExampleSetRemovalRun $run The remove; loses what was taken out, counts and failures are added.
	 *
	 * @return void
	 */
	private function removeMemberships(ExampleSetRemovalRun $run): void {
		$removal = new ExampleSetAccountsRemoval(accounts: $this->accounts);
		$run->record['subAdmins']   = $removal->removeSubAdmins(pairs: $run->record['subAdmins'], report: $run->report);
		$run->record['memberships'] = $removal->removeMembers(pairs: $run->record['memberships'], report: $run->report);
		$this->written(run: $run);
	}//end removeMemberships()

	/**
	 * Delete the OpenRegister organisations the set created.
	 *
	 * The accounts the record held before remove deleted them, and any member that is no account any more, do not
	 * keep an organisation.
	 *
	 * @param ExampleSetRemovalRun $run The remove; counts, organisations kept and failures are added.
	 *
	 * @return void
	 */
	private function removeOrganisations(ExampleSetRemovalRun $run): void {
		$left = [];
		foreach ($run->record['organisations'] as $uuid) {
			if ($this->keepsOrganisation(run: $run, uuid: $uuid) === true) {
				$left[] = $uuid;
			}
		}

		$run->record['organisations'] = $left;
		$this->written(run: $run);
	}//end removeOrganisations()

	/**
	 * Delete one organisation the set created; true when it stays (it could not be deleted, or has members the set
	 * did not add), which the report names.
	 *
	 * @param ExampleSetRemovalRun $run  The remove; counts, organisations kept and failures are added.
	 * @param string               $uuid The organisation's uuid.
	 *
	 * @return bool
	 */
	private function keepsOrganisation(ExampleSetRemovalRun $run, string $uuid): bool {
		$outcome = $this->settings->instance->delete(uuid: $uuid, ignorable: $this->ignorable(accounts: $run->accounts));
		if ($outcome === ExampleSetInstance::FAILED) {
			$run->report['failed'][] = 'OpenRegister organisation '.$uuid;
			return true;
		}

		if ($outcome === ExampleSetInstance::LEFT) {
			$run->report['left'][] = 'OpenRegister organisation '.$uuid.', which has members the set did not add';
			return true;
		}

		$run->report['removed']['organisations']++;

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
	 * @param ExampleSetRemovalRun $run The remove; loses what is gone, counts and failures are added.
	 *
	 * @return void
	 */
	private function removeGroupsAndAccounts(ExampleSetRemovalRun $run): void {
		$removal = new ExampleSetAccountsRemoval(accounts: $this->accounts);
		$run->record['groupNames'] = $removal->restoreGroupNames(names: $run->record['groupNames']);
		$run->record['groups']     = $removal->deleteGroups(groups: $run->record['groups'], report: $run->report);
		$this->written(run: $run);

		$run->record['users'] = $removal->removeUsers(users: $run->record['users'], report: $run->report);
	}//end removeGroupsAndAccounts()

}//end class
