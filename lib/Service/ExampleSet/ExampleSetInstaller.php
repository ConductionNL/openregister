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
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;
use DateTimeZone;
use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallerInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\CauseFrame;
use OCA\OpenRegister\Service\WriteCause;
use Throwable;

/**
 * Checks, installs and proves an example set.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One install flow: check, accounts, objects, proof.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     One install flow: check, accounts, objects, proof.
 * @SuppressWarnings(PHPMD.TooManyMethods)            Each part of a fragment has its own check and write step.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)    One collaborator per part of a fragment, each small and separately tested.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
 */
class ExampleSetInstaller implements ExampleSetInstallerInterface {
	/**
	 * The report an install starts from.
	 */
	private const EMPTY_REPORT = [
		'refusals'        => [],
		'registers'       => [],
		'users'           => [],
		'groups'          => [],
		'memberships'     => [],
		'subAdmins'       => [],
		'changes'         => [],
		'appConfig'       => [],
		'userPreferences' => [],
		'tasks'           => [],
		'views'           => [],
		'watchers'        => [],
		'parties'         => [],
		'notes'           => [],
		'readStates'      => [],
		'flows'           => [],
		'files'           => 0,
		'passwords'       => [],
		'types'           => [],
		'dropped'         => [],
		'left'            => [],
		'missing'         => [],
		'lost'            => [],
		'ok'              => false,
	];

	/**
	 * Constructor.
	 *
	 * @param ExampleSetInstallerObjects $objects Register access, placeholders, the install record and the proof.
	 * @param ExampleSetInstallerSetup   $setup   Accounts, prerequisites, app config and user preferences.
	 * @param ExampleSetInstallerWriters $writers Changes, files, engine rows and the refresh rewriter.
	 * @param ExampleSetLock|null        $lock    One install or removal of a set at a time; null holds nothing.
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetInstallerObjects $objects,
		private readonly ExampleSetInstallerSetup $setup,
		private readonly ExampleSetInstallerWriters $writers,
		private readonly ?ExampleSetLock $lock=null,
	) {
	}//end __construct()

	/**
	 * Check a set without writing any of its accounts, groups or objects.
	 *
	 * A register the set requires that is not on the instance is imported
	 * first, because its schemas are what the objects are checked against;
	 * the import is recorded, and remove takes it away again.
	 *
	 * An account id the set creates (a cast member's or a service account's)
	 * counts as existing for a `user` format here: the accounts are written
	 * first in a real install, and no other id is let through.
	 *
	 * @param array<string, mixed>   $plan  A plan from ExampleSetCatalogue::gather().
	 * @param array<int, string>     $adopt Account ids the administrator lets the set use although it did not create them.
	 * @param DateTimeImmutable|null $today The install day; now in the set's time zone when null.
	 *
	 * @return array{refusals: array<int, string>, checked: array<string, int>, checkedChanges: array<string, int>, registers: array<string, string>}
	 *         Every refusal, the number of objects (`checked`) and of changes (`checkedChanges`) checked per register
	 *         and schema, counted apart as an install reports them, and per required register `imported` or `kept`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function check(array $plan, array $adopt=[], ?DateTimeImmutable $today=null): array {
		$today = ($today ?? $this->today(timezone: (string) $plan['timezone']));
		return $this->checked(request: new ExampleSetInstallRequest(plan: $plan, adopt: $adopt, password: '', today: $today));
	}//end check()

	/**
	 * Check a set as check() describes it.
	 *
	 * @param ExampleSetInstallRequest $request The plan, the adoptable account ids and the install day.
	 *
	 * @return array{refusals: array<int, string>, checked: array<string, int>, checkedChanges: array<string, int>, registers: array<string, string>}
	 *         As check() returns it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	private function checked(ExampleSetInstallRequest $request): array {
		$plan      = $request->plan;
		$record    = $this->objects->record->read(set: (string) $plan['set']);
		$ready     = $this->setup->prerequisites->ensure(plan: $plan, record: $record);
		$run       = new ExampleSetCheckRun(plan: $plan, record: $record);
		$run->base = $this->withIds(plan: $plan, base: $this->setup->prerequisites->context(plan: $plan, today: $request->today));
		$run->base['setGroups'] = $this->setup->people->groupsOfSet(plan: $plan, record: $run->record);
		$vouched       = new ExampleSetVouchedAccounts(record: $run->record, adopt: $request->adopt);
		$run->refusals = array_merge($ready['refusals'], $this->setup->people->refusals(plan: $plan, vouched: $vouched));
		$run->users    = $this->setup->people->declaredUids(plan: $plan);
		foreach ($plan['fragments'] as $fragment) {
			$this->checkFragment(run: $run, fragment: $fragment);
		}

		return ['refusals' => $run->refusals, 'checked' => $run->checked, 'checkedChanges' => $run->checkedChanges, 'registers' => $ready['registers']];
	}//end checked()

	/**
	 * Check one fragment: its objects, then its changes, app config, user preferences and engine rows.
	 *
	 * @param ExampleSetCheckRun   $run      The check; its refusals and counts grow.
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	private function checkFragment(ExampleSetCheckRun $run, array $fragment): void {
		$run->fragment = $fragment;
		$run->objects  = [];
		$run->files    = [];
		foreach ($fragment['objects'] as $object) {
			$this->checkObject(run: $run, object: $object);
		}

		$engineRows = ($this->writers->engineRows ?? new ExampleSetEngineKinds());
		$context    = [
			'objects'     => $run->objects,
			'flows'       => $this->objects->values->flowsOf(fragment: $fragment, record: null),
			'accessLinks' => $engineRows->accessLinksOf(fragment: $fragment, record: null),
		] + $run->base;
		$run->refusals = array_merge(
			$run->refusals,
			$this->checkChanges(run: $run, context: $context),
			$this->checkAppConfig(fragment: $fragment, context: $context),
			$this->checkUserPreferences(run: $run, context: ($context + ['views' => $this->objects->values->viewsOf(fragment: $fragment, record: null)])),
			$engineRows->check(write: new ExampleSetFragmentWrite(fragment: $fragment, context: $context, values: $this->objects->values), users: $run->users)
		);
	}//end checkFragment()

	/**
	 * Check one declared object: it resolves, and what it would send is valid with its files.
	 *
	 * @param ExampleSetCheckRun   $run    The check; its refusals, counts and objects so far grow.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	private function checkObject(ExampleSetCheckRun $run, array $object): void {
		$type = $object['register'].' '.$object['schema'];
		$run->checked[$type] = (($run->checked[$type] ?? 0) + 1);
		$resolved = $this->resolve(
			object: $object,
			scope: new ExampleSetResolveScope(context: $run->objectContext(), planned: $run->planned)
		);
		if ($resolved['refusal'] !== '') {
			$run->refusals[] = $this->objectWhere(run: $run, object: $object).': '.$resolved['refusal'];
			$run->objects[$object['key']] = $this->standIn(name: $run->fragment['app'].'/'.$object['key'], data: []);
			return;
		}

		$this->planObject(run: $run, declared: new ExampleSetResolvedObject(object: $object, resolved: $resolved));
	}//end checkObject()

	/**
	 * Validate a resolved object and its files, and let it stand in for the objects declared after it.
	 *
	 * @param ExampleSetCheckRun       $run      The check; its refusals, planned objects and objects so far grow.
	 * @param ExampleSetResolvedObject $declared The declared object and its resolution.
	 *
	 * @return void
	 */
	private function planObject(ExampleSetCheckRun $run, ExampleSetResolvedObject $declared): void {
		$object   = $declared->object;
		$messages = array_merge(
			$this->objects->store->refusalsFor(
				table: new RegisterSchemaRef(register: $object['register'], schema: $object['schema']),
				check: new ObjectCheck(object: $declared->resolved['sent'], plannedUsers: $run->users)
			),
			$this->writers->files->refusals(dir: (string) $run->fragment['dir'], object: $object)
		);
		foreach ($messages as $message) {
			$run->refusals[] = $this->objectWhere(run: $run, object: $object).': '.$message;
		}

		$name         = $run->fragment['app'].'/'.$object['key'];
		$standIn      = $this->standIn(name: $name, data: $declared->resolved['data']);
		$run->planned[] = ['register' => $object['register'], 'schema' => $object['schema'], 'row' => $standIn];
		$kept         = $this->kept(record: $run->record, name: $name)['row'];
		$run->objects[$object['key']] = ($kept ?? $standIn);
		$run->files[$object['key']]   = $this->plannedFileIds(run: $run, object: $object);
	}//end planObject()

