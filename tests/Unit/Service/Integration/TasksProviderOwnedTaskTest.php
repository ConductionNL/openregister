<?php

/**
 * Characterisation test for TasksProvider update and delete: the task is
 * checked against the object before it is changed (allowed and refused
 * outcomes), a malformed entity id is refused before the check, and an
 * update that answers nothing is an error.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers take positional args by convention.

use Exception;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\TaskLocation;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProvider;
use OCA\OpenRegister\Service\Integration\BuiltinProviders\TasksProviderServices;
use OCA\OpenRegister\Service\TaskService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the order and outcomes of TasksProvider::update() and ::delete().
 */
class TasksProviderOwnedTaskTest extends TestCase {

	/**
	 * The calls made on the task service, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A provider over a task service that owns `7/task.ics` for `uuid-mine` only.
	 *
	 * @param array<string, mixed>|null $updated What changeTask answers.
	 *
	 * @return TasksProvider
	 */
	private function provider(?array $updated): TasksProvider {
		$tasks = $this->createMock(TaskService::class);
		$tasks->method('requireTaskAt')->willReturnCallback(
			function (string $objectUuid, TaskLocation $at): void {
				$this->calls[] = "require:$objectUuid:{$at->calendarId}:{$at->uri}";
				if ($objectUuid !== 'uuid-mine') {
					throw new Exception('Task not found', 404);
				}
			}
		);
		$tasks->method('changeTask')->willReturnCallback(
			function (TaskLocation $at, array $data) use ($updated): ?array {
				$this->calls[] = "update:{$at->calendarId}:{$at->uri}:" . json_encode($data);
				return $updated;
			}
		);
		$tasks->method('deleteTask')->willReturnCallback(
			function (string $calendarId, string $taskUri): bool {
				$this->calls[] = "delete:$calendarId:$taskUri";
				return true;
			}
		);

		return new TasksProvider(
			new TasksProviderServices(
				$tasks,
				$this->createMock(RegisterMapperInterface::class),
				$this->createMock(SchemaMapperInterface::class),
				$this->createMock(ObjectOperationsInterface::class),
			),
			$this->createMock(IL10N::class),
		);
	}

	public function testTheObjectsOwnTaskIsUpdatedAfterTheCheck(): void {
		$result = $this->provider(updated: ['id' => 'task.ics'])->update(new ObjectRef(register: 'r', schema: 's', id: 'uuid-mine'), '7/task.ics', ['summary' => 'x']);

		$this->assertSame(['id' => 'task.ics'], $result);
		$this->assertSame(['require:uuid-mine:7:task.ics', 'update:7:task.ics:{"summary":"x"}'], $this->calls);
	}

	public function testAnotherObjectsTaskIsNotUpdated(): void {
		try {
			$this->provider(updated: ['id' => 'task.ics'])->update(new ObjectRef(register: 'r', schema: 's', id: 'uuid-other'), '7/task.ics', ['summary' => 'x']);
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertSame(['require:uuid-other:7:task.ics'], $this->calls);
	}

	public function testAnUpdateThatAnswersNothingIsAnError(): void {
		$this->expectException(RuntimeException::class);
		$this->provider(updated: null)->update(new ObjectRef(register: 'r', schema: 's', id: 'uuid-mine'), '7/task.ics', ['summary' => 'x']);
	}

	public function testAMalformedEntityIdIsRefusedBeforeTheCheck(): void {
		try {
			$this->provider(updated: [])->update(new ObjectRef(register: 'r', schema: 's', id: 'uuid-mine'), 'task.ics', []);
			$this->fail('expected a refusal');
		} catch (NotImplementedException $e) {
			$this->assertSame([], $this->calls);
		}
	}

	public function testTheObjectsOwnTaskIsDeletedAfterTheCheck(): void {
		$this->provider(updated: null)->delete(new ObjectRef(register: 'r', schema: 's', id: 'uuid-mine'), '7/task.ics');

		$this->assertSame(['require:uuid-mine:7:task.ics', 'delete:7:task.ics'], $this->calls);
	}

	public function testAnotherObjectsTaskIsNotDeleted(): void {
		try {
			$this->provider(updated: null)->delete(new ObjectRef(register: 'r', schema: 's', id: 'uuid-other'), '7/task.ics');
			$this->fail('expected a refusal');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}

		$this->assertSame(['require:uuid-other:7:task.ics'], $this->calls);
	}
}
