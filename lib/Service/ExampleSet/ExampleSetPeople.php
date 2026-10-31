<?php

/**
 * OpenRegister Example Set People
 *
The accounts of an example set: the cast's people with an account, and the
 * service accounts fragments declare. Refuses an account somebody else made, a
 * BSN that fails the eleven test and Nextcloud's administrators group; makes
 * accounts, groups and memberships the record lacks; names what is missing.
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
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The accounts, groups and memberships of an example set.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)            One rule per kind of refusal keeps each one readable.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every account rule of a set in one place: cast, service accounts, groups.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
class ExampleSetPeople {
	/**
	 * Nextcloud's administrators group, which no example set may add anyone to.
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * Constructor.
	 *
	 * @param ExampleSetAccounts      $accounts Nextcloud accounts and groups.
	 * @param ExampleSetRecord        $record   What an install made.
	 * @param ExampleSetProfiles|null $profiles Writes function, role, absence and status; null leaves them out.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetAccounts $accounts,
		private readonly ExampleSetRecord $record,
		private readonly ?ExampleSetProfiles $profiles=null,
	) {
	}//end __construct()

	/**
	 * Groups, accounts and memberships, each written when the record does not hold it.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<int, string>   $adopt    Adoptable account ids.
	 * @param string               $password The administrator's password, or ''.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record; updated and kept after every step.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function install(array $plan, array $adopt, string $password, array $report, array &$record): array {
		$set    = (string) $plan['set'];
		$report = $this->installGroups(plan: $plan, report: $report, record: $record);
		foreach ($plan['cast']['people'] as $person) {
			$uid = (string) ($person['uid'] ?? '');
			if ($uid === '') {
				continue;
			}

			$report = $this->installUser(set: $set, person: $person, adopt: $adopt, password: $password, report: $report, record: $record);
			$report = $this->installProfile(plan: $plan, person: $person, report: $report, record: $record);
		}

		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			$report = $this->installServiceUser(set: $set, account: $account, adopt: $adopt, report: $report, record: $record);
		}

		$report = $this->installMemberships(plan: $plan, report: $report, record: $record);

		return $this->installSubAdmins(plan: $plan, report: $report, record: $record);
	}//end install()

	/**
	 * Create every declared group the instance lacks, and record it.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installGroups(array $plan, array $report, array &$record): array {
		foreach ($this->declaredGroups(plan: $plan) as $gid => $displayName) {
			$report['groups'][$gid] = 'kept';
			if ($this->accounts->groupExists(gid: $gid) === true) {
				$report['groups'][$gid] = $this->nameUnnamedGroup(set: (string) $plan['set'], gid: $gid, displayName: $displayName, record: $record);
				continue;
			}

			if ($this->accounts->createGroup(gid: $gid, displayName: $displayName) === false) {
				$report['dropped'][] = 'group '.$gid.': Nextcloud did not create it';
				continue;
			}

			$report['groups'][$gid] = 'created';
			$record['groups'][]     = $gid;
			$this->record->write(set: (string) $plan['set'], record: $record);
		}

		return $report;
	}//end installGroups()

	/**
	 * Give an existing group that shows only its id the declared display name, and record the name it had.
	 *
	 * @param string               $set         The set id.
	 * @param string               $gid         The group id.
	 * @param string               $displayName The declared display name.
	 * @param array<string, mixed> $record      The record; gains the old name.
	 *
	 * @return string `named` or `kept`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-group-without-a-name-gets-the-declared-one
	 */
	private function nameUnnamedGroup(string $set, string $gid, string $displayName, array &$record): string {
		$current = $this->accounts->groupName(gid: $gid);
		$unnamed = ($current === '' || $current === $gid);
		if ($displayName === '' || $unnamed === false || $this->accounts->setGroupName(gid: $gid, displayName: $displayName) === false) {
			return 'kept';
		}

		$record['groupNames'][$gid] = ($record['groupNames'][$gid] ?? ['old' => $current, 'new' => $displayName]);
		$this->record->write(set: $set, record: $record);

		return 'named';
	}//end nameUnnamedGroup()

	/**
	 * Add every declared membership the instance lacks, and record it.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installMemberships(array $plan, array $report, array &$record): array {
		$set = (string) $plan['set'];
		foreach ($this->declaredMemberships(plan: $plan) as [$gid, $uid]) {
			$name = $gid.' / '.$uid;
			$report['memberships'][$name] = 'kept';
			if ($this->accounts->inGroup(gid: $gid, uid: $uid) === true) {
				continue;
			}

			if ($this->accounts->addToGroup(gid: $gid, uid: $uid) === false) {
				$report['dropped'][] = 'membership '.$name.': the group or the account is not there';
				continue;
			}

			$report['memberships'][$name] = 'created';
			$record['memberships'][]      = [$gid, $uid];
			$this->record->write(set: $set, record: $record);
		}//end foreach

		return $report;
	}//end installMemberships()

	/**
	 * Make every declared group sub-admin one the instance lacks, and record it.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	private function installSubAdmins(array $plan, array $report, array &$record): array {
		foreach ($this->declaredSubAdmins(plan: $plan) as [$gid, $uid]) {
			$name = $gid.' / '.$uid;
			$report['subAdmins'][$name] = 'kept';
			if ($this->accounts->isSubAdmin(gid: $gid, uid: $uid) === true) {
				continue;
			}

			if ($this->accounts->addSubAdmin(gid: $gid, uid: $uid) === false) {
				$report['dropped'][] = 'sub-admin '.$name.': the group or the account is not there';
				continue;
			}

			$report['subAdmins'][$name] = 'created';
			$record['subAdmins'][]      = [$gid, $uid];
			$this->record->write(set: (string) $plan['set'], record: $record);
		}

		return $report;
	}//end installSubAdmins()

	/**
	 * Every group sub-admin the set declares, as gid and uid pairs: `subAdmins` (cast keys) on a cast or fragment group.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	private function declaredSubAdmins(array $plan): array {
		$pairs = [];
		foreach ($this->groupsWithSubAdmins(plan: $plan) as [$gid, $keys]) {
			foreach ($keys as $key) {
				$uid = (string) ($plan['cast']['people'][$key]['uid'] ?? '');
				if ($uid !== '') {
					$pairs[] = [$gid, $uid];
				}
			}
		}

		return array_values(array_unique($pairs, SORT_REGULAR));
	}//end declaredSubAdmins()

	/**
	 * The cast and fragment groups with their declared sub-admin cast keys.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, array{0: string, 1: array<int, string>}>
	 */
	private function groupsWithSubAdmins(array $plan): array {
		$groups = [];
		foreach ($plan['cast']['groups'] as $key => $group) {
			$groups[] = [(string) ($group['gid'] ?? $key), array_map('strval', (array) ($group['subAdmins'] ?? []))];
		}

		foreach ($plan['fragments'] as $fragment) {
			foreach ($fragment['groups'] as $group) {
				$groups[] = [(string) ($group['group'] ?? ''), array_map('strval', (array) ($group['subAdmins'] ?? []))];
			}
		}

		return array_values(array_filter($groups, static fn (array $group): bool => $group[0] !== '' && $group[1] !== []));
	}//end groupsWithSubAdmins()

	/**
	 * One cast account: kept, adopted or created.
	 *
	 * @param string               $set      The set id.
	 * @param array<string, mixed> $person   The cast person.
	 * @param array<int, string>   $adopt    Adoptable account ids.
	 * @param string               $password The administrator's password, or ''.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installUser(string $set, array $person, array $adopt, string $password, array $report, array &$record): array {
		$uid = (string) $person['uid'];
		if ($this->accounts->userExists(uid: $uid) === true) {
			$report['users'][$uid] = 'kept';
			if (isset($record['users'][$uid]) === false && in_array($uid, $adopt, true) === true) {
				$record['users'][$uid] = 'adopted';
				$report['users'][$uid] = 'adopted';
				$this->record->write(set: $set, record: $record);
			}

			return $report;
		}

		$generated = '';
		if ($password === '') {
			$generated = $this->accounts->newPassword();
			$password  = $generated;
		}

		$error = $this->accounts->createUser(
			uid: $uid,
			displayName: (string) ($person['displayName'] ?? $uid),
			email: (string) ($person['email'] ?? ''),
			password: $password
		);
		if ($error !== '') {
			$report['dropped'][] = 'account '.$uid.': '.$error;
			return $report;
		}

		$report['users'][$uid] = 'created';
		if ($generated !== '') {
			$report['passwords'][$uid] = $generated;
		}

		$record['users'][$uid] = 'created';
		$this->record->write(set: $set, record: $record);

		return $report;
	}//end installUser()

	/**
	 * Write the function, role, absence and status of an account the set created, at every install.
	 *
	 * An account the set adopted is someone else's: it only gains the function and role it lacks, and a value it
	 * holds stays. An account the set found but did not adopt is left alone.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $person The cast person.
	 * @param array<string, mixed> $report The report; a part that could not be written goes under `dropped`.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
	 */
	private function installProfile(array $plan, array $person, array $report, array $record): array {
		$uid  = (string) $person['uid'];
		$held = ($record['users'][$uid] ?? null);
		if ($this->profiles === null || in_array($held, ['created', 'adopted'], true) === false) {
			return $report;
		}

		$today   = new DateTimeImmutable('today', new DateTimeZone((string) ($plan['timezone'] ?? 'UTC')));
		$profile = $this->profiles->fromPerson(person: $person, today: $today);
		$reasons = $this->writeProfile(uid: $uid, profile: $profile, adopted: $held === 'adopted');

		foreach ($reasons as $reason) {
			$report['dropped'][] = $reason;
		}

		return $report;
	}//end installProfile()

	/**
	 * Write a created account's whole profile, or fill in what an adopted account lacks.
	 *
	 * @param string               $uid     The account.
	 * @param array<string, mixed> $profile The profile, as ExampleSetProfiles::fromPerson() gives it.
	 * @param bool                 $adopted Whether the set adopted the account rather than created it.
	 *
	 * @return array<int, string> Why a part was not written.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Created or adopted is the account's own fact in the record.
	 */
	private function writeProfile(string $uid, array $profile, bool $adopted): array {
		if ($adopted === true) {
			return $this->profiles->fillMissing(uid: $uid, profile: $profile);
		}

		return $this->profiles->apply(uid: $uid, profile: $profile);
	}//end writeProfile()

	/**
	 * One service account: kept, adopted or created with a password nobody ever sees.
	 *
	 * Nextcloud has no account without a password, so the account gets a long
	 * random one that is neither shown nor stored: nobody can sign in with it,
	 * and the account stays enabled for the app that runs work as it.
	 *
	 * @param string               $set     The set id.
	 * @param array<string, mixed> $account The declared service account.
	 * @param array<int, string>   $adopt   Adoptable account ids.
	 * @param array<string, mixed> $report  The report so far.
	 * @param array<string, mixed> $record  The record.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installServiceUser(string $set, array $account, array $adopt, array $report, array &$record): array {
		$uid = (string) $account['uid'];
		if ($this->accounts->userExists(uid: $uid) === true) {
			$report['users'][$uid] = 'kept';
			if (isset($record['users'][$uid]) === false && in_array($uid, $adopt, true) === true) {
				$record['users'][$uid] = 'adopted';
				$report['users'][$uid] = 'adopted';
				$this->record->write(set: $set, record: $record);
			}

			return $report;
		}

		$error = $this->accounts->createUser(
			uid: $uid,
			displayName: (string) $account['displayName'],
			email: '',
			password: $this->accounts->newPassword().$this->accounts->newPassword()
		);
		if ($error === ExampleSetAccounts::ALREADY_EXISTS && isset($record['users'][$uid]) === false) {
			// Another writer made the declared account in between: the set adopts it, so remove leaves it.
			$record['users'][$uid] = 'adopted';
			$report['users'][$uid] = 'adopted';
			$this->record->write(set: $set, record: $record);
			return $report;
		}

		if ($error !== '') {
			$report['dropped'][] = 'service account '.$uid.': '.$error;
			return $report;
		}

		$report['users'][$uid] = 'created';
		$record['users'][$uid] = 'created';
		$this->record->write(set: $set, record: $record);

		return $report;
	}//end installServiceUser()

	/**
	 * What the cast refuses: a BSN that fails the eleven test, a group or member
	 * that is not in the cast, an account that belongs to somebody else.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record.
	 * @param array<int, string>   $adopt  Adoptable account ids.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function refusals(array $plan, array $record, array $adopt): array {
		$refusals = [];
		$castFile = 'openregister/'.ExampleSetCatalogue::FOLDER.'/'.$plan['set'].'.json';
		foreach ($plan['cast']['people'] as $key => $person) {
			$refusals = array_merge(
				$refusals,
				$this->personRefusals(key: (string) $key, person: $person, plan: $plan, castFile: $castFile),
				$this->accountRefusals(uid: (string) ($person['uid'] ?? ''), record: $record, adopt: $adopt)
			);
		}

		$castUids = array_filter(array_map(static fn (array $person): string => (string) ($person['uid'] ?? ''), $plan['cast']['people']));
		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			if (in_array($account['uid'], $castUids, true) === true) {
				$refusals[] = 'service account "'.$account['key'].'": the account id '.$account['uid'].' is a cast member\'s';
			}

			$refusals = array_merge($refusals, $this->accountRefusals(uid: $account['uid'], record: $record, adopt: $adopt));
		}

		foreach (array_keys($this->declaredGroups(plan: $plan)) as $gid) {
			if (strtolower($gid) === self::ADMIN_GROUP) {
				$refusals[] = 'group "'.$gid.'": an example set never adds anyone to Nextcloud\'s administrators.'
					.' Give the person an app admin group in a fragment instead.';
			}
		}

		foreach ($this->groupsWithSubAdmins(plan: $plan) as [$gid, $keys]) {
			foreach ($keys as $key) {
				if (($plan['cast']['people'][$key]['uid'] ?? '') === '') {
					$refusals[] = 'group "'.$gid.'": sub-admin "'.$key.'" is not a cast person with an account';
				}
			}
		}

		return array_merge($refusals, $this->memberRefusals(plan: $plan));
	}//end refusals()

	/**
	 * What one cast person refuses: a BSN that fails the eleven test, a group that is not in the cast.
	 *
	 * @param string               $key      The cast key.
	 * @param array<string, mixed> $person   The cast person.
	 * @param array<string, mixed> $plan     The plan.
	 * @param string               $castFile The cast file, for messages.
	 *
	 * @return array<int, string>
	 */
	private function personRefusals(string $key, array $person, array $plan, string $castFile): array {
		$refusals = [];
		$bsn      = (string) ($person['bsn'] ?? '');
		if ($bsn !== '' && self::passesElevenTest(bsn: $bsn) === false) {
			$refusals[] = $castFile.': cast "'.$key.'": BSN '.$bsn.' fails the eleven test';
		}

		foreach ((array) ($person['groups'] ?? []) as $groupKey) {
			if (isset($plan['cast']['groups'][$groupKey]) === false) {
				$refusals[] = $castFile.': cast "'.$key.'": group "'.$groupKey.'" is not a cast group';
			}
		}

		return $refusals;
	}//end personRefusals()

	/**
	 * Why an account id cannot be used: it exists, and neither the record nor the administrator vouches for it.
	 *
	 * @param string               $uid    The account id, or '' for a person without an account.
	 * @param array<string, mixed> $record The record.
	 * @param array<int, string>   $adopt  Adoptable account ids.
	 *
	 * @return array<int, string>
	 */
	private function accountRefusals(string $uid, array $record, array $adopt): array {
		if ($uid === '' || isset($record['users'][$uid]) === true || in_array($uid, $adopt, true) === true
			|| $this->accounts->userExists(uid: $uid) === false
		) {
			return [];
		}

		return [
			'account "'.$uid.'" exists and this set did not create it, so it is left alone.'
			.' Pass --adopt='.$uid.' to let the set use it; remove will then leave it in place.',
		];
	}//end accountRefusals()

	/**
	 * Fragment group members that are not cast people with an account.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, string>
	 */
	private function memberRefusals(array $plan): array {
		$refusals = [];
		foreach ($plan['fragments'] as $fragment) {
			foreach ($fragment['groups'] as $group) {
				foreach ((array) ($group['members'] ?? []) as $member) {
					if (($plan['cast']['people'][$member]['uid'] ?? '') === '') {
						$refusals[] = $fragment['file'].': group "'.($group['group'] ?? '').'": "'.$member.'" is not a cast person with an account';
					}
				}
			}
		}

		return $refusals;
	}//end memberRefusals()

	/**
	 * Every group the set declares: cast groups and fragment groups, gid to display name.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<string, string>
	 */
	private function declaredGroups(array $plan): array {
		$groups = [];
		foreach ($plan['cast']['groups'] as $key => $group) {
			$groups[(string) ($group['gid'] ?? $key)] = (string) ($group['displayName'] ?? '');
		}

		foreach ($plan['fragments'] as $fragment) {
			foreach ($fragment['groups'] as $group) {
				$gid = (string) ($group['group'] ?? '');
				if ($gid !== '' && isset($groups[$gid]) === false) {
					$groups[$gid] = (string) ($group['displayName'] ?? '');
				}
			}
		}

		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			foreach ($account['groups'] as $gid) {
				$groups[$gid] ??= '';
			}
		}

		return $groups;
	}//end declaredGroups()

	/**
	 * Every membership the set declares, as gid and uid pairs.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function declaredMemberships(array $plan): array {
		$pairs = [];
		foreach ($plan['cast']['people'] as $person) {
			$uid = (string) ($person['uid'] ?? '');
			foreach ((array) ($person['groups'] ?? []) as $groupKey) {
				$group = $plan['cast']['groups'][$groupKey];
				$pairs[] = [(string) ($group['gid'] ?? $groupKey), $uid];
			}
		}

		foreach ($plan['fragments'] as $fragment) {
			foreach ($fragment['groups'] as $group) {
				foreach ((array) ($group['members'] ?? []) as $member) {
					$pairs[] = [(string) $group['group'], (string) $plan['cast']['people'][$member]['uid']];
				}
			}
		}

		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			foreach ($account['groups'] as $gid) {
				$pairs[] = [$gid, $account['uid']];
			}
		}

		return array_values(array_unique($pairs, SORT_REGULAR));
	}//end declaredMemberships()

	/**
	 * The recorded accounts, groups and memberships that are not on the instance.
	 *
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function missing(array $record): array {
		$missing = [];
		foreach (array_keys($record['users']) as $uid) {
			if ($this->accounts->userExists(uid: $uid) === false) {
				$missing[] = 'account '.$uid;
			}
		}

		foreach ($record['groups'] as $gid) {
			if ($this->accounts->groupExists(gid: $gid) === false) {
				$missing[] = 'group '.$gid;
			}
		}

		foreach ($record['memberships'] as [$gid, $uid]) {
			if ($this->accounts->inGroup(gid: $gid, uid: $uid) === false) {
				$missing[] = 'membership '.$gid.' / '.$uid;
			}
		}

		foreach (($record['subAdmins'] ?? []) as [$gid, $uid]) {
			if ($this->accounts->isSubAdmin(gid: $gid, uid: $uid) === false) {
				$missing[] = 'sub-admin '.$gid.' / '.$uid;
			}
		}

		return $missing;
	}//end missing()

	/**
	 * Every service account the fragments declare.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, array{key: string, uid: string, displayName: string, groups: array<int, string>}>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-declare-service-accounts-req-exs-014
	 */
	public function serviceAccounts(array $plan): array {
		$accounts = [];
		foreach ($plan['fragments'] as $fragment) {
			foreach (($fragment['serviceAccounts'] ?? []) as $account) {
				$accounts[] = $account;
			}
		}

		return $accounts;
	}//end serviceAccounts()

	/**
	 * Every account id the set declares: its cast members' and its service accounts'.
	 *
	 * These are the accounts an install writes before any object, so an object
	 * may name one in a property of format `user` before it exists.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function declaredUids(array $plan): array {
		$uids = [];
		foreach ($plan['cast']['people'] as $person) {
			$uids[] = (string) ($person['uid'] ?? '');
		}

		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			$uids[] = (string) ($account['uid'] ?? '');
		}

		return array_values(array_unique(array_filter($uids, static fn (string $uid): bool => $uid !== '')));
	}//end declaredUids()

	/**
	 * Whether a BSN passes the eleven test.
	 *
	 * @param string $bsn Nine digits.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public static function passesElevenTest(string $bsn): bool {
		if (preg_match('/^\d{9}$/', $bsn) !== 1) {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((int) $bsn[$i] * (9 - $i));
		}

		$sum -= (int) $bsn[8];

		return $sum % 11 === 0;
	}//end passesElevenTest()
}//end class
