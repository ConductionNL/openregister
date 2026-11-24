<?php

/**
 * POST /api/settings/search-index/run: queue one maintenance run.
 *
 * The button an administrator presses to rebuild the search indexes without a
 * shell. The run is the same SearchIndexRebuildJob `occ` starts; the endpoint
 * only puts it on the job list, once.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\SettingsControllerFactory;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Search\BackgroundJob\SearchIndexRebuildJob;
use OCA\OpenRegister\Service\SettingsService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SettingsController::queueSearchIndexRun(), queued, already waiting and refused.
 */
class SettingsControllerSearchIndexRunTest extends TestCase {

	/**
	 * A controller whose container hands out the given job list.
	 *
	 * @param IJobList        $jobList The job list the endpoint writes to.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return SettingsController The controller under test.
	 */
	private function controllerWith(IJobList $jobList, ?LoggerInterface $logger = null): SettingsController {
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')
			->with(IJobList::class)
			->willReturn($jobList);

		return SettingsControllerFactory::create(
			appName: 'openregister',
			request: $this->createMock(originalClassName: IRequest::class),
			config: $this->createMock(originalClassName: IAppConfig::class),
			db: $this->createMock(originalClassName: IDBConnection::class),
			container: $container,
			settingsService: $this->createMock(originalClassName: SettingsService::class),
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class))
		);
	}//end controllerWith()

	/**
	 * With nothing waiting, the rebuild job is added for every register and the answer is 202 queued.
	 *
	 * @return void
	 */
	public function testARunIsQueuedAsTheRebuildJob(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->expects($this->once())->method('has')
			->with(SearchIndexRebuildJob::class, null)
			->willReturn(false);
		$jobList->expects($this->once())->method('add')
			->with(SearchIndexRebuildJob::class, null);

		$response = $this->controllerWith(jobList: $jobList)->queueSearchIndexRun();

		$this->assertSame(expected: 202, actual: $response->getStatus());
		$this->assertSame(expected: ['queued' => true, 'alreadyQueued' => false], actual: $response->getData());
	}//end testARunIsQueuedAsTheRebuildJob()

	/**
	 * A run already waiting is not queued a second time, and the answer says so.
	 *
	 * @return void
	 */
	public function testAWaitingRunIsNotQueuedTwice(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('has')->willReturn(true);
		$jobList->expects($this->never())->method('add');

		$response = $this->controllerWith(jobList: $jobList)->queueSearchIndexRun();

		$this->assertSame(expected: 202, actual: $response->getStatus());
		$this->assertSame(expected: ['queued' => false, 'alreadyQueued' => true], actual: $response->getData());
	}//end testAWaitingRunIsNotQueuedTwice()

	/**
	 * A job list that fails answers 500 with a fixed message and logs the cause; the cause is not echoed.
	 *
	 * @return void
	 */
	public function testAFailingJobListAnswers500WithoutTheCause(): void {
		$jobList = $this->createMock(originalClassName: IJobList::class);
		$jobList->method('has')->willReturn(false);
		$jobList->method('add')->willThrowException(new RuntimeException('database is gone'));

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with(
				'[SettingsController] Could not queue the search index run',
				['exception' => 'database is gone']
			);

		$response = $this->controllerWith(jobList: $jobList, logger: $logger)->queueSearchIndexRun();

		$this->assertSame(expected: 500, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => 'The search index run could not be queued.'], actual: $response->getData());
	}//end testAFailingJobListAnswers500WithoutTheCause()
}//end class
