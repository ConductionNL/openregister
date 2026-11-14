<?php

/**
 * The released AuditTrailMapper and TaskService methods answer through the methods that replaced them.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat;

use DateTime;
use OCA\OpenRegister\Db\AuditTrail\AuditStatisticsScope;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Kernel\Contracts\LinkedTaskDraft;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Kernel\Contracts\TaskLocation;
use OCA\OpenRegister\Service\TaskService;
use PHPUnit\Framework\TestCase;

class ReleasedMethodAdaptersTest extends TestCase {

	private function auditMapper(): AuditTrailMapper {
		return $this->getMockBuilder(AuditTrailMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findTrail', 'actionChartData'])
			->getMock();
	}

	private function taskService(): TaskService {
		return $this->getMockBuilder(TaskService::class)
			->disableOriginalConstructor()
			->onlyMethods(['listUserTasks', 'createObjectTask', 'changeTask'])
			->getMock();
	}

	public function testFindAllWithoutArgumentsListsEveryEntryNewestFirst(): void {
		$mapper = $this->auditMapper();
		$mapper->expects($this->once())->method('findTrail')->with($this->callback(
			fn (ListQuery $q): bool => $q->isUnbounded() === true
				&& $q->offset === null
				&& $q->filters === []
				&& $q->sort === ['created' => 'DESC']
				&& $q->search === null
		))->willReturn(['row']);

		$this->assertSame(['row'], $mapper->findAll());
	}

	public function testFindAllPassesItsPageFiltersSortAndSearch(): void {
		$mapper = $this->auditMapper();
		$mapper->expects($this->once())->method('findTrail')->with($this->callback(
			fn (ListQuery $q): bool => $q->limit === 10
				&& $q->offset === 20
				&& $q->filters === ['action' => 'update']
				&& $q->sort === ['id' => 'ASC']
				&& $q->search === 'x'
		))->willReturn([]);

		$mapper->findAll(limit: 10, offset: 20, filters: ['action' => 'update'], sort: ['id' => 'ASC'], search: 'x');
	}

	public function testFindAllTreatsNullFiltersAndSortAsNone(): void {
		$mapper = $this->auditMapper();
		$mapper->expects($this->once())->method('findTrail')->with($this->callback(
			fn (ListQuery $q): bool => $q->filters === [] && $q->sort === []
		))->willReturn([]);

		$mapper->findAll(filters: null, sort: null);
	}

	public function testGetActionChartDataScopesTheChart(): void {
		$from   = new DateTime('2026-01-01');
		$till   = new DateTime('2026-02-01');
		$mapper = $this->auditMapper();
		$mapper->expects($this->once())->method('actionChartData')->with($this->callback(
			fn (AuditStatisticsScope $s): bool => $s->registerId === 3 && $s->schemaId === null && $s->from === $from && $s->till === $till
		))->willReturn(['labels' => [], 'series' => []]);

		$this->assertSame(['labels' => [], 'series' => []], $mapper->getActionChartData(from: $from, till: $till, registerId: 3, schemaId: null));
	}

	public function testGetAllUserTasksPagesTheList(): void {
		$service = $this->taskService();
		$service->expects($this->once())->method('listUserTasks')->with(
			'completed',
			$this->callback(fn (ListQuery $q): bool => $q->limit === 1000 && $q->offset === 5)
		)->willReturn(['results' => [], 'total' => 0]);

		$this->assertSame(['results' => [], 'total' => 0], $service->getAllUserTasks(status: 'completed', limit: 1000, offset: 5));
	}

	public function testGetAllUserTasksDefaultsToTheReleasedPage(): void {
		$service = $this->taskService();
		$service->expects($this->once())->method('listUserTasks')->with(
			null,
			$this->callback(fn (ListQuery $q): bool => $q->limit === 50 && $q->offset === 0)
		)->willReturn(['results' => [], 'total' => 0]);

		$service->getAllUserTasks();
	}

	public function testCreateTaskLinksTheObject(): void {
		$service = $this->taskService();
		$service->expects($this->once())->method('createObjectTask')->with(
			$this->callback(fn (ObjectRef $r): bool => $r->register === 1 && $r->schema === 2 && $r->id === 'uuid'),
			$this->callback(fn (LinkedTaskDraft $d): bool => $d->objectTitle === 'Title' && $d->data === ['summary' => 's'])
		)->willReturn(['id' => 't']);

		$this->assertSame(['id' => 't'], $service->createTask(registerId: 1, schemaId: 2, objectUuid: 'uuid', objectTitle: 'Title', data: ['summary' => 's']));
	}

	public function testUpdateTaskLocatesTheTask(): void {
		$service = $this->taskService();
		$service->expects($this->once())->method('changeTask')->with(
			$this->callback(fn (TaskLocation $at): bool => $at->calendarId === '7' && $at->uri === 'task.ics'),
			['status' => 'completed']
		)->willReturn(null);

		$this->assertNull($service->updateTask(calendarId: '7', taskUri: 'task.ics', data: ['status' => 'completed']));
	}
}