	/**
	 * Where a check names a declared object: the fragment file, the key, the register and schema.
	 *
	 * @param ExampleSetCheckRun   $run    The check.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return string
	 */
	private function objectWhere(ExampleSetCheckRun $run, array $object): string {
		return $run->fragment['file'].': object "'.$object['key'].'" ('.$object['register'].' '.$object['schema'].')';
	}//end objectWhere()

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
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	public function install(array $plan, array $adopt=[], string $password='', ?DateTimeImmutable $today=null): array {
		$today = ($today ?? $this->today(timezone: (string) $plan['timezone']));
		return $this->installRequested(request: new ExampleSetInstallRequest(plan: $plan, adopt: $adopt, password: $password, today: $today));
	}//end install()

	/**
	 * Install a set as install() describes it, one run of a set at a time.
	 *
	 * @param ExampleSetInstallRequest $request The plan, the adopted account ids, the password and the install day.
	 *
	 * @return array<string, mixed> As install() returns it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::runAs() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function installRequested(ExampleSetInstallRequest $request): array {
		$report = self::EMPTY_REPORT;
		$set    = (string) $request->plan['set'];
		if ($this->lock !== null && $this->lock->hold(set: $set) === false) {
			$report['refusals'] = [$this->lock->busy(set: $set)];
			return $report;
		}

		try {
			// Every write of the run is the set's import, its run the set, so a history can leave the set's upkeep
			// out and the trail can find one load. A row the set declares with its own cause keeps that cause.
			return WriteCause::runAs(
				frame: new CauseFrame(cause: WriteCause::IMPORT, run: 'example-set:'.$set),
				operation: fn (): array => $this->installHeld(request: $request, report: $report)
			);
		} finally {
			$this->lock?->release(set: $set);
		}
	}//end installRequested()

	/**
	 * Install a set this run holds: check, then write what the record lacks, then prove it.
	 *
	 * @param ExampleSetInstallRequest $request The plan, the adopted account ids, the password and the install day.
	 * @param array<string, mixed>     $report  The empty report.
	 *
	 * @return array<string, mixed> The report, as install() returns it.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-install-proves-what-arrived-req-exs-006
	 */
	private function installHeld(ExampleSetInstallRequest $request, array $report): array {
		$plan                = $request->plan;
		$checked             = $this->checked(request: $request);
		$report['refusals']  = $checked['refusals'];
		$report['registers'] = $checked['registers'];
		if ($report['refusals'] !== []) {
			return $report;
		}

		$run         = new ExampleSetInstallRun(plan: $plan, record: $this->objects->record->read(set: (string) $plan['set']));
		$terms       = new ExampleSetAccountTerms(set: $run->set(), adopt: $request->adopt, password: $request->password);
		$accounts    = new ExampleSetAccountsInstall(plan: $plan, terms: $terms);
		$run->report = array_merge($report, $this->setup->people->install(install: $accounts, record: $run->record));
		$run->base   = $this->withIds(plan: $plan, base: $this->setup->prerequisites->context(plan: $plan, today: $request->today));
		// A grant goes only to a group this set created (ExampleSetGrants).
		$run->base['setGroups'] = $run->record['groups'];
		foreach ($plan['fragments'] as $fragment) {
			$this->installFragment(run: $run, fragment: $fragment);
		}

		return $this->proven(run: $run);
	}//end installHeld()

