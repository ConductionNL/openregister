<?php

/**
 * The door between a task and the object it is about.
 *
 * These tests pin the hole that was reproduced against a live instance: an
 * ordinary account that gets 404 reading a case could still post a task onto
 * that case and onto a named colleague's work list, because knowing the uuid
 * was the whole check. They also pin the two properties that keep the fix
 * from being worse than the hole: an entitled caller is unaffected, and a
 * refusal writes nothing at all.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskAuditMapper;
use OCA\OpenRegister\Db\TaskCandidateMapper;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Db\TaskRelationMapper;
use OCA\OpenRegister\Exception\TaskSubjectNotFoundException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\Task\TaskAuthorizationService;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use OCA\OpenRegister\Service\Task\TaskPerformerResolver;
use OCA\OpenRegister\Service\Task\TaskService;
use OCA\OpenRegister\Service\Task\TaskSubjectAccessGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Creating a task is authorized against the object the task is about.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskSubjectAccessGuard
 * @covers \OCA\OpenRegister\Exception\TaskSubjectNotFoundException
 * @uses \OCA\OpenRegister\Service\Task\TaskService
 * @uses \OCA\OpenRegister\Service\Task\TaskBuilder
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 */
class TaskSubjectAccessGuardTest extends TestCase {

	/**
	 * The task table, mocked.
	 *
	 * @var TaskMapper&MockObject
	 */
	private TaskMapper&MockObject $tasks;

	/**
	 * The candidate index, mocked.
	 *
	 * @var TaskCandidateMapper&MockObject
	 */
	private TaskCandidateMapper&MockObject $candidates;

	/**
	 * The typed relations, mocked.
	 *
	 * @var TaskRelationMapper&MockObject
	 */
	private TaskRelationMapper&MockObject $relations;

	/**
	 * The append-only audit, mocked.
	 *
	 * @var TaskAuditMapper&MockObject
	 */
	private TaskAuditMapper&MockObject $audits;

	/**
	 * The per-verb decisions, mocked.
	 *
	 * @var TaskAuthorizationService&MockObject
	 */
	private TaskAuthorizationService&MockObject $authorization;

