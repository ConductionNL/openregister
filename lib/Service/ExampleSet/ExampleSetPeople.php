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
	 * @param ExampleSetAccountsInstall $install The plan and the account terms (set id, adoptable ids, password).
	 * @param array<string, mixed>      $record  The record; updated and kept after every step.
	 *
	 * @return array<string, mixed> The account part of the report: `users`, `groups`, `memberships`, `subAdmins`,
	 *                              `passwords` and `dropped`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function install(ExampleSetAccountsInstall $install, array &$record): array {
		$run  = new ExampleSetPeopleRun(install: $install, record: $record);
		$plan = $install->plan;
		$this->installGroups(run: $run);
		foreach ($plan['cast']['people'] as $person) {
			if ((string) ($person['uid'] ?? '') === '') {
				continue;
			}

			$this->installUser(run: $run, person: $person);
			$this->installProfile(run: $run, person: $person);
		}

		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			$this->installServiceUser(run: $run, account: $account);
		}

		$this->installMemberships(run: $run);
		$this->installSubAdmins(run: $run);
		$record = $run->record;

		return $run->report;
	}//end install()

	/**
	 * Create every declared group the instance lacks, and record it.
	 *
	 * @param ExampleSetPeopleRun $run The install; its report and record grow.
	 *
	 * @return void
	 */
	private function installGroups(ExampleSetPeopleRun $run): void {
		foreach ($this->declaredGroups(plan: $run->install->plan) as $gid => $displayName) {
			$run->report['groups'][$gid] = 'kept';
			if ($this->accounts->groupExists(gid: $gid) === true) {
				$run->report['groups'][$gid] = $this->nameUnnamedGroup(run: $run, gid: $gid);
				continue;
			}

			if ($this->accounts->createGroup(gid: $gid, displayName: $displayName) === false) {
				$run->report['dropped'][] = 'group '.$gid.': Nextcloud did not create it';
				continue;
			}

			$run->report['groups'][$gid] = 'created';
			$run->record['groups'][]     = $gid;
			$this->written(run: $run);
		}
	}//end installGroups()

	/**
	 * Write the record as the run holds it now.
	 *
	 * @param ExampleSetPeopleRun $run The install.
	 *
	 * @return void
	 */
	private function written(ExampleSetPeopleRun $run): void {
		$this->record->write(set: $run->set(), record: $run->record);
	}//end written()

	/**
	 * Give an existing group that shows only its id the declared display name, and record the name it had.
	 *
	 * @param ExampleSetPeopleRun $run The install; the record gains the old name.
	 * @param string              $gid The group id.
	 *
	 * @return string `named` or `kept`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-declared-group-without-a-name-gets-the-declared-one
	 */
	private function nameUnnamedGroup(ExampleSetPeopleRun $run, string $gid): string {
		$displayName = $this->declaredGroups(plan: $run->install->plan)[$gid];
		$current     = $this->accounts->groupName(gid: $gid);
		$unnamed     = ($current === '' || $current === $gid);
		if ($displayName === '' || $unnamed === false || $this->accounts->setGroupName(gid: $gid, displayName: $displayName) === false) {
			return 'kept';
		}

		$run->record['groupNames'][$gid] = ($run->record['groupNames'][$gid] ?? ['old' => $current, 'new' => $displayName]);
		$this->written(run: $run);

		return 'named';
	}//end nameUnnamedGroup()

	/**
	 * Add every declared membership the instance lacks, and record it.
	 *
	 * @param ExampleSetPeopleRun $run The install; its report and record grow.
	 *
	 * @return void
	 */
	private function installMemberships(ExampleSetPeopleRun $run): void {
		foreach ($this->declaredMemberships(plan: $run->install->plan) as [$gid, $uid]) {
			$name = $gid.' / '.$uid;
			$run->report['memberships'][$name] = 'kept';
			if ($this->accounts->inGroup(gid: $gid, uid: $uid) === true) {
				continue;
			}

			if ($this->accounts->addToGroup(gid: $gid, uid: $uid) === false) {
				$run->report['dropped'][] = 'membership '.$name.': the group or the account is not there';
				continue;
			}

			$run->report['memberships'][$name] = 'created';
			$run->record['memberships'][]      = [$gid, $uid];
			$this->written(run: $run);
		}//end foreach
	}//end installMemberships()

	/**
	 * Make every declared group sub-admin one the instance lacks, and record it.
	 *
	 * @param ExampleSetPeopleRun $run The install; its report and record grow.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-group-may-name-its-sub-admins-from-the-cast
	 */
	private function installSubAdmins(ExampleSetPeopleRun $run): void {
		foreach ($this->declaredSubAdmins(plan: $run->install->plan) as [$gid, $uid]) {
			$name = $gid.' / '.$uid;
			$run->report['subAdmins'][$name] = 'kept';
			if ($this->accounts->isSubAdmin(gid: $gid, uid: $uid) === true) {
				continue;
			}

			if ($this->accounts->addSubAdmin(gid: $gid, uid: $uid) === false) {
				$run->report['dropped'][] = 'sub-admin '.$name.': the group or the account is not there';
				continue;
			}

			$run->report['subAdmins'][$name] = 'created';
			$run->record['subAdmins'][]      = [$gid, $uid];
			$this->written(run: $run);
		}
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
	 * @param ExampleSetPeopleRun  $run    The install; its report and record grow.
	 * @param array<string, mixed> $person The cast person.
	 *
	 * @return void
	 */
	private function installUser(ExampleSetPeopleRun $run, array $person): void {
		$uid = (string) $person['uid'];
		if ($this->accounts->userExists(uid: $uid) === true) {
			$this->keepOrAdopt(run: $run, uid: $uid);
			return;
		}

		$this->installNewUser(run: $run, person: $person);
	}//end installUser()

	/**
	 * Create the account for a cast person that does not exist yet, and report it.
	 *
	 * @param ExampleSetPeopleRun  $run    The install in progress.
	 * @param array<string, mixed> $person The cast person, its uid not yet taken.
	 *
	 * @return void
	 */
	private function installNewUser(ExampleSetPeopleRun $run, array $person): void {
		$uid = (string) $person['uid'];
		$generated = $this->generatedPassword(run: $run);
		$error = $this->createAccount(person: $person, password: $this->passwordOf(run: $run, generated: $generated));
		if ($error !== '') {
			$run->report['dropped'][] = 'account '.$uid.': '.$error;
			return;
		}

		$this->created(run: $run, uid: $uid);
		if ($generated !== '') {
			$run->report['passwords'][$uid] = $generated;
		}
	}//end installNewUser()

	/**
	 * A fresh password when the install terms name none, else the empty string.
	 *
	 * @param ExampleSetPeopleRun $run The install in progress.
	 *
	 * @return string The generated password, or ''.
	 */
	private function generatedPassword(ExampleSetPeopleRun $run): string {
		if ($run->install->terms->password === '') {
			return $this->accounts->newPassword();
		}

		return '';
	}//end generatedPassword()

	/**
	 * The password a new account gets: the generated one, or else the one the terms name.
	 *
	 * @param ExampleSetPeopleRun $run       The install in progress.
	 * @param string              $generated The generated password, or ''.
	 *
	 * @return string The password.
	 */
	private function passwordOf(ExampleSetPeopleRun $run, string $generated): string {
		if ($generated !== '') {
			return $generated;
		}

		return $run->install->terms->password;
	}//end passwordOf()

	/**
	 * Create one account for a cast person.
	 *
	 * @param array<string, mixed> $person   The cast person.
	 * @param string               $password Its password.
	 *
	 * @return string The error, or '' when it was created.
	 */
	private function createAccount(array $person, string $password): string {
		$uid = (string) $person['uid'];

		return $this->accounts->createUser(
			uid: $uid,
			displayName: (string) ($person['displayName'] ?? $uid),
			email: (string) ($person['email'] ?? ''),
			password: $password
		);
	}//end createAccount()

	/**
	 * An account that is there: kept, or adopted when the record does not hold it and the administrator lets the set
	 * use it.
	 *
	 * @param ExampleSetPeopleRun $run The install; its report and record grow.
	 * @param string              $uid The account id.
	 *
	 * @return void
	 */
	private function keepOrAdopt(ExampleSetPeopleRun $run, string $uid): void {
		$run->report['users'][$uid] = 'kept';
		if (isset($run->record['users'][$uid]) === false && in_array($uid, $run->install->terms->adopt, true) === true) {
			$this->adopted(run: $run, uid: $uid);
		}
	}//end keepOrAdopt()

	/**
	 * Record and report an account as adopted.
	 *
	 * @param ExampleSetPeopleRun $run The install; its report and record grow.
	 * @param string              $uid The account id.
	 *
	 * @return void
	 */
	private function adopted(ExampleSetPeopleRun $run, string $uid): void {
		$run->record['users'][$uid] = 'adopted';
		$run->report['users'][$uid] = 'adopted';
		$this->written(run: $run);
	}//end adopted()

	/**
	 * Report and record an account as created.
	 *
	 * @param ExampleSetPeopleRun $run The install; its report and record grow.
	 * @param string              $uid The account id.
	 *
	 * @return void
	 */
	private function created(ExampleSetPeopleRun $run, string $uid): void {
		$run->report['users'][$uid] = 'created';
		$run->record['users'][$uid] = 'created';
		$this->written(run: $run);
	}//end created()

	/**
	 * Write the function, role, absence and status of an account the set created, at every install.
	 *
	 * An account the set adopted is someone else's: it only gains the function and role it lacks, and a value it
	 * holds stays. An account the set found but did not adopt is left alone.
	 *
	 * @param ExampleSetPeopleRun  $run    The install; a part that could not be written goes under `dropped`.
	 * @param array<string, mixed> $person The cast person.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-cast-person-may-carry-a-function-a-role-an-absence-and-a-status
	 */
	private function installProfile(ExampleSetPeopleRun $run, array $person): void {
		$uid  = (string) $person['uid'];
		$held = ($run->record['users'][$uid] ?? null);
		if ($this->profiles === null || in_array($held, ['created', 'adopted'], true) === false) {
			return;
		}

		$today   = new DateTimeImmutable('today', new DateTimeZone((string) ($run->install->plan['timezone'] ?? 'UTC')));
		$profile = $this->profiles->fromPerson(person: $person, today: $today);
		// An adopted account only gains what it lacks; a created one gets the whole profile.
		if ($held === 'adopted') {
			$reasons = $this->profiles->fillMissing(uid: $uid, profile: $profile);
		} else {
			$reasons = $this->profiles->apply(uid: $uid, profile: $profile);
		}

		foreach ($reasons as $reason) {
			$run->report['dropped'][] = $reason;
		}
	}//end installProfile()

	/**
	 * One service account: kept, adopted or created with a password nobody ever sees.
	 *
	 * Nextcloud has no account without a password, so the account gets a long
	 * random one that is neither shown nor stored: nobody can sign in with it,
	 * and the account stays enabled for the app that runs work as it.
	 *
	 * @param ExampleSetPeopleRun  $run     The install; its report and record grow. The password is not used.
	 * @param array<string, mixed> $account The declared service account.
	 *
	 * @return void
	 */
	private function installServiceUser(ExampleSetPeopleRun $run, array $account): void {
		$uid = (string) $account['uid'];
		if ($this->accounts->userExists(uid: $uid) === true) {
			$this->keepOrAdopt(run: $run, uid: $uid);
			return;
		}

		$error = $this->accounts->createUser(
			uid: $uid,
			displayName: (string) $account['displayName'],
			email: '',
			password: $this->accounts->newPassword().$this->accounts->newPassword()
		);
		if ($error === ExampleSetAccounts::ALREADY_EXISTS && isset($run->record['users'][$uid]) === false) {
			// Another writer made the declared account in between: the set adopts it, so remove leaves it.
			$this->adopted(run: $run, uid: $uid);
			return;
		}

		if ($error !== '') {
			$run->report['dropped'][] = 'service account '.$uid.': '.$error;
			return;
		}

		$this->created(run: $run, uid: $uid);
	}//end installServiceUser()

	/**
	 * What the cast refuses: a BSN that fails the eleven test, a group or member
	 * that is not in the cast, an account that belongs to somebody else.
	 *
	 * @param array<string, mixed>      $plan    The plan.
	 * @param ExampleSetVouchedAccounts $vouched The record and the adoptable account ids.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
	 */
	public function refusals(array $plan, ExampleSetVouchedAccounts $vouched): array {
		$refusals = [];
		foreach ($plan['cast']['people'] as $key => $person) {
			$refusals = array_merge(
				$refusals,
				$this->personRefusals(plan: $plan, key: (string) $key),
				$this->accountRefusals(uid: (string) ($person['uid'] ?? ''), vouched: $vouched)
			);
		}

		$castUids = array_filter(array_map(static fn (array $person): string => (string) ($person['uid'] ?? ''), $plan['cast']['people']));
		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			if (in_array($account['uid'], $castUids, true) === true) {
				$refusals[] = 'service account "'.$account['key'].'": the account id '.$account['uid'].' is a cast member\'s';
			}

			$refusals = array_merge($refusals, $this->accountRefusals(uid: $account['uid'], vouched: $vouched));
		}

		return array_merge($refusals, $this->groupRefusals(plan: $plan), $this->memberRefusals(plan: $plan));
	}//end refusals()

	/**
	 * What the declared groups refuse: Nextcloud's administrators group, and a sub-admin that is not a cast person
	 * with an account.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, string>
	 */
	private function groupRefusals(array $plan): array {
		$refusals = [];
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

		return $refusals;
	}//end groupRefusals()

	/**
	 * What one cast person refuses: a BSN that fails the eleven test, a group that is not in the cast.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param string               $key  The cast key.
	 *
	 * @return array<int, string>
	 */
	private function personRefusals(array $plan, string $key): array {
		$castFile = 'openregister/'.ExampleSetCatalogue::FOLDER.'/'.$plan['set'].'.json';
		$person   = $plan['cast']['people'][$key];
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
	 * @param string                    $uid     The account id, or '' for a person without an account.
	 * @param ExampleSetVouchedAccounts $vouched The record and the adoptable account ids.
	 *
	 * @return array<int, string>
	 */
	private function accountRefusals(string $uid, ExampleSetVouchedAccounts $vouched): array {
		if ($uid === '' || $vouched->vouchFor(uid: $uid) === true || $this->accounts->userExists(uid: $uid) === false) {
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
		$pairs = array_merge(
			$this->castMemberships(plan: $plan),
			$this->fragmentMemberships(plan: $plan),
			$this->serviceAccountMemberships(plan: $plan)
		);

		return array_values(array_unique($pairs, SORT_REGULAR));
	}//end declaredMemberships()

	/**
	 * The memberships the cast's people declare, as [gid, uid] pairs.
	 *
	 * @param array<string, mixed> $plan The resolved plan.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs.
	 */
	private function castMemberships(array $plan): array {
		$pairs = [];
		foreach ($plan['cast']['people'] as $person) {
			$uid = (string) ($person['uid'] ?? '');
			foreach ((array) ($person['groups'] ?? []) as $groupKey) {
				$group = $plan['cast']['groups'][$groupKey];
				$pairs[] = [(string) ($group['gid'] ?? $groupKey), $uid];
			}
		}

		return $pairs;
	}//end castMemberships()

	/**
	 * The memberships the fragments' groups declare, as [gid, uid] pairs.
	 *
	 * @param array<string, mixed> $plan The resolved plan.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs.
	 */
	private function fragmentMemberships(array $plan): array {
		$pairs = [];
		foreach ($plan['fragments'] as $fragment) {
			foreach ($fragment['groups'] as $group) {
				foreach ((array) ($group['members'] ?? []) as $member) {
					$pairs[] = [(string) $group['group'], (string) $plan['cast']['people'][$member]['uid']];
				}
			}
		}

		return $pairs;
	}//end fragmentMemberships()

	/**
	 * The memberships the service accounts declare, as [gid, uid] pairs.
	 *
	 * @param array<string, mixed> $plan The resolved plan.
	 *
	 * @return array<int, array{0: string, 1: string}> The pairs.
	 */
	private function serviceAccountMemberships(array $plan): array {
		$pairs = [];
		foreach ($this->serviceAccounts(plan: $plan) as $account) {
			foreach ($account['groups'] as $gid) {
				$pairs[] = [$gid, $account['uid']];
			}
		}

		return $pairs;
	}//end serviceAccountMemberships()

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
		return array_merge(
			$this->missingAccountsAndGroups(record: $record),
			$this->missingMembershipsAndSubAdmins(record: $record)
		);
	}//end missing()

	/**
	 * The recorded accounts and groups that are not on the instance.
	 *
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<int, string> What is missing, accounts first.
	 */
	private function missingAccountsAndGroups(array $record): array {
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

		return $missing;
	}//end missingAccountsAndGroups()

	/**
	 * The recorded memberships and sub-admin rights that are not on the instance.
	 *
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<int, string> What is missing, memberships first.
	 */
	private function missingMembershipsAndSubAdmins(array $record): array {
		$missing = [];
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
	}//end missingMembershipsAndSubAdmins()

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
	 * The groups this set created, or will create because the instance lacks them: a group that was there before the
	 * set is not the set's.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/changes/example-set-access-links-and-grants/specs/example-sets/spec.md#requirement-a-fragment-may-declare-grants-on-its-objects
	 */
	public function groupsOfSet(array $plan, array $record): array {
		$gids = array_values(array_filter((array) ($record['groups'] ?? []), 'is_string'));
		foreach (array_keys($this->declaredGroups(plan: $plan)) as $gid) {
			if (in_array((string) $gid, $gids, true) === false && $this->accounts->groupExists(gid: (string) $gid) === false) {
				$gids[] = (string) $gid;
			}
		}

		return $gids;
	}//end groupsOfSet()

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
