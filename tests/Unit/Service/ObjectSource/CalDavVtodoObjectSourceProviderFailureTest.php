<?php

/**
 * What the CalDAV VTODO object source answers when the task list fails or
 * no task answers to the id.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Db\RegisterSchema;
use OCA\OpenRegister\Service\ObjectSource\SourcedTable;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\ObjectSource\CalDavVtodoObjectSourceProvider;
use OCA\OpenRegister\Service\UserTaskListInterface;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Failure tests for {@see CalDavVtodoObjectSourceProvider}.
 */
class CalDavVtodoObjectSourceProviderFailureTest extends TestCase {

	/**
	 * A failing task list reads as no tasks, logged.
	 *
	 * @return void
	 */
	public function testAFailingTaskListReadsAsNoTasks(): void {
		$tasks = $this->createMock(UserTaskListInterface::class);
		$tasks->method('listUserTasks')->willThrowException(new RuntimeException('caldav down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with('[ObjectSource:caldav-vtodo] could not read VTODOs: caldav down');
		$provider = new CalDavVtodoObjectSourceProvider(taskService: $tasks, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $logger));

		$this->assertSame([], $provider->findAll(source: new SourcedTable(new RegisterSchema(new Register(), new Schema()))));
		$this->assertNull($provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: 't-1'));
	}//end testAFailingTaskListReadsAsNoTasks()

	/**
	 * A task is found by its id or its uid; any other id is absent.
	 *
	 * @return void
	 */
	public function testFindMatchesIdOrUidOnly(): void {
		$tasks = $this->createMock(UserTaskListInterface::class);
		$tasks->method('listUserTasks')->with(null, new ListQuery(limit: 1000, offset: 0))->willReturn(['results' => [['id' => '7', 'uid' => 'u-7', 'summary' => 'Call back']]]);
		$provider = new CalDavVtodoObjectSourceProvider(taskService: $tasks, app: new \OCA\OpenRegister\Service\ObjectSource\OptionalAppBridge(appManager: $this->createMock(IAppManager::class), container: $this->createMock(\Psr\Container\ContainerInterface::class), logger: $this->createMock(LoggerInterface::class)));

		$this->assertSame('Call back', $provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: '7')?->getObject()['title']);
		$this->assertSame('u-7', $provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: 'u-7')?->getUuid());
		$this->assertNull($provider->find(source: new SourcedTable(new RegisterSchema(new Register(), new Schema())), id: '8'));
	}//end testFindMatchesIdOrUidOnly()
}//end class
