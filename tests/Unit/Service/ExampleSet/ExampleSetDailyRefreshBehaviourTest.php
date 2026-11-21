<?php

/**
 * Behaviour tests for ExampleSetDailyRefresh: which sets are marked, and what a
 * run reports for each.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use DateTimeImmutable;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the daily-refresh marks and run.
 */
class ExampleSetDailyRefreshBehaviourTest extends TestCase {

	/** @var array<string, string> Stored app config values. */
	private array $values = [];

	/** @var ExampleSetCatalogue&MockObject */
	private ExampleSetCatalogue $catalogue;

	/** @var ExampleSetInstaller&MockObject */
	private ExampleSetInstaller $installer;

	/** @var ExampleSetRecord&MockObject */
	private ExampleSetRecord $record;

	protected function setUp(): void {
		$this->catalogue = $this->createMock(ExampleSetCatalogue::class);
		$this->installer = $this->createMock(ExampleSetInstaller::class);
		$this->record    = $this->createMock(ExampleSetRecord::class);
	}//end setUp()

	private function daily(): ExampleSetDailyRefresh {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default=''): string => ($app === 'openregister' ? ($this->values[$key] ?? $default) : 'wrong-app')
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->values[$app.'/'.$key] = $value;
				$this->values[$key]          = $value;
				return true;
			}
		);
		return new ExampleSetDailyRefresh($config, $this->catalogue, $this->installer, $this->record);
	}//end daily()

	public function testSetsIsEmptyWhenNothingIsStored(): void {
		$this->assertSame([], $this->daily()->sets());
	}

	public function testSetsIsEmptyWhenTheStoredValueIsNotAJsonList(): void {
		$this->values[ExampleSetDailyRefresh::KEY] = '"just a string"';

		$this->assertSame([], $this->daily()->sets());
	}

	public function testSetsKeepsUniqueStringsOnly(): void {
		$this->values[ExampleSetDailyRefresh::KEY] = '["a", 3, "b", "a", null]';

		$this->assertSame(['a', 'b'], $this->daily()->sets());
	}

	public function testMarkAddsOnceAndUnmarkRemoves(): void {
		$daily = $this->daily();

		$daily->mark('a', true);
		$daily->mark('b', true);
		$daily->mark('a', true);
		$this->assertSame('["b","a"]', $this->values['openregister/'.ExampleSetDailyRefresh::KEY]);

		$daily->mark('b', false);
		$this->assertSame(['a'], $daily->sets());
	}

	public function testRunSkipsASetThatIsNotInstalled(): void {
		$this->values[ExampleSetDailyRefresh::KEY] = '["demo"]';
		$this->record->method('read')->with('demo')->willReturn(['r' => 1]);
		$this->record->method('isEmpty')->with(['r' => 1])->willReturn(true);
		$this->installer->expects($this->never())->method('install');

		$this->assertSame(['demo' => ['ok' => false, 'skipped' => 'not installed']], $this->daily()->run());
	}

	public function testRunRefreshesAnInstalledSetAgainstTheGivenDay(): void {
		$today = new DateTimeImmutable('2026-03-04T00:00:00+00:00');
		$this->values[ExampleSetDailyRefresh::KEY] = '["demo"]';
		$this->record->method('read')->willReturn([]);
		$this->record->method('isEmpty')->willReturn(false);
		$this->catalogue->method('gather')->with('demo')->willReturn(['set' => 'demo', 'refresh' => false]);
		$this->installer->expects($this->once())->method('install')
			->with(['set' => 'demo', 'refresh' => false], [], '', $today)
			->willReturn(['ok' => true, 'refusals' => [], 'dropped' => ['x'], 'missing' => [], 'lost' => [], 'extra' => 'not reported']);

		$report = $this->daily()->run($today);

		$this->assertSame(['demo' => ['ok' => true, 'refusals' => [], 'dropped' => ['x'], 'missing' => [], 'lost' => []]], $report);
	}

	public function testRunAddsTheRefreshFlagWhenThePlanHasNone(): void {
		$this->values[ExampleSetDailyRefresh::KEY] = '["demo"]';
		$this->record->method('isEmpty')->willReturn(false);
		$this->record->method('read')->willReturn([]);
		$this->catalogue->method('gather')->willReturn(['set' => 'demo']);
		$this->installer->expects($this->once())->method('install')
			->with(['set' => 'demo', 'refresh' => true], [], '', null)
			->willReturn(['ok' => false, 'refusals' => ['r'], 'dropped' => [], 'missing' => [], 'lost' => []]);

		$this->assertFalse($this->daily()->run()['demo']['ok']);
	}

	public function testRunReportsAFailingInstallAsSkippedWithItsMessage(): void {
		$this->values[ExampleSetDailyRefresh::KEY] = '["demo"]';
		$this->record->method('read')->willReturn([]);
		$this->record->method('isEmpty')->willReturn(false);
		$this->catalogue->method('gather')->willThrowException(new \RuntimeException('unknown set'));

		$this->assertSame(['demo' => ['ok' => false, 'skipped' => 'unknown set']], $this->daily()->run());
	}

	public function testRunOfNoMarkedSetsIsEmpty(): void {
		$this->record->expects($this->never())->method('read');

		$this->assertSame([], $this->daily()->run());
	}
}//end class
