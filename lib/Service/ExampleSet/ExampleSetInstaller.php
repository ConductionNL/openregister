<?php

/**
 * OpenRegister Example Set Installer
 *
 * Installs an example set gathered by ExampleSetCatalogue. First a check pass
 * resolves every placeholder and lookup, checks the cast and validates every
 * object against its real schema; when that pass refuses anything, nothing is
 * written. Then the write pass creates what the install record does not hold
 * yet, keeping the record after every step, and finally every created object
 * is read back and compared with what was sent.
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
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Checks, installs and proves an example set.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One install flow: check, accounts, objects, proof.
 *
 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
class ExampleSetInstaller {
	/**
	 * Nextcloud's administrators group, which no example set may add anyone to.
	 */
	private const ADMIN_GROUP = 'admin';

	/**
	 * Constructor.
	 *
	 * @param ExampleSetStore    $store    Register access.
	 * @param ExampleSetAccounts $accounts Accounts and groups.
	 * @param ExampleSetValues   $values   Fills placeholders.
	 * @param ExampleSetRecord   $record   What an install made.
	 * @param ExampleSetProof     $proof     Compares sent with stored.
	 * @param ExampleSetRegisters $registers Imports the registers a set requires.
	 * @param ExampleSetChanges   $changes   Changes objects the set did not create.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetStore $store,
		private readonly ExampleSetAccounts $accounts,
		private readonly ExampleSetValues $values,
		private readonly ExampleSetRecord $record,
		private readonly ExampleSetProof $proof,
		private readonly ExampleSetRegisters $registers,
		private readonly ExampleSetChanges $changes,
	) {
	}//end __construct()

	/**
	 * Check a set without writing any of its accounts, groups or objects.
	 *
	 * A register the set requires that is not on the instance is imported
	 * first, because its schemas are what the objects are checked against;
	 * the import is recorded, and remove takes it away again.
	 *
	 * @param array<string, mixed>   $plan  A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt Account ids the administrator lets the set use although it did not create them.
	 * @param DateTimeImmutable|null $today The install day; now in the set's time zone when null.
	 *
	 * @return array{refusals: array<int, string>, checked: array<string, int>, registers: array<string, string>}
	 *         Every refusal, the number of objects and changes checked per register and schema, and per required
	 *         register `imported` or `kept`.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function check(array $plan, array $adopt=[], ?DateTimeImmutable $today=null): array {
		$today     = ($today ?? $this->today(timezone: (string) $plan['timezone']));
		$record    = $this->record->read(set: (string) $plan['set']);
		$registers = $this->ensureRegisters(plan: $plan, record: $record);
		$refusals  = array_merge($registers['refusals'], $this->castRefusals(plan: $plan, record: $record, adopt: $adopt));
		$checked   = [];
		$planned   = [];
		foreach ($plan['fragments'] as $fragment) {
			$objects = [];
			foreach ($fragment['objects'] as $object) {
				$type           = $object['register'].' '.$object['schema'];
				$checked[$type] = (($checked[$type] ?? 0) + 1);
				$where          = $fragment['file'].': object "'.$object['key'].'" ('.$type.')';
				$resolved       = $this->resolve(
					object: $object,
					context: ['cast' => $plan['cast']['entries'], 'objects' => $objects, 'today' => $today],
					planned: $planned
				);
				if ($resolved['refusal'] !== '') {
					$refusals[] = $where.': '.$resolved['refusal'];
					$objects[$object['key']] = $this->standIn(app: $fragment['app'], object: $object, data: []);
					continue;
				}

				foreach ($this->store->validate(register: $object['register'], schema: $object['schema'], data: $resolved['sent']) as $message) {
					$refusals[] = $where.': '.$message;
				}

				$standIn   = $this->standIn(app: $fragment['app'], object: $object, data: $resolved['data']);
				$planned[] = ['register' => $object['register'], 'schema' => $object['schema'], 'row' => $standIn];
				$kept      = $this->kept(record: $record, app: $fragment['app'], key: $object['key']);
				$objects[$object['key']] = ($kept ?? $standIn);
			}//end foreach

			$context  = ['cast' => $plan['cast']['entries'], 'objects' => $objects, 'today' => $today];
			$refusals = array_merge($refusals, $this->checkChanges(fragment: $fragment, context: $context, checked: $checked));
		}//end foreach

		return ['refusals' => $refusals, 'checked' => $checked, 'registers' => $registers['outcome']];
	}//end check()

	/**
	 * Install a set: check, then write what the record lacks, then prove it.
	 *
	 * @param array<string, mixed>   $plan     A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt    Account ids the administrator lets the set use.
	 * @param string                 $password One password for every new account; '' makes one per account.
	 * @param DateTimeImmutable|null $today    The install day; now in the set's time zone when null.
	 *
	 * @return array<string, mixed> `refusals` (non-empty: none of the set's accounts or objects was written),
	 *                              `registers`, `users`, `groups`, `memberships` and `changes` (each name to
	 *                              `imported`, `created`, `adopted` or `kept`), `passwords` (uid to a password this
	 *                              run made), `types`, `dropped`, `missing`, `lost` and `ok`.
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function install(array $plan, array $adopt=[], string $password='', ?DateTimeImmutable $today=null): array {
		$today  = ($today ?? $this->today(timezone: (string) $plan['timezone']));
		$report = [
			'refusals'    => [],
			'registers'   => [],
			'users'       => [],
			'groups'      => [],
			'memberships' => [],
			'changes'     => [],
			'passwords'   => [],
			'types'       => [],
			'dropped'     => [],
			'missing'     => [],
			'lost'        => [],
			'ok'          => false,
		];

		$checked             = $this->check(plan: $plan, adopt: $adopt, today: $today);
		$report['refusals']  = $checked['refusals'];
		$report['registers'] = $checked['registers'];
		if ($report['refusals'] !== []) {
			return $report;
		}

		$set    = (string) $plan['set'];
		$record = $this->record->read(set: $set);
		$report = $this->installAccounts(plan: $plan, adopt: $adopt, password: $password, report: $report, record: $record);

		$written = [];
		foreach ($plan['fragments'] as $fragment) {
			$report = $this->installFragment(plan: $plan, fragment: $fragment, today: $today, report: $report, record: $record, written: $written);
		}

		return $this->proven(report: $report, record: $record, written: $written);
	}//end install()

	/**
	 * Import every required register that is not on the instance, and record the import.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record; gains the imports.
	 *
	 * @return array{refusals: array<int, string>, outcome: array<string, string>}
	 */
	private function ensureRegisters(array $plan, array &$record): array {
		$result = ['refusals' => [], 'outcome' => []];
		foreach ((array) ($plan['requires'] ?? []) as $slug) {
			$result['outcome'][$slug] = 'kept';
			if ($this->registers->exists(slug: $slug) === true) {
				continue;
			}

			$import = $this->registers->import(slug: $slug);
			if ($import['jobs'] !== []) {
				$record['registers'][$slug] = array_merge(($record['registers'][$slug] ?? []), $import['jobs']);
				$this->record->write(set: (string) $plan['set'], record: $record);
			}

			$why = $import['error'];
			if ($why === '' && $this->registers->exists(slug: $slug) === false) {
				$why = 'it is still not on this instance';
			}

			if ($why !== '') {
				$result['refusals'][]     = 'register "'.$slug.'" is required and could not be imported from OpenRegister\'s descriptor: '.$why;
				$result['outcome'][$slug] = 'failed';
				continue;
			}

			$result['outcome'][$slug] = 'imported';
		}//end foreach

		return $result;
	}//end ensureRegisters()

	/**
	 * Check the changes of one fragment: each resolves, finds its object, and leaves it valid.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  `cast`, `objects` and `today`.
	 * @param array<string, int>   $checked  Counts per register and schema; changes are added.
	 *
	 * @return array<int, string> Refusals.
	 */
	private function checkChanges(array $fragment, array $context, array &$checked): array {
		$refusals = [];
		foreach ($fragment['changes'] as $change) {
			$type           = $change['register'].' '.$change['schema'];
			$checked[$type] = (($checked[$type] ?? 0) + 1);
			$where          = $fragment['file'].': change "'.$change['key'].'" ('.$type.')';
			$resolved       = $this->changes->resolve(change: $change, context: $context);
			if ($resolved['refusal'] !== '') {
				$refusals[] = $where.': '.$resolved['refusal'];
				continue;
			}

			$merged = $resolved['merged'];
			unset($merged['@self'], $merged['id']);
			foreach ($this->store->validate(register: $change['register'], schema: $change['schema'], data: $merged) as $message) {
				$refusals[] = $where.': '.$message;
			}
		}

		return $refusals;
	}//end checkChanges()

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
	 */
	private function installAccounts(array $plan, array $adopt, string $password, array $report, array &$record): array {
		$set = (string) $plan['set'];
		foreach ($this->declaredGroups(plan: $plan) as $gid => $displayName) {
			$report['groups'][$gid] = 'kept';
			if ($this->accounts->groupExists(gid: $gid) === false) {
				if ($this->accounts->createGroup(gid: $gid, displayName: $displayName) === false) {
					$report['dropped'][] = 'group '.$gid.': Nextcloud did not create it';
					continue;
				}

				$report['groups'][$gid] = 'created';
				$record['groups'][]     = $gid;
				$this->record->write(set: $set, record: $record);
			}
		}

		foreach ($plan['cast']['people'] as $person) {
			$uid = (string) ($person['uid'] ?? '');
			if ($uid === '') {
				continue;
			}

			$report = $this->installUser(set: $set, person: $person, adopt: $adopt, password: $password, report: $report, record: $record);
		}

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
	}//end installAccounts()

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
	 * Write the objects and changes of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed>                $plan     The plan.
	 * @param array<string, mixed>                $fragment The fragment.
	 * @param DateTimeImmutable                   $today    The install day.
	 * @param array<string, mixed>                $report   The report so far.
	 * @param array<string, mixed>                $record   The record; updated and kept after every write.
	 * @param array<string, array<string, mixed>> $written  Collects what this run sent, by record key.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installFragment(array $plan, array $fragment, DateTimeImmutable $today, array $report, array &$record, array &$written): array {
		$objects = [];
		foreach ($fragment['objects'] as $object) {
			$type = $object['register'].' '.$object['schema'];
			$name = $fragment['app'].'/'.$object['key'];
			$report['types'][$type] ??= ['declared' => 0, 'created' => 0, 'kept' => 0, 'arrived' => 0];
			$report['types'][$type]['declared']++;

			$kept = $this->kept(record: $record, app: $fragment['app'], key: $object['key']);
			if ($kept !== null) {
				$report['types'][$type]['kept']++;
				$objects[$object['key']] = $kept;
				continue;
			}

			$resolved = $this->resolve(
				object: $object,
				context: ['cast' => $plan['cast']['entries'], 'objects' => $objects, 'today' => $today],
				planned: []
			);
			if ($resolved['refusal'] !== '') {
				$report['dropped'][] = $fragment['file'].': object "'.$object['key'].'": '.$resolved['refusal'];
				continue;
			}

			$made = $this->store->create(register: $object['register'], schema: $object['schema'], data: $resolved['sent']);
			if ($made['id'] === '') {
				$report['dropped'][] = $fragment['file'].': object "'.$object['key'].'": '.$made['error'];
				continue;
			}

			$report['types'][$type]['created']++;
			$record['objects'][$name] = ['register' => $object['register'], 'schema' => $object['schema'], 'id' => $made['id']];
			$this->record->write(set: (string) $plan['set'], record: $record);
			$written[$name] = $resolved['data'];

			// The row as stored, because a later object may quote a field the
			// register made itself, such as a case number.
			$objects[$object['key']] = ($this->store->get(register: $object['register'], schema: $object['schema'], id: $made['id']) ?? ['id' => $made['id']]);
		}//end foreach

		$context = ['cast' => $plan['cast']['entries'], 'objects' => $objects, 'today' => $today];

		return $this->installChanges(plan: $plan, fragment: $fragment, context: $context, report: $report, record: $record);
	}//end installFragment()

	/**
	 * Make the changes of one fragment that the record does not hold.
	 *
	 * @param array<string, mixed> $plan     The plan.
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  `cast`, `objects` and `today`.
	 * @param array<string, mixed> $report   The report so far.
	 * @param array<string, mixed> $record   The record; updated and kept after every change.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function installChanges(array $plan, array $fragment, array $context, array $report, array &$record): array {
		foreach ($fragment['changes'] as $change) {
			$name = $fragment['app'].'/'.$change['key'];
			$report['changes'][$name] = 'kept';
			if (isset($record['changes'][$name]) === true) {
				continue;
			}

			$resolved = $this->changes->resolve(change: $change, context: $context);
			$made     = ['error' => $resolved['refusal'], 'entry' => null];
			if ($resolved['refusal'] === '') {
				$made = $this->changes->apply(change: $change, resolved: $resolved);
			}

			if ($made['error'] !== '') {
				$report['dropped'][]      = $fragment['file'].': change "'.$change['key'].'": '.$made['error'];
				$report['changes'][$name] = 'failed';
				continue;
			}

			if ($made['entry'] !== null) {
				$record['changes'][$name] = $made['entry'];
				$report['changes'][$name] = 'created';
				$this->record->write(set: (string) $plan['set'], record: $record);
			}
		}//end foreach

		return $report;
	}//end installChanges()

	/**
	 * Resolve an object's lookups and fill its data.
	 *
	 * A field named in `jsonFields` is sent as JSON text, for a schema that
	 * keeps it as text; `data` keeps the list, which is what a read hands back.
	 *
	 * @param array<string, mixed>             $object  The declared object.
	 * @param array<string, mixed>             $context `cast`, `objects` and `today`.
	 * @param array<int, array<string, mixed>> $planned Objects a check pass declared earlier, for lookups.
	 *
	 * @return array{data: array<string, mixed>, sent: array<string, mixed>, refusal: string} The filled data, what
	 *                                                                                        to send, or why it
	 *                                                                                        cannot be filled.
	 */
	private function resolve(array $object, array $context, array $planned): array {
		$context['lookups'] = [];
		foreach ((array) ($object['lookups'] ?? []) as $name => $lookup) {
			$found = $this->lookedUp(register: (string) $object['register'], lookup: (array) $lookup, context: $context, planned: $planned);
			if ($found['id'] === '') {
				return ['data' => [], 'sent' => [], 'refusal' => 'lookup "'.$name.'" found '.$found['reason']];
			}

			$context['lookups'][(string) $name] = $found['id'];
		}

		$gaps = [];
		$data = (array) $this->values->fill(value: $object['data'], context: $context, gaps: $gaps);
		if ($gaps !== []) {
			return ['data' => [], 'sent' => [], 'refusal' => 'cannot resolve '.implode(', ', array_unique($gaps))];
		}

		$sent = $data;
		foreach ((array) ($object['jsonFields'] ?? []) as $field) {
			if (is_array($sent[$field] ?? null) === true) {
				$sent[$field] = (string) json_encode($sent[$field]);
			}
		}

		return ['data' => $data, 'sent' => $sent, 'refusal' => ''];
	}//end resolve()

	/**
	 * The id of the one row a lookup by business key describes.
	 *
	 * The match is checked here as well: a register that does not filter on a
	 * property hands over every row, and the first of those is not the one
	 * that was asked for.
	 *
	 * @param string                           $register The register of the object being written.
	 * @param array<string, mixed>             $lookup   `schema` and `where`; `register` when it is another one.
	 * @param array<string, mixed>             $context  The cast, the lookups found so far and the day.
	 * @param array<int, array<string, mixed>> $planned  Objects a check pass declared earlier.
	 *
	 * @return array{id: string, reason: string}
	 */
	private function lookedUp(string $register, array $lookup, array $context, array $planned): array {
		$gaps   = [];
		$where  = (array) $this->values->fill(value: (array) ($lookup['where'] ?? []), context: $context, gaps: $gaps);
		$schema = (string) ($lookup['schema'] ?? '');
		$from   = (string) ($lookup['register'] ?? $register);
		if ($gaps !== []) {
			return ['id' => '', 'reason' => 'an unresolvable '.implode(', ', array_unique($gaps))];
		}

		if ($where === [] || $schema === '') {
			return ['id' => '', 'reason' => 'nothing: a lookup needs a schema and a where'];
		}

		// Asked with the filter first, then without: OpenRegister does not
		// filter on every kind of property, and the match is checked here anyway.
		foreach ([$where, []] as $filters) {
			foreach ($this->store->find(register: $from, schema: $schema, filters: $filters) as $row) {
				if ($this->matches(row: $row, where: $where) === true) {
					return ['id' => $this->store->idOf(row: $row), 'reason' => ''];
				}
			}
		}

		$id = $this->plannedMatch(planned: $planned, register: $from, schema: $schema, where: $where);
		if ($id !== '') {
			return ['id' => $id, 'reason' => ''];
		}

		$words = [];
		foreach ($where as $field => $value) {
			$words[] = $field.' '.json_encode($value, JSON_UNESCAPED_UNICODE);
		}

		return ['id' => '', 'reason' => 'no '.$from.' '.$schema.' with '.implode(' and ', $words)];
	}//end lookedUp()

	/**
	 * The stand-in id of an object declared earlier in the set that a lookup describes, or ''.
	 *
	 * @param array<int, array<string, mixed>> $planned  Objects a check pass declared earlier.
	 * @param string                           $register The register looked in.
	 * @param string                           $schema   The schema looked in.
	 * @param array<string, mixed>             $where    The asked values.
	 *
	 * @return string
	 */
	private function plannedMatch(array $planned, string $register, string $schema, array $where): string {
		foreach ($planned as $object) {
			if ($object['register'] === $register && $object['schema'] === $schema && $this->matches(row: $object['row'], where: $where) === true) {
				return (string) $object['row']['id'];
			}
		}

		return '';
	}//end plannedMatch()

	/**
	 * Whether a row holds every asked value.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param array<string, mixed> $where The asked values, by field.
	 *
	 * @return bool
	 */
	private function matches(array $row, array $where): bool {
		foreach ($where as $field => $value) {
			$held = ($row[$field] ?? null);
			if ($held === $value) {
				continue;
			}

			if (is_scalar($held) === false || is_scalar($value) === false || (string) $held !== (string) $value) {
				return false;
			}
		}

		return true;
	}//end matches()

	/**
	 * The row the record holds for an object, when it is still there.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $app    The app of the fragment.
	 * @param string               $key    The object key.
	 *
	 * @return array<string, mixed>|null
	 */
	private function kept(array $record, string $app, string $key): ?array {
		$held = ($record['objects'][$app.'/'.$key] ?? null);
		if ($held === null) {
			return null;
		}

		return $this->store->get(register: $held['register'], schema: $held['schema'], id: $held['id']);
	}//end kept()

	/**
	 * A row that stands in for an object a check does not write.
	 *
	 * @param string               $app    The app of the fragment.
	 * @param array<string, mixed> $object The declared object.
	 * @param array<string, mixed> $data   Its filled data, or [] when it could not be filled.
	 *
	 * @return array<string, mixed>
	 */
	private function standIn(string $app, array $object, array $data): array {
		$hash = md5($app.'/'.$object['key']);
		$id   = sprintf(
			'%s-%s-4%s-8%s-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			substr($hash, 17, 3),
			substr($hash, 20, 12)
		);

		return array_merge($data, ['id' => $id, 'uuid' => $id, ExampleSetValues::PLANNED => true]);
	}//end standIn()

	/**
	 * What the cast refuses: a BSN that fails the eleven test, a group or member
	 * that is not in the cast, an account that belongs to somebody else.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record.
	 * @param array<int, string>   $adopt  Adoptable account ids.
	 *
	 * @return array<int, string>
	 */
	private function castRefusals(array $plan, array $record, array $adopt): array {
		$refusals = [];
		$castFile = 'openregister/'.ExampleSetCatalogue::FOLDER.'/'.$plan['set'].'.json';
		foreach ($plan['cast']['people'] as $key => $person) {
			$refusals = array_merge(
				$refusals,
				$this->personRefusals(key: (string) $key, person: $person, plan: $plan, castFile: $castFile),
				$this->accountRefusals(uid: (string) ($person['uid'] ?? ''), record: $record, adopt: $adopt)
			);
		}

		foreach (array_keys($this->declaredGroups(plan: $plan)) as $gid) {
			if (strtolower($gid) === self::ADMIN_GROUP) {
				$refusals[] = 'group "'.$gid.'": an example set never adds anyone to Nextcloud\'s administrators.'
					.' Give the person an app admin group in a fragment instead.';
			}
		}

		return array_merge($refusals, $this->memberRefusals(plan: $plan));
	}//end castRefusals()

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

		return array_values(array_unique($pairs, SORT_REGULAR));
	}//end declaredMemberships()

	/**
	 * Read the instance back and fill in what is missing and what was not kept.
	 *
	 * An object from an earlier run is counted, not compared: somebody may have
	 * changed it since, and that is not a loss.
	 *
	 * @param array<string, mixed>                $report  The report so far.
	 * @param array<string, mixed>                $record  The record as written.
	 * @param array<string, array<string, mixed>> $written What this run sent, by record key.
	 *
	 * @return array<string, mixed> The finished report.
	 */
	private function proven(array $report, array $record, array $written): array {
		foreach ($record['objects'] as $name => $held) {
			$type   = $held['register'].' '.$held['schema'];
			$stored = $this->store->get(register: $held['register'], schema: $held['schema'], id: $held['id']);
			if ($stored === null) {
				$report['missing'][] = $type.' '.$name;
				continue;
			}

			if (isset($report['types'][$type]) === true) {
				$report['types'][$type]['arrived']++;
			}

			foreach ($this->proof->lostPaths(declared: ($written[$name] ?? []), stored: $stored) as $path) {
				$report['lost'][] = $type.' '.$name.': '.$path;
			}
		}

		foreach ($record['changes'] as $name => $entry) {
			foreach ($this->changes->lost(entry: $entry) as $path) {
				$report['lost'][] = 'change '.$name.': '.$path;
			}
		}

		$report['missing'] = array_merge($report['missing'], $this->missingAccounts(record: $record));
		$report['ok']      = ($report['dropped'] === [] && $report['missing'] === [] && $report['lost'] === []);

		return $report;
	}//end proven()

	/**
	 * The recorded accounts, groups and memberships that are not on the instance.
	 *
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<int, string>
	 */
	private function missingAccounts(array $record): array {
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

		return $missing;
	}//end missingAccounts()

	/**
	 * Whether a BSN passes the eleven test.
	 *
	 * @param string $bsn Nine digits.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/example-sets/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
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

	/**
	 * This moment in the set's time zone, so "09:12" is a quarter past nine where the set takes place.
	 *
	 * @param string $timezone The set's time zone.
	 *
	 * @return DateTimeImmutable
	 */
	private function today(string $timezone): DateTimeImmutable {
		try {
			return new DateTimeImmutable('now', new DateTimeZone($timezone));
		} catch (Throwable) {
			return new DateTimeImmutable('now');
		}
	}//end today()
}//end class
