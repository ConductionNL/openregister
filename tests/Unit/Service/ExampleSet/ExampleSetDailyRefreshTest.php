<?php

/**
 * A set an administrator marks for a daily refresh is refreshed once a day, so
 * everything it dates against "today" (notes, read states, inspections,
 * deadlines) follows the day it is viewed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\BackgroundJob\ExampleSetDailyRefreshJob;
use OCA\OpenRegister\Command\ExampleSetInstallCommand;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh
 * @covers \OCA\OpenRegister\BackgroundJob\ExampleSetDailyRefreshJob
 * @covers \OCA\OpenRegister\Command\ExampleSetInstallCommand
 */
class ExampleSetDailyRefreshTest extends ExampleSetTestCase {
	/** @var array<string, string> OpenRegister's own app config for the marks. */
	private array $marks = [];

	/**
	 * A case dated "today" in a set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
		$this->app(app: 'alpha', fragment: ['objects' => [
			['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Inspectie', 'scheduledAt' => '{{date:0}}']],
		]]);
	}

	/**
	 * The daily refresh over the harness's catalogue and installer.
	 *
	 * @return ExampleSetDailyRefresh
	 */
	private function daily(): ExampleSetDailyRefresh {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = ''): string => ($this->marks[$key] ?? $default));
		$config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->marks[$key] = $value;
			return true;
		});

		return new ExampleSetDailyRefresh(appConfig: $config, catalogue: $this->catalogue, installer: $this->installer, record: $this->record);
	}//end daily()

	/**
	 * A marked, installed set is refreshed against the day of the run; an unmarked one is left alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function testAMarkedSetIsRefreshedAgainstTheDayOfTheRun(): void {
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$daily = $this->daily();
		$id    = $this->store->ids(register: 'cases', schema: 'case')[0];

		$this->assertSame([], $daily->run(today: $this->today()->modify('+1 day')), 'nothing is marked');
		$this->assertSame('2026-10-13', $this->store->get(register: 'cases', schema: 'case', id: $id)['scheduledAt']);

		$daily->mark(set: 'demo', daily: true);
		$this->assertSame(['demo'], $daily->sets());
		$report = $daily->run(today: $this->today()->modify('+1 day'));

		$this->assertTrue($report['demo']['ok'], (string) json_encode($report));
		$this->assertSame('2026-10-14', $this->store->get(register: 'cases', schema: 'case', id: $id)['scheduledAt']);

		$daily->mark(set: 'demo', daily: false);
		$this->assertSame([], $daily->sets());
	}//end testAMarkedSetIsRefreshedAgainstTheDayOfTheRun()

	/**
	 * A member the set added and an admin removed since stays removed after the daily refresh.
	 *
	 * @return void
	 */
	public function testTheRefreshDoesNotGiveBackAMembershipAnAdminRemoved(): void {
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$withMembers = array_filter($this->accounts->groups, static fn (array $members): bool => $members !== []);
		$this->assertNotSame([], $withMembers, 'the set adds members');
		$gid = (string) array_key_first($withMembers);
		$uid = (string) $withMembers[$gid][0];
		$this->accounts->removeFromGroup(gid: $gid, uid: $uid);
		$users = $this->accounts->userCreates;

		$daily = $this->daily();
		$daily->mark(set: 'demo', daily: true);
		$report = $daily->run(today: $this->today()->modify('+1 day'));

		$this->assertSame([], $report['demo']['refusals'], (string) json_encode($report));
		$this->assertFalse($this->accounts->inGroup(gid: $gid, uid: $uid), 'the removed member stays removed');
		$this->assertSame(['membership '.$gid.' / '.$uid], $report['demo']['missing'], 'the refresh reports it, it does not redo it');
		$this->assertSame($users, $this->accounts->userCreates, 'no account is made by a refresh');
	}//end testTheRefreshDoesNotGiveBackAMembershipAnAdminRemoved()

	/**
	 * A marked set that is not installed (removed since) is skipped, never installed by the job.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function testAMarkedSetThatIsNotInstalledIsSkipped(): void {
		$daily = $this->daily();
		$daily->mark(set: 'demo', daily: true);

		$this->assertSame(['demo' => ['ok' => false, 'skipped' => 'not installed']], $daily->run(today: $this->today()));
		$this->assertSame([], $this->store->ids(register: 'cases', schema: 'case'));
	}//end testAMarkedSetThatIsNotInstalledIsSkipped()

	/**
	 * `--daily` marks the set while it installs; `--no-daily` takes the mark away.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function testTheInstallCommandMarksAndUnmarksTheSet(): void {
		$daily   = $this->daily();
		$command = new CommandTester(new ExampleSetInstallCommand(catalogue: $this->catalogue, installer: $this->installer, daily: $daily));

		$command->execute(['set' => 'demo', '--refresh' => true, '--daily' => true]);
		$this->assertSame(['demo'], $daily->sets());
		$this->assertStringContainsString('Daily refresh: demo', $command->getDisplay());

		$command->execute(['set' => 'demo', '--check' => true, '--no-daily' => true]);
		$this->assertSame([], $daily->sets());
		$this->assertStringContainsString('Daily refresh: none', $command->getDisplay());
	}//end testTheInstallCommandMarksAndUnmarksTheSet()

	/**
	 * The job runs the daily refresh and logs each set, a failing one as a warning.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-daily-refresh/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function testTheJobRefreshesAndLogsEachSet(): void {
		$daily = $this->createMock(ExampleSetDailyRefresh::class);
		$daily->expects($this->once())->method('run')->willReturn(['demo' => ['ok' => true], 'other' => ['ok' => false, 'skipped' => 'not installed']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$logger->expects($this->once())->method('warning');
		$job = new ExampleSetDailyRefreshJob(time: $this->createMock(ITimeFactory::class), daily: $daily, logger: $logger);

		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end testTheJobRefreshesAndLogsEachSet()
}//end class
