<?php

/**
 * Tests for an app config entry declared `atLeast`: a running counter that the
 * set only raises, which the app moves on afterwards.
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
 *
 * @spec openspec/changes/example-set-app-config-at-least/specs/example-sets/spec.md#requirement-an-integer-app-config-entry-may-be-a-floor
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Service\ExampleSet\ExampleSetException;

/**
 * An `atLeast` entry raises a counter to its value, never lowers it, is not
 * lost when the app moves the counter on, and is not put back on remove.
 */
class ExampleSetAppConfigAtLeastTest extends ExampleSetTestCase {
	/**
	 * Register the cast, which every set needs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->app(app: 'openregister', fragment: $this->castFile());
	}//end setUp()

	/**
	 * A fragment of app `alpha` that declares the counter as a floor.
	 *
	 * @param array<string, mixed> $entry Overrides for the counter entry.
	 *
	 * @return void
	 */
	private function counter(array $entry = []): void {
		$counter = array_merge(['key' => 'counter', 'value' => '423', 'type' => 'int', 'atLeast' => true], $entry);
		$this->app(app: 'alpha', fragment: ['appConfig' => [$counter]]);
	}

	/**
	 * A counter below the floor is raised to it.
	 *
	 * @return void
	 */
	public function testALowerCounterIsRaisedToTheFloor(): void {
		$this->appValues['alpha'] = ['counter' => '7'];
		$this->appTypes['alpha']  = ['counter' => 'int'];
		$this->counter();

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: 'created', actual: $report['appConfig']['alpha/counter'], message: (string) json_encode($report));
		$this->assertSame(expected: '423', actual: $this->appValues['alpha']['counter']);
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
	}//end testALowerCounterIsRaisedToTheFloor()

	/**
	 * A counter above the floor is never lowered, and is reported kept.
	 *
	 * @return void
	 */
	public function testAHigherCounterIsNeverLowered(): void {
		$this->appValues['alpha'] = ['counter' => '450'];
		$this->appTypes['alpha']  = ['counter' => 'int'];
		$this->counter();

		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: '450', actual: $this->appValues['alpha']['counter']);
		$this->assertSame(expected: 'kept', actual: $report['appConfig']['alpha/counter'], message: (string) json_encode($report));
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
	}//end testAHigherCounterIsNeverLowered()

	/**
	 * A counter the app moved on after the install is not reported lost.
	 *
	 * @return void
	 */
	public function testACounterTheAppMovedOnIsNotLost(): void {
		$this->counter();
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->appValues['alpha']['counter'] = '431';
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(expected: [], actual: $report['lost'], message: (string) json_encode($report));
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
	}//end testACounterTheAppMovedOnIsNotLost()

	/**
	 * A counter that fell below the floor is reported lost.
	 *
	 * @return void
	 */
	public function testACounterBelowTheFloorIsLost(): void {
		$this->counter();
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->appValues['alpha']['counter'] = '12';
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertContains(needle: 'app config alpha counter (alpha/counter)', haystack: $report['lost'], message: (string) json_encode($report));
	}//end testACounterBelowTheFloorIsLost()

	/**
	 * Remove leaves a counter the app moved on after the install.
	 *
	 * @return void
	 */
	public function testRemoveLeavesACounterTheAppMovedOn(): void {
		$this->appValues['alpha'] = ['counter' => '7'];
		$this->appTypes['alpha']  = ['counter' => 'int'];
		$this->counter();
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->appValues['alpha']['counter'] = '431';
		$this->remover->remove(set: 'demo');

		$this->assertSame(expected: '431', actual: $this->appValues['alpha']['counter']);
	}//end testRemoveLeavesACounterTheAppMovedOn()

	/**
	 * A record written before floors existed holds the counter as a plain value. When the declaration becomes a floor,
	 * install raises the key to it, the read-back then uses the floor, and remove still puts back what was there first.
	 *
	 * @return void
	 */
	public function testARecordedPlainEntryBecomesAFloor(): void {
		$this->appValues['alpha'] = ['counter' => '7'];
		$this->appTypes['alpha']  = ['counter' => 'int'];
		$this->counter(entry: ['value' => '402', 'atLeast' => false]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->appValues['alpha']['counter'] = '404';

		$this->counter();
		$plan   = $this->catalogue->gather(set: 'demo');
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertSame(expected: '423', actual: $this->appValues['alpha']['counter']);
		$this->assertSame(expected: 'raised', actual: $report['appConfig']['alpha/counter'], message: (string) json_encode($report));
		$this->assertSame(expected: [], actual: $report['lost'], message: (string) json_encode($report));

		$this->appValues['alpha']['counter'] = '431';
		$this->assertSame(expected: [], actual: $this->installer->install(plan: $plan, today: $this->today())['lost']);

		$this->appValues['alpha']['counter'] = '423';
		$this->remover->remove(set: 'demo');
		$this->assertSame(expected: '7', actual: $this->appValues['alpha']['counter']);
	}//end testARecordedPlainEntryBecomesAFloor()

	/**
	 * A recorded floor lower than the declared one is raised to the declared one.
	 *
	 * @return void
	 */
	public function testARecordedLowerFloorIsRaised(): void {
		$this->counter(entry: ['value' => '400']);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->counter();
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: '423', actual: $this->appValues['alpha']['counter']);
		$this->assertSame(expected: 'raised', actual: $report['appConfig']['alpha/counter'], message: (string) json_encode($report));
	}//end testARecordedLowerFloorIsRaised()

	/**
	 * A recorded plain entry whose key the app already moved past the floor keeps that value; only the record learns
	 * the floor, so the read-back no longer compares against the old exact value.
	 *
	 * @return void
	 */
	public function testARecordedEntryAboveTheFloorKeepsItsValue(): void {
		$this->counter(entry: ['value' => '402', 'atLeast' => false]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$this->appValues['alpha']['counter'] = '450';

		$this->counter();
		$report = $this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());

		$this->assertSame(expected: '450', actual: $this->appValues['alpha']['counter']);
		$this->assertSame(expected: [], actual: $report['lost'], message: (string) json_encode($report));
	}//end testARecordedEntryAboveTheFloorKeepsItsValue()

	/**
	 * A string entry cannot be a floor.
	 *
	 * @return void
	 */
	public function testAFloorMustBeAWrittenInteger(): void {
		$this->counter(entry: ['type' => 'string']);

		$this->expectException(exception: ExampleSetException::class);
		$this->expectExceptionMessage(message: 'atLeast');
		$this->catalogue->gather(set: 'demo');
	}//end testAFloorMustBeAWrittenInteger()

	/**
	 * A generated entry cannot be a floor.
	 *
	 * @return void
	 */
	public function testAGeneratedValueCannotBeAFloor(): void {
		$this->counter(entry: ['generate' => true, 'value' => null]);

		$this->expectException(exception: ExampleSetException::class);
		$this->expectExceptionMessage(message: 'atLeast');
		$this->catalogue->gather(set: 'demo');
	}//end testAGeneratedValueCannotBeAFloor()
}//end class
