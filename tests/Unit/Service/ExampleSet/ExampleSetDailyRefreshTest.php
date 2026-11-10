<?php

/**
 * A set an administrator marks for a daily refresh is refreshed once a day, so
 * everything it dates against "today" (notes, read states, inspections,
 * deadlines) follows the day it is viewed.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\BackgroundJob\JobRuntime;
use OCA\OpenRegister\BackgroundJob\ExampleSetDailyRefreshJob;
use OCA\OpenRegister\Command\ExampleSetInstallCommand;
use OCA\OpenRegister\Kernel\Contracts\ExampleSetInstallInput;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetDailyRefresh
 * @covers \OCA\OpenRegister\BackgroundJob\ExampleSetDailyRefreshJob
 * @covers \OCA\OpenRegister\Command\ExampleSetInstallCommand
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetActingAs
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetBackgroundRun
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function testAMarkedSetIsRefreshedAgainstTheDayOfTheRun(): void {
		$this->installer->install(input: new ExampleSetInstallInput(plan: $this->catalogue->gather(set: 'demo'), today: $this->today()));
		$daily = $this->daily();
		$id    = $this->store->ids(register: 'cases', schema: 'case')[0];

		$this->assertSame([], $daily->run(today: $this->today()->modify('+1 day')), 'nothing is marked');
		$this->assertSame('2026-10-13', $this->store->row(ref: new ObjectRef(register: 'cases', schema: 'case', id: $id))['scheduledAt']);

		$daily->mark(set: 'demo', daily: true);
		$this->assertSame(['demo'], $daily->sets());
		$report = $daily->run(today: $this->today()->modify('+1 day'));

		$this->assertTrue($report['demo']['ok'], (string) json_encode($report));
		$this->assertSame('2026-10-14', $this->store->row(ref: new ObjectRef(register: 'cases', schema: 'case', id: $id))['scheduledAt']);

		$daily->mark(set: 'demo', daily: false);
		$this->assertSame([], $daily->sets());
	}//end testAMarkedSetIsRefreshedAgainstTheDayOfTheRun()

	/**
	 * A marked set that is not installed (removed since) is skipped, never installed by the job.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-may-be-refreshed-every-day
	 */
	public function testTheJobRefreshesAndLogsEachSet(): void {
		$daily = $this->createMock(ExampleSetDailyRefresh::class);
		$daily->expects($this->once())->method('run')->willReturn(['demo' => ['ok' => true], 'other' => ['ok' => false, 'skipped' => 'not installed']]);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');
		$logger->expects($this->once())->method('warning');
		$job = new ExampleSetDailyRefreshJob(runtime: new JobRuntime(time: $this->createMock(ITimeFactory::class), logger: $logger), daily: $daily);

		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}//end testTheJobRefreshesAndLogsEachSet()
}//end class