	/**
	 * The connection holding the transaction, mocked.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * Fresh mocks per test, with the happy plumbing wired.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->tasks = $this->createMock(TaskMapper::class);
		$this->candidates = $this->createMock(TaskCandidateMapper::class);
		$this->relations = $this->createMock(TaskRelationMapper::class);
		$this->audits = $this->createMock(TaskAuditMapper::class);
		$this->authorization = $this->createMock(TaskAuthorizationService::class);
		$this->db = $this->createMock(IDBConnection::class);

		$this->tasks->method('insert')->willReturnCallback(
			static function (Task $task): Task {
				if ($task->getId() === null) {
					$task->setId(41);
				}

				return $task;
			}
		);
		$this->audits->method('insert')->willReturnArgument(0);
		$this->relations->method('insert')->willReturnArgument(0);

		// The principal under test is an ORDINARY account, never an
		// administrator: an admin success proves almost nothing here, because
		// an admin reads every object anyway.
		$this->authorization->method('isAdministrator')->willReturn(false);

	}//end setUp()

	/**
	 * The object read path, doubled with `onlyMethods` so it cannot grow a
	 * method the real class lacks.
	 *
	 * @return ObjectService&MockObject The double.
	 */
	private function objectService(): ObjectService&MockObject {
		return $this->getMockBuilder(ObjectService::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
	}//end objectService()

	/**
	 * A lifecycle service wired to one subject guard.
	 *
	 * @param TaskSubjectAccessGuard|null $guard The guard under test.
	 *
	 * @return TaskService The service.
	 */
	private function service(?TaskSubjectAccessGuard $guard): TaskService {
		return new TaskService(
			tasks: $this->tasks,
			candidates: $this->candidates,
			relations: $this->relations,
			audits: $this->audits,
			authorization: $this->authorization,
			resolver: $this->createMock(TaskPerformerResolver::class),
			db: $this->db,
			logger: new NullLogger(),
			builder: new TaskBuilder(),
			subjects: $guard
		);
	}//end service()

	/**
	 * THE HOLE: an account that gets 404 reading the case could put a task on
	 * it. The read path answers the unrelated principal with
	 * DoesNotExistException, exactly as it answers their GET, and the create
	 * is refused with the object endpoint's own words.
	 *
	 * @return void
	 */
	public function testAnAccountThatCannotReadTheObjectCannotPutATaskOnIt(): void {
		$objects = $this->objectService();
		$objects->method('find')->willThrowException(new DoesNotExistException('Object f271b756 not found'));

		$this->tasks->expects($this->never())->method('insert');
		$this->audits->expects($this->never())->method('insert');

		$this->expectException(TaskSubjectNotFoundException::class);
		$this->expectExceptionMessage('Object with id f271b756 not found');

		$this->service(guard: new TaskSubjectAccessGuard(objects: $objects))->create(
			data: [
				'objectUuid' => 'f271b756',
				'assignee' => 'admin',
				'kind' => 'reminder',
			],
			actor: 'e2e-other'
		);
	}//end testAnAccountThatCannotReadTheObjectCannotPutATaskOnIt()

	/**
	 * The other half: an account the read path DOES answer for still creates
	 * its task, anchored to that object. Without this the fix would be
	 * indistinguishable from removing the endpoint.
	 *
	 * @return void
	 */
	public function testAnAccountThatCanReadTheObjectStillCreatesItsTask(): void {
		$objects = $this->objectService();
		$objects->expects($this->once())
			->method('find')
			->willReturn(new ObjectEntity());

		$created = $this->service(guard: new TaskSubjectAccessGuard(objects: $objects))->create(
			data: [
				'objectUuid' => 'f271b756',
				'assignee' => 'admin',
				'kind' => 'reminder',
			],
			actor: 'e2e-other'
		);

		$this->assertSame('f271b756', $created->getObjectUuid());
	}//end testAnAccountThatCanReadTheObjectStillCreatesItsTask()

	/**
	 * A relation attaches the task to an object exactly as the anchor does,
	 * so it is checked exactly as the anchor is. A guard that read only
	 * `objectUuid` would leave the same hole one key along.
	 *
	 * @return void
	 */
	public function testARelationIsCheckedLikeTheAnchor(): void {
		$objects = $this->objectService();
		$objects->method('find')->willReturnCallback(
			static function (int|string $id): ?ObjectEntity {
				if ($id === 'mine') {
					return new ObjectEntity();
				}

				return null;
			}
		);

		$this->tasks->expects($this->never())->method('insert');

		$this->expectException(TaskSubjectNotFoundException::class);
		$this->expectExceptionMessage('Object with id theirs not found');

		$this->service(guard: new TaskSubjectAccessGuard(objects: $objects))->create(
			data: [
				'objectUuid' => 'mine',
				'relations' => [['role' => 'evidence', 'objectUuid' => 'theirs']],
			],
			actor: 'e2e-other'
		);
	}//end testARelationIsCheckedLikeTheAnchor()

	/**
	 * A task about nothing names no object, so there is nothing to be
	 * entitled to and nothing to refuse. The read path is never asked.
	 *
	 * @return void
	 */
	public function testAStandaloneTaskIsUnaffected(): void {
		$objects = $this->objectService();
		$objects->expects($this->never())->method('find');

		$created = $this->service(guard: new TaskSubjectAccessGuard(objects: $objects))->create(
			data: ['title' => 'Ring the notary'],
			actor: 'e2e-other'
		);

		$this->assertSame('Ring the notary', $created->getTitle());
	}//end testAStandaloneTaskIsUnaffected()

	/**
	 * FAIL CLOSED: a guard with no read path to ask has not passed the check,
	 * it has failed to run it, and those must not look the same. A service
	 * built without the collaborator refuses a named subject too.
	 *
	 * @return void
	 */
	public function testAGuardWithNoReadPathRefusesRatherThanSkips(): void {
		$this->tasks->expects($this->never())->method('insert');

		$this->expectException(TaskSubjectNotFoundException::class);

		$this->service(guard: null)->create(
			data: ['objectUuid' => 'f271b756'],
			actor: 'e2e-other'
		);
	}//end testAGuardWithNoReadPathRefusesRatherThanSkips()

}//end class
