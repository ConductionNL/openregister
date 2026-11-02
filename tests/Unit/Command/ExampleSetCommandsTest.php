<?php

/**
 * Tests for the example-set commands, through the command: exit codes 0, 1
 * and 2, the password shown once, `--check`, `--adopt`, `OC_PASS`, remove and list.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\ExampleSetInstallCommand;
use OCA\OpenRegister\Command\ExampleSetListCommand;
use OCA\OpenRegister\Command\ExampleSetRemoveCommand;
use OCA\OpenRegister\Tests\Unit\Service\ExampleSet\ExampleSetTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Command\ExampleSetInstallCommand
 * @covers \OCA\OpenRegister\Command\ExampleSetRemoveCommand
 * @covers \OCA\OpenRegister\Command\ExampleSetListCommand
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 */
class ExampleSetCommandsTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'brp', schema: 'person');
		$this->app(
			app: 'openregister',
			fragment: $this->castFile(objects: [['key' => 'brp-sanne', 'register' => 'brp', 'schema' => 'person', 'data' => ['bsn' => '{{cast:sanne.bsn}}']]])
		);
		$this->app(app: 'alpha', fragment: ['after' => ['absent'], 'objects' => []]);
	}

	protected function tearDown(): void {
		putenv('OC_PASS');
		parent::tearDown();
	}

	private function install(): CommandTester {
		return new CommandTester(new ExampleSetInstallCommand(catalogue: $this->catalogue, installer: $this->installer));
	}

	public function testACompleteInstallExitsZeroAndShowsEachPasswordOnce(): void {
		$tester = $this->install();

		$this->assertSame(0, $tester->execute(['set' => 'demo']));
		$display = $tester->getDisplay();
		$this->assertStringContainsString('fragments from: openregister, alpha', $display);
		$this->assertStringContainsString('Skipped: absent is not enabled', $display);
		$this->assertStringContainsString('  sanne.devries: generated-1', $display);
		$this->assertStringContainsString('brp person: 1 declared, 1 created, 0 already there, 1 found afterwards', $display);

		$this->assertSame(0, $tester->execute(['set' => 'demo']));
		$this->assertStringNotContainsString('generated-', $tester->getDisplay());
		$this->assertStringContainsString('Account sanne.devries: already there', $tester->getDisplay());
	}

	/**
	 * `--refresh` goes over the objects the set holds and says how many it wrote and how many already held their values.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-refresh-writes-only-an-object-whose-values-differ
	 */
	public function testRefreshRewritesWhatTheSetHolds(): void {
		$tester = $this->install();
		$this->assertSame(0, $tester->execute(['set' => 'demo']));

		$this->assertSame(0, $tester->execute(['set' => 'demo', '--refresh' => true]));
		$this->assertStringContainsString('brp person: 1 declared, 0 created, 1 already there (0 refreshed, 1 unchanged), 1 found afterwards', $tester->getDisplay());
	}

	public function testAnUnknownSetExitsOne(): void {
		$tester = $this->install();

		$this->assertSame(1, $tester->execute(['set' => 'nope']));
		$this->assertStringContainsString('There is no example set "nope"', $tester->getDisplay());
	}

	public function testAForeignAccountExitsOneAndAdoptLetsItThrough(): void {
		$this->accounts->users['sanne.devries'] = ['displayName' => 'Sanne', 'email' => '', 'password' => 'theirs'];
		$tester = $this->install();

		$this->assertSame(1, $tester->execute(['set' => 'demo']));
		$this->assertStringContainsString('none of its accounts, groups or objects was written', $tester->getDisplay());
		$this->assertSame(0, $this->store->creates);

		$this->assertSame(0, $tester->execute(['set' => 'demo', '--adopt' => ['sanne.devries']]));
		$this->assertStringContainsString('Account sanne.devries: adopted', $tester->getDisplay());
	}

	public function testSomethingMissingExitsTwo(): void {
		$this->store->droppedFields['brp person'] = ['bsn'];
		$tester = $this->install();

		$this->assertSame(2, $tester->execute(['set' => 'demo']));
		$this->assertStringContainsString('Written but not kept: brp person openregister/brp-sanne: bsn', $tester->getDisplay());
	}

	public function testCheckWritesNothingAndExitsOneOnARefusal(): void {
		$tester = $this->install();
		$this->assertSame(0, $tester->execute(['set' => 'demo', '--check' => true]));
		$this->assertStringContainsString('brp person: 1 checked', $tester->getDisplay());

		$this->store->refusedFields['brp person'] = ['bsn'];
		$this->assertSame(1, $tester->execute(['set' => 'demo', '--check' => true]));
		$this->assertStringContainsString('Refused: openregister/lib/Settings/example-sets/demo.json: object "brp-sanne"', $tester->getDisplay());
		$this->assertSame(0, $this->store->creates);
		$this->assertSame(0, $this->accounts->userCreates);
	}

	public function testPasswordFromEnvIsUsedAndNeverPrinted(): void {
		putenv('OC_PASS=from-the-environment');
		$tester = $this->install();

		$this->assertSame(0, $tester->execute(['set' => 'demo', '--password-from-env' => true]));
		$this->assertSame('from-the-environment', $this->accounts->users['esra.demir']['password']);
		$this->assertStringNotContainsString('from-the-environment', $tester->getDisplay());
	}

	public function testPasswordFromAnEmptyEnvExitsOne(): void {
		putenv('OC_PASS');

		$this->assertSame(1, $this->install()->execute(['set' => 'demo', '--password-from-env' => true]));
		$this->assertSame(0, $this->accounts->userCreates);
	}

	public function testRemoveExitsZeroThenTwoWhenSomethingSticks(): void {
		$remove = new CommandTester(new ExampleSetRemoveCommand(remover: $this->remover));
		$this->assertSame(0, $remove->execute(['set' => 'demo']));
		$this->assertStringContainsString('not installed', $remove->getDisplay());

		$this->install()->execute(['set' => 'demo']);
		$this->store->undeletable = $this->store->ids(register: 'brp', schema: 'person');
		$this->assertSame(2, $remove->execute(['set' => 'demo']));
		$this->assertStringContainsString('Not removed: brp person openregister/brp-sanne', $remove->getDisplay());

		$this->store->undeletable = [];
		$this->assertSame(0, $remove->execute(['set' => 'demo']));
		$this->assertSame([], $this->config);
	}

	public function testListShowsTheSetsAndWhetherTheyAreInstalled(): void {
		$list = new CommandTester(new ExampleSetListCommand(catalogue: $this->catalogue, record: $this->record));

		$this->assertSame(0, $list->execute([]));
		$this->assertStringContainsString('demo (not installed): alpha, openregister', $list->getDisplay());

		$this->install()->execute(['set' => 'demo']);
		$list->execute([]);
		$this->assertStringContainsString('demo (installed): alpha, openregister', $list->getDisplay());
	}
}