	/**
	 * The context with the database id of every register and schema the set names by slug.
	 *
	 * Asked after the required registers are imported, so their ids are there
	 * in a check too. A slug the instance does not have gets no id, and its
	 * placeholder refuses the set.
	 *
	 * @param array<string, mixed> $plan The plan.
	 * @param array<string, mixed> $base The context so far.
	 *
	 * @return array<string, mixed> The context with `registers` (id by slug) and `schemas` (id by `register/schema`).
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-register-or-schema-is-named-by-slug-and-written-as-its-id-req-exs-021
	 */
	private function withIds(array $plan, array $base): array {
		$text              = (string) json_encode($plan['fragments'], (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$base['registers'] = [];
		$base['schemas']   = [];
		preg_match_all('/\{\{\s*register:\s*([^{}\s]+)\s*\}\}/', $text, $registers);
		foreach (array_unique($registers[1]) as $slug) {
			$base['registers'][$slug] = $this->objects->store->registerId(register: $slug);
		}

		preg_match_all('/\{\{\s*schema:\s*([^{}\s\/]+)\/([^{}\s]+)\s*\}\}/', $text, $schemas, PREG_SET_ORDER);
		foreach ($schemas as $match) {
			$base['schemas'][$match[1].'/'.$match[2]] = $this->objects->store->schemaId(register: $match[1], schema: $match[2]);
		}

		return $base;
	}//end withIds()
	/**
	 * Check the changes of one fragment: each resolves, finds its object, and leaves it valid.
	 *
	 * The changed object is validated as it would be written, so a field kept
	 * as JSON text is validated as text, as when the object was created.
	 *
	 * @param ExampleSetCheckRun   $run     The check: the fragment, the account ids the set creates (valid for a
	 *                                      `user` format), and the counts of changes per register and schema.
	 * @param array<string, mixed> $context `cast`, `objects` and `today`.
	 *
	 * @return array<int, string> Refusals.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	private function checkChanges(ExampleSetCheckRun $run, array $context): array {
		$fragment = $run->fragment;
		$refusals = [];
		foreach ($fragment['changes'] as $change) {
			$change = $this->withObjectJsonFields(change: $change, fragment: $fragment);
			$type   = $change['register'].' '.$change['schema'];
			$run->checkedChanges[$type] = (($run->checkedChanges[$type] ?? 0) + 1);
			$where    = $fragment['file'].': change "'.$change['key'].'" ('.$type.')';
			$resolved = $this->writers->changes->resolve(change: $change, context: $context);
			if ($resolved['refusal'] !== '') {
				$refusals[] = $where.': '.$resolved['refusal'];
				continue;
			}

			$merged = $resolved['merged'];
			unset($merged['@self'], $merged['id']);
			$messages = $this->objects->store->refusalsFor(
				table: new RegisterSchemaRef(register: $change['register'], schema: $change['schema']),
				check: new ObjectCheck(object: $merged, plannedUsers: $run->users)
			);
			foreach ($messages as $message) {
				$refusals[] = $where.': '.$message;
			}
		}

		return $refusals;
	}//end checkChanges()

	/**
	 * Write the objects and changes of one fragment that the record does not hold.
	 *
	 * @param ExampleSetInstallRun $run      The install; its report, record and what it sent grow.
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return void
	 */
	private function installFragment(ExampleSetInstallRun $run, array $fragment): void {
		$run->fragment = $fragment;
		$run->objects  = [];
		$run->files    = [];
		foreach ($fragment['objects'] as $object) {
			$this->installObject(run: $run, object: $object);
		}

		$run->context = (['objects' => $run->objects] + $run->base);
		// A refresh updates the flows too, so it is known before they are written.
		$run->context['refresh'] = $run->refresh();
		$this->installFragmentRows(run: $run);
	}//end installFragment()

	/**
	 * Write one declared object the record does not hold, or keep (and refresh) the one it holds.
	 *
	 * @param ExampleSetInstallRun $run    The install; its report, record and objects so far grow.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return void
	 */
	private function installObject(ExampleSetInstallRun $run, array $object): void {
		$type = $object['register'].' '.$object['schema'];
		$name = $run->fragment['app'].'/'.$object['key'];
		$run->report['types'][$type] ??= ['declared' => 0, 'created' => 0, 'kept' => 0, 'arrived' => 0];
		$run->report['types'][$type]['declared']++;

		$held = $this->kept(record: $run->record, name: $name);
		if ($held['error'] !== '') {
			// A read that failed proves nothing about the object: writing it again would leave two copies and
			// forget the original, so nothing is written and the recorded id stays.
			$run->report['dropped'][] = $run->fragment['file'].': object "'.$object['key'].'": the object the record holds could not be read ('
				.$held['error'].')';
			$run->objects[$object['key']] = ['id' => (string) $run->record['objects'][$name]['id']];
			return;
		}

		if ($held['row'] !== null) {
			$this->keepObject(run: $run, held: new ExampleSetKeptObject(object: $object, row: $held['row'], context: []));
			return;
		}

		$this->createObject(run: $run, object: $object);
	}//end installObject()

	/**
	 * Keep an object an earlier run wrote: attach the files declared after it, then refresh its references or, on
	 * `--refresh`, rewrite it.
	 *
	 * @param ExampleSetInstallRun $run  The install; its report, record and objects so far grow.
	 * @param ExampleSetKeptObject $held The declared object and the object as stored.
	 *
	 * @return void
	 */
	private function keepObject(ExampleSetInstallRun $run, ExampleSetKeptObject $held): void {
		$object = $held->object;
		$name   = $run->fragment['app'].'/'.$object['key'];
		$run->report['types'][$object['register'].' '.$object['schema']]['kept']++;
		if (isset($run->record['files'][$name]) === false) {
			$this->attachFiles(run: $run, object: $object);
		}

		$run->files[$object['key']] = $this->heldFileIds(record: $run->record, name: $name);
		$held = new ExampleSetKeptObject(object: $object, row: $held->row, context: $run->objectContext());
		$run->objects[$object['key']] = $this->refreshedOrRewritten(run: $run, held: $held);

		$set  = $run->set();
		$save = fn (array $kept) => $this->objects->record->write(set: $set, record: $kept);
		$this->writers->rewriter->follow(record: $run->record, name: $name, written: $run->written, save: $save);
	}//end keepObject()

	/**
	 * A kept object after this run: with its references refreshed, or rewritten on `--refresh`.
	 *
	 * @param ExampleSetInstallRun $run  The install; its report and what it sent grow.
	 * @param ExampleSetKeptObject $held The declared object, the object as stored and the context its placeholders draw on.
	 *
	 * @return array<string, mixed> The object as stored now.
	 */
	private function refreshedOrRewritten(ExampleSetInstallRun $run, ExampleSetKeptObject $held): array {
		if ($run->refresh() === false) {
			return $this->refreshReferences(run: $run, held: $held);
		}

		return $this->writers->rewriter->rewrite(
			run: ['store' => $this->objects->store, 'fragment' => $run->fragment, 'object' => $held->object, 'row' => $held->row],
			resolved: $this->resolve(object: $held->object, scope: new ExampleSetResolveScope(context: $held->context)),
			report: $run->report,
			written: $run->written
		);
	}//end refreshedOrRewritten()

	/**
	 * Write a declared object the record does not hold, and record it.
	 *
	 * @param ExampleSetInstallRun $run    The install; its report, record, what it sent and objects so far grow.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return void
	 */
	private function createObject(ExampleSetInstallRun $run, array $object): void {
		$resolved = $this->resolve(object: $object, scope: new ExampleSetResolveScope(context: $run->objectContext()));
		$made     = ['id' => '', 'error' => $resolved['refusal']];
		if ($resolved['refusal'] === '') {
			$made = $this->objects->store->create(
				register: $object['register'],
				schema: $object['schema'],
				data: $resolved['sent'],
				owner: $resolved['owner']
			);
		}

		if ($made['id'] === '') {
			$run->report['dropped'][] = $run->fragment['file'].': object "'.$object['key'].'": '.$made['error'];
			return;
		}

		$name = $run->fragment['app'].'/'.$object['key'];
		$run->report['types'][$object['register'].' '.$object['schema']]['created']++;
		$run->record['objects'][$name] = ['register' => $object['register'], 'schema' => $object['schema'], 'id' => $made['id']];
		$this->recordCreated(run: $run, created: new ExampleSetResolvedObject(object: $object, resolved: $resolved));
	}//end createObject()

	/**
	 * Record a just-written object: the references it was written with, what was sent, its files, and the row as
	 * stored (a later object may quote a field the register made itself, such as a case number).
	 *
	 * @param ExampleSetInstallRun     $run     The install; the record holds the object's id.
	 * @param ExampleSetResolvedObject $created The declared object and its resolution.
	 *
	 * @return void
	 */
	private function recordCreated(ExampleSetInstallRun $run, ExampleSetResolvedObject $created): void {
		$object = $created->object;
		$name   = $run->fragment['app'].'/'.$object['key'];
		$data   = $created->resolved['data'];
		$refs   = array_intersect_key($data, array_flip($this->referenceFields(object: $object)));
		if ($refs !== []) {
			$run->record['objects'][$name]['refs'] = $refs;
		}

		$this->written(run: $run);
		$run->written[$name] = $data;
		$this->attachFiles(run: $run, object: $object);
		$run->files[$object['key']] = $this->heldFileIds(record: $run->record, name: $name);

		$id = (string) $run->record['objects'][$name]['id'];
		$ref = new ObjectRef(register: $object['register'], schema: $object['schema'], id: $id);
		$run->objects[$object['key']] = ($this->objects->store->row(ref: $ref) ?? ['id' => $id]);
	}//end recordCreated()

	/**
	 * Write the record as the run holds it now.
	 *
	 * @param ExampleSetInstallRun $run The install.
	 *
	 * @return void
	 */
	private function written(ExampleSetInstallRun $run): void {
		$this->objects->record->write(set: $run->set(), record: $run->record);
	}//end written()

	/**
	 * Write the rows of one fragment after its objects: the engine rows a change may quote, the changes, the app
	 * config, the other engine rows, and the user preferences (after the views, which a preference may quote).
	 *
	 * @param ExampleSetInstallRun $run The install; its context holds the fragment's objects.
	 *
	 * @return void
	 */
	private function installFragmentRows(ExampleSetInstallRun $run): void {
		$fragment = $run->fragment;
		// Before the changes, so a change can quote a flow or an access link (`{{flow:key.uuid}}`, `{{accessLink:key.url}}`).
		$write = new ExampleSetFragmentWrite(fragment: $fragment, context: $run->context, values: $this->objects->values);
		$ledger = new RecordLedger(report: $run->report, record: $run->record);
		if ($this->writers->engineRows?->installBeforeChanges(write: $write, ledger: $ledger) === true) {
			$this->written(run: $run);
		}

		$run->context['flows']       = $this->objects->values->flowsOf(fragment: $fragment, record: $run->record);
		$run->context['accessLinks'] = ($this->writers->engineRows?->accessLinksOf(fragment: $fragment, record: $run->record) ?? []);
		$this->installChanges(run: $run);
		$this->installAppConfig(run: $run);

		$write = new ExampleSetFragmentWrite(fragment: $fragment, context: $run->context, values: $this->objects->values);
		$ledger = new RecordLedger(report: $run->report, record: $run->record);
		if ($this->writers->engineRows?->install(write: $write, ledger: $ledger) === true) {
			$this->written(run: $run);
		}

		// After the views, so a preference can quote one (`{{view:key.uuid}}`).
		$run->context['views'] = $this->objects->values->viewsOf(fragment: $fragment, record: $run->record);
		$this->installUserPreferences(run: $run);
	}//end installFragmentRows()

	/**
	 * Point a kept object at the objects its declaration references now.
	 *
	 * An object from an earlier run is kept, but an object it references may
	 * have been removed and written again with a new id since (a partial
	 * remove). Every top-level field whose declared value holds an
	 * `{{object:}}` or `{{lookup:}}` placeholder is resolved again; a field
	 * that differs from what is stored is written, recorded as the change
	 * `<app>/<key>#references`, so the proof checks it and remove stays exact.
	 * The first recorded old value of a field is kept over later refreshes.
	 *
	 * @param ExampleSetInstallRun $run  The install; the report gains the outcome under `changes`, the record the refresh.
	 * @param ExampleSetKeptObject $held The declared object, the object as stored and the context its placeholders draw on.
	 *
	 * @return array<string, mixed> The object as stored after the refresh.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	private function refreshReferences(ExampleSetInstallRun $run, ExampleSetKeptObject $held): array {
		$object = $held->object;
		if ($this->referenceFields(object: $object) === []) {
			return $held->row;
		}

		$name = $run->fragment['app'].'/'.$object['key'].'#references';
		$made = $this->refreshedReferences(run: $run, held: $held);
		if ($made['error'] !== '') {
			$run->report['dropped'][]      = $run->fragment['file'].': object "'.$object['key'].'": its references cannot be refreshed: '.$made['error'];
			$run->report['changes'][$name] = 'failed';
			return $held->row;
		}

		if ($made['entry'] === null) {
			return $held->row;
		}

		$this->recordReferences(run: $run, refreshed: new ExampleSetRecordedObject(name: $name, entry: $made['entry']));

		$ref = new ObjectRef(register: $object['register'], schema: $object['schema'], id: (string) $made['entry']['id']);

		return ($this->objects->store->row(ref: $ref) ?? $held->row);
	}//end refreshReferences()

	/**
	 * Resolve a kept object's reference fields again and write the ones that differ; the fields somebody changed
	 * since the install are left, and named under `left`.
	 *
	 * @param ExampleSetInstallRun $run  The install; the report gains what is left.
	 * @param ExampleSetKeptObject $held The declared object, the object as stored and the context its placeholders draw on.
	 *
	 * @return array{entry: array<string, mixed>|null, error: string} The change entry the refresh made, or why it failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	private function refreshedReferences(ExampleSetInstallRun $run, ExampleSetKeptObject $held): array {
		$object   = $held->object;
		$key      = $run->fragment['app'].'/'.$object['key'];
		$resolved = $this->resolve(object: $object, scope: new ExampleSetResolveScope(context: $held->context));
		if ($resolved['refusal'] !== '') {
			return ['entry' => null, 'error' => $resolved['refusal']];
		}

		$made = $this->writers->changes->refresh(
			object: $object,
			row: $held->row,
			values: array_intersect_key($resolved['data'], array_flip($this->referenceFields(object: $object))),
			written: $this->writers->changes->writtenReferences(record: $run->record, key: $key)
		);
		foreach ($made['left'] as $field) {
			$run->report['left'][] = $run->fragment['file'].': object "'.$object['key'].'": field "'.$field
				.'" was changed after the install and is left as it is';
		}

		return $made;
	}//end refreshedReferences()

	/**
	 * Record a references refresh, merged per field with the one an earlier run recorded.
	 *
	 * A field refreshed in an earlier run and not in this one keeps its entry (its newest `new` is what the install
	 * last wrote, so a later run does not read it as edited by somebody), and a field refreshed again keeps the old
	 * value it was first replaced from.
	 *
	 * @param ExampleSetInstallRun     $run       The install; its record and report gain the refresh.
	 * @param ExampleSetRecordedObject $refreshed The change's record key and the entry this run made.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	private function recordReferences(ExampleSetInstallRun $run, ExampleSetRecordedObject $refreshed): void {
		$name   = $refreshed->name;
		$entry  = $refreshed->entry;
		$merged = [];
		foreach ((array) ($run->record['changes'][$name]['set'] ?? []) as $earlier) {
			$merged[(string) $earlier['path']] = $earlier;
		}

		foreach ($entry['set'] as $set) {
			$earlier = ($merged[(string) $set['path']] ?? null);
			if ($earlier !== null) {
				$set = array_merge($set, ['had' => $earlier['had'], 'old' => $earlier['old'], 'from' => $earlier['from']]);
			}

			$merged[(string) $set['path']] = $set;
		}

		$entry['set'] = array_values($merged);
		$run->record['changes'][$name] = $entry;
		$run->report['changes'][$name] = 'refreshed';
		$this->written(run: $run);
	}//end recordReferences()

	/**
	 * The top-level fields of an object whose declared value references another object.
	 *
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-kept-object-follows-the-objects-it-references-req-exs-023
	 */
	private function referenceFields(array $object): array {
		$fields = [];
		foreach ((array) ($object['data'] ?? []) as $field => $value) {
			$text = (string) json_encode($value, (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			if (str_contains((string) $field, '.') === false && preg_match('/\{\{\s*(object|lookup):/', $text) === 1) {
				$fields[] = (string) $field;
			}
		}

		return $fields;
	}//end referenceFields()
	/**
	 * Make the changes of one fragment that the record does not hold, and let go of the held ones it no longer
	 * declares.
	 *
	 * @param ExampleSetInstallRun $run The install: the fragment and the context (`cast`, `objects`, `today`); its
	 *                                  report and record grow, and the record is kept after every change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 * @spec openspec/specs/example-sets/spec.md#requirement-the-record-lets-go-of-a-held-entry-the-set-no-longer-declares
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ExampleSetRecord::letGoUndeclared() is a pure function of its arguments, shared by every part.
	 */
	private function installChanges(ExampleSetInstallRun $run): void {
		foreach ($run->fragment['changes'] as $change) {
			$this->installChange(run: $run, change: $change);
		}

		$declaring = new FragmentPart(fragment: $run->fragment, part: self::changesPart());
		if (ExampleSetRecord::letGoUndeclared(declaring: $declaring, ledger: new RecordLedger(report: $run->report, record: $run->record)) === true) {
			$this->written(run: $run);
		}
	}//end installChanges()

	/**
	 * Make one declared change the record does not hold, or follow the one it holds.
	 *
	 * @param ExampleSetInstallRun $run    The install; its report and record grow.
	 * @param array<string, mixed> $change The declared change.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	private function installChange(ExampleSetInstallRun $run, array $change): void {
		$change = $this->withObjectJsonFields(change: $change, fragment: $run->fragment);
		$name   = $run->fragment['app'].'/'.$change['key'];
		$run->report['changes'][$name] = 'kept';
		if (isset($run->record['changes'][$name]) === true) {
			$made = $this->writers->changes->follow(change: $change, entry: $run->record['changes'][$name], context: $run->context, refresh: $run->refresh());
			$this->hold(run: $run, followed: new ExampleSetFollowedEntry(part: 'changes', name: $name, made: $made));
			return;
		}

		$resolved = $this->writers->changes->resolve(change: $change, context: $run->context);
		$made     = ['error' => $resolved['refusal'], 'entry' => null];
		if ($resolved['refusal'] === '') {
			$made = $this->writers->changes->apply(change: $change, resolved: $resolved);
		}

		if ($this->recordMade(run: $run, made: new ExampleSetMadeEntry(part: 'changes', name: $name, made: $made)) === false) {
			$run->report['dropped'][] = $run->fragment['file'].': change "'.$change['key'].'": '.$made['error'];
		}
	}//end installChange()

	/**
	 * Keep a held entry the declaration still names, as the record's hold() follows it.
	 *
	 * @param ExampleSetInstallRun    $run      The install; its report and record follow the entry.
	 * @param ExampleSetFollowedEntry $followed The record part, the entry's name and what following it made.
	 *
	 * @return void
	 */
	private function hold(ExampleSetInstallRun $run, ExampleSetFollowedEntry $followed): void {
		$run->report = $this->objects->record->hold(set: $run->set(), followed: $followed, report: $run->report, record: $run->record);
	}//end hold()

	/**
	 * Record what writing one entry made: `failed` (false; the caller names why under `dropped`), or `created` and
	 * the entry kept in the record. A write that made no entry leaves it `kept`.
	 *
	 * @param ExampleSetInstallRun $run  The install; its report and record grow.
	 * @param ExampleSetMadeEntry  $made The record part, the entry's name and what the write made (`error`, `entry`).
	 *
	 * @return bool
	 */
	private function recordMade(ExampleSetInstallRun $run, ExampleSetMadeEntry $made): bool {
		if ($made->made['error'] !== '') {
			$run->report[$made->part][$made->name] = 'failed';
			return false;
		}

		if ($made->made['entry'] !== null) {
			$run->record[$made->part][$made->name] = $made->made['entry'];
			$run->report[$made->part][$made->name] = 'created';
			$this->written(run: $run);
		}

		return true;
	}//end recordMade()

	/**
	 * A change on an object of the same fragment, with that object's `jsonFields` added to its own.
	 *
	 * The object was sent with those fields as JSON text, so the change reads,
	 * validates and writes them the same way.
	 *
	 * @param array<string, mixed> $change   The declared change.
	 * @param array<string, mixed> $fragment The fragment.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-field-a-schema-keeps-as-json-text-is-sent-as-text-req-exs-012
	 */
	private function withObjectJsonFields(array $change, array $fragment): array {
		if (is_string($change['object'] ?? null) === false) {
			return $change;
		}

		foreach ($fragment['objects'] as $object) {
			if (($object['key'] ?? null) === $change['object']) {
				$change['jsonFields'] = array_values(
					array_unique(array_merge((array) ($change['jsonFields'] ?? []), (array) ($object['jsonFields'] ?? [])))
				);
				break;
			}
		}

		return $change;
	}//end withObjectJsonFields()
	/**
	 * Resolve an object's lookups and fill its data.
	 *
	 * A field named in `jsonFields` is sent as JSON text, for a schema that
	 * keeps it as text; `data` keeps the list, which is what a read hands back.
	 *
	 * @param array<string, mixed>   $object The declared object.
	 * @param ExampleSetResolveScope $scope  The context (`cast`, `objects`, `today`) and the objects a check declared
	 *                                       earlier, for lookups.
	 *
	 * @return array{data: array<string, mixed>, sent: array<string, mixed>, refusal: string, owner?: string|null} The
	 *         filled data, what to send and the declared owner, or why it cannot be filled.
	 */
	private function resolve(array $object, ExampleSetResolveScope $scope): array {
		$context = $scope->context;
		$context['lookups'] = [];
		foreach ((array) ($object['lookups'] ?? []) as $name => $lookup) {
			$lookup = (array) $lookup;
			$lookup['register'] ??= (string) $object['register'];
			$found  = $this->lookedUp(scope: new ExampleSetResolveScope(context: $context, planned: $scope->planned), lookup: $lookup);
			if ($found['id'] === '') {
				return ['data' => [], 'sent' => [], 'refusal' => 'lookup "'.$name.'" found '.$found['reason']];
			}

			$context['lookups'][(string) $name] = $found['id'];
		}

		$gaps = [];
		$data = $this->objects->values->fillObject(object: $object, context: $context, gaps: $gaps);
		if ($gaps !== []) {
			return ['data' => [], 'sent' => [], 'refusal' => 'cannot resolve '.implode(', ', array_unique($gaps))];
		}

		$owner = $this->objects->values->ownerOf(object: $object, context: $context);
		$sent  = $this->writers->changes->asSent(row: $data, fields: (array) ($object['jsonFields'] ?? []));

		return ['data' => $data, 'sent' => $sent, 'refusal' => $owner['refusal'], 'owner' => $owner['owner']];
	}//end resolve()

	/**
	 * The id of the one row a lookup by business key describes.
	 *
	 * The match is checked as well: a register that does not filter on a
	 * property hands over every row, and the first of those is not the one
	 * that was asked for. Two rows that match (two organisations may share a
	 * business key) refuse the lookup, naming the count.
	 *
	 * @param ExampleSetResolveScope $scope  The cast, the lookups found so far and the day; the objects a check
	 *                                       declared earlier.
	 * @param array<string, mixed>   $lookup `register` (the object's own unless it names another), `schema` and `where`.
	 *
	 * @return array{id: string, reason: string}
	 */
	private function lookedUp(ExampleSetResolveScope $scope, array $lookup): array {
		$gaps   = [];
		$where  = (array) $this->objects->values->fill(value: (array) ($lookup['where'] ?? []), context: $scope->context, gaps: $gaps);
		$schema = (string) ($lookup['schema'] ?? '');
		$from   = (string) $lookup['register'];
		if ($gaps !== []) {
			return ['id' => '', 'reason' => 'an unresolvable '.implode(', ', array_unique($gaps))];
		}

		if ($where === [] || $schema === '') {
			return ['id' => '', 'reason' => 'nothing: a lookup needs a schema and a where'];
		}

		$rows = $this->objects->store->rowsMatching(table: new RegisterSchemaRef(register: $from, schema: $schema), where: $where);
		if (count($rows) > 1) {
			return ['id' => '', 'reason' => count($rows).' '.$from.' '.$schema.' rows with '.$this->asWords(where: $where).'; a lookup needs exactly one'];
		}

		if ($rows !== []) {
			return ['id' => $this->objects->store->idOf(row: $rows[0]), 'reason' => ''];
		}

		$planned = array_filter($scope->planned, static fn (array $object): bool => $object['register'] === $from && $object['schema'] === $schema);
		$id      = $this->plannedMatch(planned: $planned, where: $where);
		if ($id !== '') {
			return ['id' => $id, 'reason' => ''];
		}

		return ['id' => '', 'reason' => 'no '.$from.' '.$schema.' with '.$this->asWords(where: $where)];
	}//end lookedUp()

	/**
	 * The asked values as words for a refusal.
	 *
	 * @param array<string, mixed> $where The asked values, by field.
	 *
	 * @return string
	 */
	private function asWords(array $where): string {
		$words = [];
		foreach ($where as $field => $value) {
			$words[] = $field.' '.json_encode($value, JSON_UNESCAPED_UNICODE);
		}

		return implode(' and ', $words);
	}//end asWords()
	/**
	 * The stand-in id of an object declared earlier in the set that a lookup describes, or ''.
	 *
	 * @param array<int, array<string, mixed>> $planned Objects a check pass declared earlier, in the register and
	 *                                                  schema looked in.
	 * @param array<string, mixed>             $where   The asked values.
	 *
	 * @return string
	 */
	private function plannedMatch(array $planned, array $where): string {
		foreach ($planned as $object) {
			if ($this->objects->store->rowMatches(row: $object['row'], where: $where) === true) {
				return (string) $object['row']['id'];
			}
		}

		return '';
	}//end plannedMatch()

	/**
	 * The row the record holds for an object, when it is still there.
	 *
	 * A read that failed is not "it is gone": the error comes back and the caller must not write the object again.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $name   The object's record key (`app/key`).
	 *
	 * @return array{row: array<string, mixed>|null, error: string} The row; null with '' when the record holds none or the
	 *                                                              instance lost it; null with why when the read failed.
	 */
	private function kept(array $record, string $name): array {
		$held = ($record['objects'][$name] ?? null);
		if ($held === null) {
			return ['row' => null, 'error' => ''];
		}

		return $this->objects->store->readRow(ref: new ObjectRef(register: $held['register'], schema: $held['schema'], id: $held['id']));
	}//end kept()

	/**
	 * A row that stands in for an object a check does not write.
	 *
	 * @param string               $name The object's record key (`app/key`).
	 * @param array<string, mixed> $data Its filled data, or [] when it could not be filled.
	 *
	 * @return array<string, mixed>
	 */
	private function standIn(string $name, array $data): array {
		$hash = md5($name);
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
	 * The ids of the files the set attached to one object, in the order the
	 * fragment declares them, for `{{file:key.n}}`.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $name   The object's record key (`app/key`).
	 *
	 * @return array<int, int> The file ids.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	private function heldFileIds(array $record, string $name): array {
		return array_map(
			static fn (array $file): int => (int) ($file['id'] ?? 0),
			array_values((array) ($record['files'][$name] ?? []))
		);
	}//end heldFileIds()
	/**
	 * The file ids `check` stands in for an object's declared files: the ids an
	 * earlier install attached, else 1..n, so a field that holds a file id is
	 * validated as the integer it will be. Nothing is written by a check.
	 *
	 * @param ExampleSetCheckRun   $run    The check: the record and the fragment's app.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return array<int, int> The file ids.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-an-object-of-a-set-may-carry-files-from-its-fragment-req-exs-013
	 */
	private function plannedFileIds(ExampleSetCheckRun $run, array $object): array {
		$held = $this->heldFileIds(record: $run->record, name: $run->fragment['app'].'/'.$object['key']);
		if ($held !== []) {
			return $held;
		}

		$count = count((array) ($object['files'] ?? []));
		if ($count === 0) {
			return [];
		}

		return range(1, $count);
	}//end plannedFileIds()

	/**
	 * Read the instance back and fill in what is missing and what was not kept.
	 *
	 * An object from an earlier run is counted, not compared: somebody may have
	 * changed it since, and that is not a loss.
	 *
	 * @param ExampleSetInstallRun $run The install: the report so far, the record as written and what this run sent.
	 *
	 * @return array<string, mixed> The finished report.
	 */
	private function proven(ExampleSetInstallRun $run): array {
		$report = $run->report;
		foreach ($run->record['objects'] as $name => $held) {
			$type   = $held['register'].' '.$held['schema'];
			$stored = $this->objects->store->row(ref: new ObjectRef(register: $held['register'], schema: $held['schema'], id: $held['id']));
			if ($stored === null) {
				$report['missing'][] = $type.' '.$name;
				continue;
			}

			if (isset($report['types'][$type]) === true) {
				$report['types'][$type]['arrived']++;
			}

			foreach ($this->objects->proof->lostPaths(declared: ($run->written[$name] ?? []), stored: $stored) as $path) {
				$report['lost'][] = $type.' '.$name.': '.$path;
			}
		}

		$report            = $this->provenParts(report: $report, record: $run->record);
		$report['missing'] = array_merge($report['missing'], $this->setup->people->missing(record: $run->record));
		$report['ok']      = ($report['dropped'] === [] && $report['missing'] === [] && $report['lost'] === []);

		return $report;
	}//end proven()

	/**
	 * Read back the changes, files and app config the record holds.
	 *
	 * @param array<string, mixed> $report The report so far.
	 * @param array<string, mixed> $record The record as written.
	 *
	 * @return array<string, mixed> The report with what is missing or not kept.
	 */
	private function provenParts(array $report, array $record): array {
		foreach ($record['changes'] as $name => $entry) {
			foreach ($this->writers->changes->lost(entry: $entry) as $path) {
				$report['lost'][] = 'change '.$name.': '.$path;
			}
		}

		foreach ($record['files'] as $name => $files) {
			$present = $this->writers->files->names(objectId: (string) ($record['objects'][$name]['id'] ?? ''));
			foreach ($files as $file) {
				if ($this->writers->files->present(file: $file, names: $present) === false) {
					$report['missing'][] = 'file '.$file['name'].' on '.$name;
				}
			}
		}

		foreach ($record['appConfig'] as $name => $entry) {
			if ($this->setup->appConfig->lost(entry: $entry) === true) {
				$report['lost'][] = 'app config '.$entry['app'].' '.$entry['key'].' ('.$name.')';
			}
		}

		$preferences = $this->setup->userPreferences;
		foreach (array_keys(array_filter($record['userPreferences'], fn (array $entry): bool => $preferences->lost(entry: $entry))) as $name) {
			$report['lost'][] = 'user preference '.$name;
		}

		return ($this->writers->engineRows ?? new ExampleSetEngineKinds())->prove(report: $report, record: $record);
	}//end provenParts()
	/**
	 * Check the app config of one fragment: each key may be written and each value resolves.
	 *
	 * @param array<string, mixed> $fragment The fragment.
	 * @param array<string, mixed> $context  The context placeholders draw on.
	 *
	 * @return array<int, string> Refusals; never a value.
	 */
	private function checkAppConfig(array $fragment, array $context): array {
		$refusals = [];
		foreach (($fragment['appConfig'] ?? []) as $entry) {
			$where   = $fragment['file'].': app config "'.$entry['key'].'"';
			$refusal = $this->setup->appConfig->refusal(at: new ExampleSetConfigKey(app: $fragment['app'], key: $entry['key']), generate: $entry['generate']);
			if ($refusal !== '') {
				$refusals[] = $where.': '.$refusal;
				continue;
			}

			$gaps = [];
			$this->objects->values->fill(value: (string) $entry['value'], context: $context, gaps: $gaps);
			if ($entry['generate'] === false && $gaps !== []) {
				$refusals[] = $where.': cannot resolve '.implode(', ', array_unique($gaps));
			}
		}

		return $refusals;
	}//end checkAppConfig()
	/**
	 * Write the app config of one fragment that the record does not hold.
	 *
	 * @param ExampleSetInstallRun $run The install: the fragment and the context placeholders draw on; its report
	 *                                  (keys, never values) and record grow.
	 *
	 * @return void
	 */
	private function installAppConfig(ExampleSetInstallRun $run): void {
		foreach (($run->fragment['appConfig'] ?? []) as $entry) {
			$this->installConfigEntry(run: $run, entry: $entry);
		}
	}//end installAppConfig()

	/**
	 * Write one app config entry the record does not hold, or raise a recorded floor.
	 *
	 * @param ExampleSetInstallRun $run   The install; its report and record grow.
	 * @param array<string, mixed> $entry The declared entry.
	 *
	 * @return void
	 */
	private function installConfigEntry(ExampleSetInstallRun $run, array $entry): void {
		$fragment = $run->fragment;
		$name     = $fragment['app'].'/'.$entry['key'];
		$run->report['appConfig'][$name] = 'kept';
		$gaps   = [];
		$target = new ExampleSetConfigValue(
			at: new ExampleSetConfigKey(app: $fragment['app'], key: $entry['key']),
			value: (string) $this->objects->values->fill(value: (string) $entry['value'], context: $run->context, gaps: $gaps),
			type: (string) ($entry['type'] ?? 'string'),
			kind: ExampleSetConfigValue::kindOf(entry: $entry)
		);
		if (isset($run->record['appConfig'][$name]) === true) {
			if (($entry['atLeast'] ?? false) === true) {
				$run->report['appConfig'][$name] = $this->raiseRecordedFloor(run: $run, floor: $target);
			}

			return;
		}

		$made = $this->setup->appConfig->apply(target: $target);
		if ($this->recordMade(run: $run, made: new ExampleSetMadeEntry(part: 'appConfig', name: $name, made: $made)) === false) {
			$run->report['dropped'][] = $fragment['file'].': app config "'.$entry['key'].'": '.$made['error'];
		}
	}//end installConfigEntry()

	/**
	 * Bring a recorded app config entry up to the floor its declaration now names: an entry recorded before it was a
	 * floor, or with a lower one. The key is raised when it holds less; the record learns the floor, so the read-back
	 * uses it, and keeps what was there before the set for remove.
	 *
	 * @param ExampleSetInstallRun  $run   The install; its record is updated when the entry changes.
	 * @param ExampleSetConfigValue $floor The key and the declared floor, filled.
	 *
	 * @return string `raised` when the key was written, `kept` otherwise.
	 *
	 * @spec openspec/changes/example-set-recorded-floor/specs/example-sets/spec.md#requirement-a-recorded-app-config-entry-follows-a-floor-declared-later
	 */
	private function raiseRecordedFloor(ExampleSetInstallRun $run, ExampleSetConfigValue $floor): string {
		$name   = $floor->app.'/'.$floor->key;
		$raised = $this->setup->appConfig->raise(entry: $run->record['appConfig'][$name], value: $floor->value);
		if ($raised['entry'] === null) {
			return 'kept';
		}

		$run->record['appConfig'][$name] = $raised['entry'];
		$this->written(run: $run);
		if ($raised['written'] === true) {
			return 'raised';
		}

		return 'kept';
	}//end raiseRecordedFloor()

	/**
	 * Check the user preferences of one fragment: each account fills and is the set's own, and each key may be written.
	 *
	 * @param ExampleSetCheckRun   $run     The check: the fragment and the account ids the set creates or adopts.
	 * @param array<string, mixed> $context The context placeholders draw on.
	 *
	 * @return array<int, string> Refusals.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function checkUserPreferences(ExampleSetCheckRun $run, array $context): array {
		$fragment = $run->fragment;
		$refusals = [];
		foreach (($fragment['userPreferences'] ?? []) as $entry) {
			$where = $fragment['file'].': user preference "'.$entry['key'].'"';
			$gaps  = [];
			$uid   = $this->objects->values->fill(value: $entry['user'], context: $context, gaps: $gaps);
			$this->objects->values->fill(value: $entry['value'], context: $context, gaps: $gaps);
			if (is_string($uid) === false) {
				$gaps[] = (string) $entry['user'];
			}

			if ($gaps !== []) {
				$refusals[] = $where.': cannot resolve '.implode(', ', array_unique($gaps));
				continue;
			}

			$refusal = $this->setup->userPreferences->refusal(
				preference: new UserPreferenceKey(entry: ['user' => $uid, 'app' => $fragment['app'], 'key' => $entry['key']]),
				users: $run->users
			);
			if ($refusal !== '') {
				$refusals[] = $where.': '.$refusal;
			}
		}

		return $refusals;
	}//end checkUserPreferences()

	/**
	 * Write the user preferences of one fragment that the record does not hold, for the set's own accounts only.
	 *
	 * @param ExampleSetInstallRun $run The install: the fragment and the context placeholders draw on; its report and
	 *                                  record grow.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function installUserPreferences(ExampleSetInstallRun $run): void {
		foreach (($run->fragment['userPreferences'] ?? []) as $entry) {
			$this->installPreference(run: $run, entry: $entry);
		}
	}//end installUserPreferences()

	/**
	 * Write one user preference the record does not hold, or follow the one it holds.
	 *
	 * @param ExampleSetInstallRun $run   The install; its report and record grow.
	 * @param array<string, mixed> $entry The declared preference.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-set-its-own-apps-preferences-for-the-sets-own-accounts-req-exs-024
	 */
	private function installPreference(ExampleSetInstallRun $run, array $entry): void {
		$app   = $run->fragment['app'];
		$gaps  = [];
		$uid   = (string) $this->objects->values->fill(value: $entry['user'], context: $run->context, gaps: $gaps);
		$value = (string) $this->objects->values->fill(value: $entry['value'], context: $run->context, gaps: $gaps);
		$name  = $app.'/'.$uid.'/'.$entry['key'];
		$run->report['userPreferences'][$name] = 'kept';
		if (isset($run->record['userPreferences'][$name]) === true) {
			$held = $run->record['userPreferences'][$name];
			$made = $this->setup->userPreferences->kept(entry: $held);
			if ($run->refresh() === true && $gaps === []) {
				$made = $this->setup->userPreferences->follow(entry: $held, value: $value);
			}

			$this->hold(run: $run, followed: new ExampleSetFollowedEntry(part: 'userPreferences', name: $name, made: $made));
			return;
		}

		// Checked again against the record: only an account this set created or adopted.
		$preference = new UserPreferenceKey(entry: ['user' => $uid, 'app' => $app, 'key' => $entry['key']]);
		$why        = $this->setup->userPreferences->refusal(preference: $preference, users: array_keys($run->record['users']));
		if ($why === '' && $gaps !== []) {
			$why = 'cannot resolve '.implode(', ', $gaps);
		}

		$made = ['error' => $why, 'entry' => null];
		if ($why === '') {
			$made = $this->setup->userPreferences->apply(preference: $preference, value: $value);
		}

		if ($this->recordMade(run: $run, made: new ExampleSetMadeEntry(part: 'userPreferences', name: $name, made: $made)) === false) {
			$run->report['dropped'][] = $run->fragment['file'].': user preference "'.$entry['key'].'" for '.$uid.': '.$made['error'];
		}
	}//end installPreference()

	/**
	 * Attach the files of a just-written object and record them. A kept object gets the files declared after it was
	 * written, once. The object's id is the one the record holds for it.
	 *
	 * @param ExampleSetInstallRun $run    The install; the record holds the object's id and gains the files.
	 * @param array<string, mixed> $object The declared object.
	 *
	 * @return void
	 */
	private function attachFiles(ExampleSetInstallRun $run, array $object): void {
		if (($object['files'] ?? []) === []) {
			return;
		}

		$fragment = $run->fragment;
		$name     = $fragment['app'].'/'.$object['key'];
		$objectId = (string) $run->record['objects'][$name]['id'];
		$attached = $this->writers->files->attach(dir: (string) $fragment['dir'], object: $object, objectId: $objectId);
		if ($attached['files'] !== []) {
			$run->record['files'][$name] = $attached['files'];
			$this->written(run: $run);
		}

		$run->report['files'] += count($attached['files']);
		foreach ($attached['errors'] as $error) {
			$run->report['dropped'][] = $fragment['file'].': object "'.$object['key'].'": '.$error;
		}
	}//end attachFiles()

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
	/**
	 * The record part of the changes a set makes to objects it did not create.
	 *
	 * @return ExampleSetRecordPart
	 */
	private static function changesPart(): ExampleSetRecordPart {
		return new ExampleSetRecordPart(part: 'changes', noun: 'change');
	}//end changesPart()

}//end class
