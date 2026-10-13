<?php

/**
 * Tests for ExampleSetInstaller and ExampleSetRemover: check before write,
 * idempotency, foreign accounts, adoption, the proof and exact removal.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 */
class ExampleSetInstallerTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'brp', schema: 'person');
		$this->store->offer(register: 'cases', schema: 'caseType');
		$this->store->offer(register: 'cases', schema: 'case');
		$this->store->seed(register: 'cases', schema: 'caseType', data: ['identifier' => 'woo']);

		$this->app(
			app: 'openregister',
			fragment: $this->castFile(objects: [
				['key' => 'brp-sanne', 'register' => 'brp', 'schema' => 'person',
					'data' => ['bsn' => '{{cast:sanne.bsn}}', 'number' => '{{cast:sanne.address.number}}']],
			])
		);
		$this->app(
			app: 'cases',
			fragment: [
				'objects' => [
					[
						'key'      => 'case-1',
						'register' => 'cases',
						'schema'   => 'case',
						'lookups'  => [
							'type'   => ['schema' => 'caseType', 'where' => ['identifier' => 'woo']],
							'person' => ['register' => 'brp', 'schema' => 'person', 'where' => ['bsn' => '{{cast:sanne.bsn}}']],
						],
						'data'     => ['type' => '{{lookup:type}}', 'applicant' => '{{lookup:person}}', 'start' => '{{date:-2}}'],
					],
					['key' => 'note-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['case' => '{{object:case-1.id}}']],
				],
				'groups'  => [['group' => 'cases-handlers', 'members' => ['esra']]],
			]
		);
	}

	public function testInstallWritesTheCastAndEveryFragmentAndProvesIt(): void {
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame([], $report['refusals']);
		$this->assertTrue($report['ok'], json_encode($report));
		$this->assertSame(['sanne.devries' => 'created', 'esra.demir' => 'created'], $report['users']);
		$this->assertSame(['sanne.devries' => 'generated-1', 'esra.demir' => 'generated-2'], $report['passwords']);
		$this->assertSame(['demo-staff' => 'created', 'cases-handlers' => 'created'], $report['groups']);
		$this->assertSame(['esra.demir'], $this->accounts->groups['cases-handlers']);
		$this->assertSame(3, $this->store->creates);

		$person = $this->store->find(register: 'brp', schema: 'person', filters: [])[0];
		$this->assertSame(14, $person['number'], 'a whole-value placeholder keeps its type');
		$case = $this->store->find(register: 'cases', schema: 'case', filters: [])[0];
		$this->assertSame($person['id'], $case['applicant'], 'a lookup reaches an object an earlier fragment wrote');
		$this->assertSame('2026-10-11', $case['start']);
		$note = $this->store->find(register: 'cases', schema: 'case', filters: [])[1];
		$this->assertSame($case['id'], $note['case']);

		$stored = json_decode($this->config['example_set_demo'], true);
		$this->assertSame(['openregister/brp-sanne', 'cases/case-1', 'cases/note-1'], array_keys($stored['objects']));
		$this->assertStringNotContainsString('generated-', $this->config['example_set_demo'], 'a password is never in the record');
	}

	public function testASecondInstallWritesNothingAndSaysAlreadyThere(): void {
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$creates = $this->store->creates;
		$users   = $this->accounts->userCreates;

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok']);
		$this->assertSame($creates, $this->store->creates);
		$this->assertSame($users, $this->accounts->userCreates);
		$this->assertSame([], $report['passwords']);
		$this->assertSame(['sanne.devries' => 'kept', 'esra.demir' => 'kept'], $report['users']);
		$this->assertSame(['declared' => 2, 'created' => 0, 'kept' => 2, 'arrived' => 2], $report['types']['cases case']);
	}

	public function testAForeignAccountRefusesTheInstallBeforeAnyWrite(): void {
		$this->accounts->users['sanne.devries'] = ['displayName' => 'Somebody', 'email' => '', 'password' => 'theirs'];

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertCount(1, $report['refusals']);
		$this->assertStringContainsString('"sanne.devries" exists and this set did not create it', $report['refusals'][0]);
		$this->assertSame(0, $this->store->creates);
		$this->assertSame(0, $this->accounts->userCreates);
		$this->assertSame([], $this->accounts->groups);
		$this->assertSame([], $this->config);
	}

	public function testAnAdoptedAccountIsUsedAndSurvivesRemove(): void {
		$this->accounts->users['sanne.devries'] = ['displayName' => 'Sanne', 'email' => '', 'password' => 'theirs'];
		$plan = $this->catalogue->gather(set: 'demo');

		$report = $this->installer->install(plan: $plan, adopt: ['sanne.devries'], today: $this->today());
		$this->assertTrue($report['ok']);
		$this->assertSame('adopted', $report['users']['sanne.devries']);

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(['sanne.devries'], $removed['left']);
		$this->assertSame('theirs', $this->accounts->users['sanne.devries']['password']);
		$this->assertArrayNotHasKey('esra.demir', $this->accounts->users);
	}

	public function testAnUnresolvedPlaceholderNamesFileKeyAndPlaceholderAndWritesNothing(): void {
		$this->app(app: 'cases', fragment: ['objects' => [['key' => 'case-9', 'register' => 'cases', 'schema' => 'case', 'data' => ['who' => '{{cast:nobody.uid}}']]]]);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(
			['cases/lib/Settings/example-sets/demo.json: object "case-9" (cases case): cannot resolve {{cast:nobody.uid}}'],
			$report['refusals']
		);
		$this->assertSame(0, $this->store->creates);
	}

	public function testAValueTheSchemaRefusesFailsTheCheckAndWritesNothing(): void {
		$this->store->refusedFields['cases case'] = ['start'];

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(['brp person' => 1, 'cases case' => 2], $result['checked']);
		$this->assertSame(['cases/lib/Settings/example-sets/demo.json: object "case-1" (cases case): The property start is not allowed'], $result['refusals']);
		$this->assertSame(0, $this->store->creates);
		$this->assertSame(3, $this->store->validations, 'the check validates every object, hard validation or not');
	}

	public function testABadBsnIsRefused(): void {
		$file = $this->castFile();
		$file['cast']['people'][0]['bsn'] = '123456789';
		$this->app(app: 'openregister', fragment: $file);

		$result = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertStringContainsString('BSN 123456789 fails the eleven test', implode("\n", $result['refusals']));
	}

	public function testAKeyTheSchemaDoesNotKeepIsNamedAsLost(): void {
		$this->store->droppedFields['brp person'] = ['number'];

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertFalse($report['ok']);
		$this->assertSame(['brp person openregister/brp-sanne: number'], $report['lost']);
	}

	public function testRemoveDeletesExactlyWhatTheInstallMade(): void {
		$foreign = $this->store->seed(register: 'cases', schema: 'case', data: ['title' => 'not ours']);
		$this->accounts->groups['cases-handlers'] = ['someone'];
		$this->accounts->users['someone']         = ['displayName' => 'Someone', 'email' => '', 'password' => 'x'];
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$report = $this->remover->remove(set: 'demo');

		$this->assertSame([], $report['failed']);
		$this->assertSame(
			[
				'files'            => 0,
				'objects'          => 3,
				'changes'          => 0,
				'app config'       => 0,
				'memberships'      => 2,
				'groups'           => 1,
				'accounts'         => 2,
				'register imports' => 0,
				'organisations'    => 0,
			],
			$report['removed']
		);
		$this->assertSame([$foreign], $this->store->ids(register: 'cases', schema: 'case'));
		$this->assertSame([], $this->store->ids(register: 'brp', schema: 'person'));
		$this->assertSame(['someone'], $this->accounts->groups['cases-handlers'], 'a group that existed before stays, with its own members');
		$this->assertArrayNotHasKey('demo-staff', $this->accounts->groups);
		$this->assertSame(['someone'], array_keys($this->accounts->users));
		$this->assertSame([], $this->config, 'the record is gone');
	}

	public function testWhatRemoveCannotDeleteStaysInTheRecord(): void {
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$stuck                     = $this->store->ids(register: 'brp', schema: 'person')[0];
		$this->store->undeletable  = [$stuck];

		$report = $this->remover->remove(set: 'demo');

		$this->assertSame(['brp person openregister/brp-sanne ('.$stuck.')'], $report['failed']);
		$this->assertSame(['openregister/brp-sanne'], array_keys($this->record->read(set: 'demo')['objects']));
	}
}
