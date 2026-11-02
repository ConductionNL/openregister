<?php

/**
 * One install or removal of a set at a time: a second run while one is
 * going writes nothing, because each run writes the whole record from its
 * own copy and the later writer would drop what the other recorded.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-one-install-or-removal-of-a-set-runs-at-a-time
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetLock;
use OCP\Lock\ILockingProvider;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 */
class ExampleSetOneRunAtATimeTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
		]);
	}

	public function testAnInstallWhileAnotherRunsWritesNothingAndSaysWhy(): void {
		$this->locks->acquireLock(ExampleSetLock::path(set: 'demo'), ILockingProvider::LOCK_EXCLUSIVE);

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame([(new ExampleSetLock(locks: $this->locks))->busy(set: 'demo')], $report['refusals']);
		$this->assertFalse($report['ok']);
		$this->assertSame([], $this->accounts->users, 'no account is written');
		$this->assertSame(0, count($this->store->ids(register: 'cases', schema: 'case')), 'no object is written');
		$this->assertArrayNotHasKey('example_set_demo', $this->config, 'the record is not touched');
	}

	public function testAnInstallLetsGoOfTheSetWhenItEnds(): void {
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);
		$this->assertSame([], $this->locks->held, 'the finished install holds no lock');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok'], 'the next run may go');
		$this->assertSame(1, count($this->store->ids(register: 'cases', schema: 'case')), 'the second run wrote nothing twice');
	}

	public function testARemovalWhileAnInstallRunsRemovesNothing(): void {
		$plan = $this->catalogue->gather(set: 'demo');
		$this->assertTrue($this->installer->install(plan: $plan, today: $this->today())['ok']);
		$record = $this->config['example_set_demo'];
		$this->locks->acquireLock(ExampleSetLock::path(set: 'demo'), ILockingProvider::LOCK_EXCLUSIVE);

		$report = $this->remover->remove(set: 'demo');

		$this->assertSame([(new ExampleSetLock(locks: $this->locks))->busy(set: 'demo')], $report['failed']);
		$this->assertSame(1, count($this->store->ids(register: 'cases', schema: 'case')), 'the object stays');
		$this->assertSame($record, $this->config['example_set_demo'], 'the record stays');
	}

	public function testOtherSetsAreNotHeldUp(): void {
		$this->locks->acquireLock(ExampleSetLock::path(set: 'other'), ILockingProvider::LOCK_EXCLUSIVE);

		$this->assertTrue($this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['ok']);
	}
}
