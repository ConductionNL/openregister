<?php

/**
 * TaskMandateGuard: a delegate or stand-in must hold the task's mandate,
 * and every way the check cannot answer refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskMandateRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\ProvenanceRequest;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Task\TaskMandateGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The three cases of design D-2 and the fail-closed edges.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskMandateGuard
 * @covers \OCA\OpenRegister\Exception\TaskMandateRefusedException
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Service\Object\ProvenanceRequest
 * @uses \OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions
 */
class TaskMandateGuardTest extends TestCase {

	private PermissionHandlerInterface&MockObject $permissions;

	private ObjectOperationsInterface&MockObject $objects;

	private SchemaMapperInterface&MockObject $schemas;

	private PermissionCatalogueInterface&MockObject $catalogue;

	private LoggerInterface&MockObject $logger;

	private Schema $schema;

	private ObjectEntity $subject;

	/**
	 * A permit case on schema 7 in register 3, and a catalogue knowing read, decide and a custom verb.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->permissions = $this->createMock(PermissionHandlerInterface::class);
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->schema = new Schema();
		$this->schema->setId(7);
		$this->schemas->method('find')->willReturnCallback(fn (string|int $id): Schema => $this->schemaById(id: $id));

		$this->subject = new ObjectEntity();
		$this->subject->setUuid('case-1');
		$this->subject->setRegister('3');
		$this->subject->setSchema('7');

		$this->catalogue->method('isGrantable')->willReturnCallback(
			static fn (string $verb): bool => in_array($verb, ['read', 'decide', 'mandaat-besluit'], true)
		);
		$this->catalogue->method('declarationFor')->willReturnCallback(
			static fn (string $verb): ?array => match ($verb) {
				'mandaat-besluit' => ['verb' => $verb, 'app' => 'dossiq', 'description' => '', 'levels' => [], 'canonical' => false],
				default => ['verb' => $verb, 'app' => 'openregister', 'description' => '', 'levels' => [], 'canonical' => true],
			}
		);
	}//end setUp()

	/**
	 * Schema 7, refusing any other id.
	 *
	 * @param string|int $id The id asked for.
	 *
	 * @return Schema The schema.
	 */
	private function schemaById(string|int $id): Schema {
		if ((string)$id !== '7') {
			throw new RuntimeException('No schema ' . $id);
		}

		return $this->schema;
	}//end schemaById()

	/**
	 * The guard with every collaborator.
	 *
	 * @return TaskMandateGuard The guard.
	 */
	private function guard(): TaskMandateGuard {
		$catalog = $this->createMock(SchemaCatalogInterface::class);
		$catalog->method('schemas')->willReturn($this->schemas);

		return new TaskMandateGuard(
			permissions: $this->permissions,
			objects: $this->objects,
			catalog: $catalog,
			catalogue: $this->catalogue,
			logger: $this->logger
		);
	}//end guard()

	/**
	 * A task on the case, optionally naming a mandate.
	 *
	 * @param string|null $required The required mandate.
	 * @param bool        $subject  Whether it is about the case.
	 *
	 * @return Task The task.
	 */
	private function task(?string $required, bool $subject = true): Task {
		$task = new Task();
		$task->setUuid('t-1');
		$task->setRequiredMandate($required);
		if ($subject === true) {
			$task->setObjectUuid('case-1');
			$task->setRegisterId(3);
			$task->setSchemaId(7);
		}

		return $task;
	}//end task()

	/**
	 * A holder passes, and the evidence names the verb, the rule and its end date.
	 *
	 * @return void
	 */
	public function testAHolderPassesWithTheGrantingRule(): void {
		$rule = ['principal' => 'chris', 'until' => '2026-12-31'];
		$this->objects->expects($this->once())->method('find')->with('case-1')->willReturn($this->subject);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->schema, 'decide', 'chris', null, true, $this->subject, '3')
			->willReturn(true);
		$this->permissions->expects($this->once())->method('provenanceFor')
			->with($this->callback(fn (ProvenanceRequest $r): bool => $r->actions === ['decide'] && $r->userId === 'chris' && $r->object === $this->subject))
			->willReturn(['decide' => ['action' => 'decide', 'granted' => true, 'source' => 'schema', 'rule' => $rule, 'principal' => 'chris', 'role' => null]]);

		$evidence = $this->guard()->assertHolds(task: $this->task(required: 'decide'), uid: 'chris');

		$this->assertSame(['verb' => 'decide', 'source' => 'schema', 'rule' => $rule, 'until' => '2026-12-31'], $evidence);
	}//end testAHolderPassesWithTheGrantingRule()

	/**
	 * A non-holder is refused, and the refusal names the verb and the person.
	 *
	 * @return void
	 */
	public function testANonHolderIsRefusedNamingVerbAndPerson(): void {
		$this->objects->method('find')->willReturn($this->subject);
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->permissions->expects($this->never())->method('provenanceFor');

		try {
			$this->guard()->assertHolds(task: $this->task(required: 'decide'), uid: 'bram');
			$this->fail('A non-holder was let through.');
		} catch (TaskMandateRefusedException $refused) {
			$this->assertSame('decide', $refused->getVerb());
			$this->assertSame('bram', $refused->getUid());
			$this->assertStringContainsString("'decide'", $refused->getMessage());
			$this->assertStringContainsString("'bram'", $refused->getMessage());
		}
	}//end testANonHolderIsRefusedNamingVerbAndPerson()

	/**
	 * A verb an app decides through its vote is recorded as custom, naming the app.
	 *
	 * @return void
	 */
	public function testACustomVerbDecidedByAVoteRecordsTheApp(): void {
		$this->objects->method('find')->willReturn($this->subject);
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->permissions->method('provenanceFor')->willReturn(['mandaat-besluit' => ['granted' => false, 'source' => 'none']]);

		$evidence = $this->guard()->assertHolds(task: $this->task(required: 'mandaat-besluit'), uid: 'chris');

		$this->assertSame(
			['verb' => 'mandaat-besluit', 'source' => 'custom', 'rule' => null, 'until' => null, 'app' => 'dossiq'],
			$evidence
		);
	}//end testACustomVerbDecidedByAVoteRecordsTheApp()

	/**
	 * Without a required mandate, the delegate must be able to read the subject.
	 *
	 * @return void
	 */
	public function testWithoutAMandateTheReadVerbIsAsked(): void {
		$this->objects->method('find')->willReturn($this->subject);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->schema, 'read', 'bram')
			->willReturn(false);

		$this->expectException(TaskMandateRefusedException::class);
		$this->expectExceptionMessage("mandate 'read'");

		$this->guard()->assertHolds(task: $this->task(required: null), uid: 'bram');
	}//end testWithoutAMandateTheReadVerbIsAsked()

	/**
	 * A subject that cannot be loaded refuses, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAMissingSubjectFailsClosed(): void {
		$this->objects->method('find')->willReturn(null);
		$this->permissions->expects($this->never())->method('hasPermission');
		$this->logger->expects($this->once())->method('warning');

		$this->expectException(TaskMandateRefusedException::class);

		$this->guard()->assertHolds(task: $this->task(required: 'decide'), uid: 'chris');
	}//end testAMissingSubjectFailsClosed()

	/**
	 * A permission check that throws refuses.
	 *
	 * @return void
	 */
	public function testAThrowingCheckFailsClosed(): void {
		$this->objects->method('find')->willReturn($this->subject);
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('cascade unreadable'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('cascade unreadable'));

		$this->expectException(TaskMandateRefusedException::class);

		$this->guard()->assertHolds(task: $this->task(required: 'decide'), uid: 'chris');
	}//end testAThrowingCheckFailsClosed()

	/**
	 * A verb the catalogue no longer knows refuses without asking.
	 *
	 * @return void
	 */
	public function testAnUnknownVerbFailsClosed(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->expectException(TaskMandateRefusedException::class);

		$this->guard()->assertHolds(task: $this->task(required: 'decidee'), uid: 'chris');
	}//end testAnUnknownVerbFailsClosed()

	/**
	 * A mandate with no subject is checked on the schema the task names.
	 *
	 * @return void
	 */
	public function testAMandateWithoutSubjectIsCheckedOnTheSchema(): void {
		$task = $this->task(required: 'decide', subject: false);
		$task->setSchemaId(7);
		$task->setRegisterId(3);
		$this->objects->expects($this->never())->method('find');
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($this->schema, 'decide', 'chris', null, true, null, '3')
			->willReturn(true);
		$this->permissions->method('provenanceFor')->willReturn(['decide' => ['granted' => true, 'source' => 'register', 'rule' => 'chris', 'role' => null]]);

		$evidence = $this->guard()->assertHolds(task: $task, uid: 'chris');

		$this->assertSame(['verb' => 'decide', 'source' => 'register', 'rule' => 'chris', 'until' => null], $evidence);
	}//end testAMandateWithoutSubjectIsCheckedOnTheSchema()

	/**
	 * A mandate with nowhere to be checked cannot be confirmed.
	 *
	 * @return void
	 */
	public function testAMandateWithNowhereToCheckIsRefused(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->expectException(TaskMandateRefusedException::class);

		$this->guard()->assertHolds(task: $this->task(required: 'decide', subject: false), uid: 'chris');
	}//end testAMandateWithNowhereToCheckIsRefused()

	/**
	 * A task about nothing, naming no mandate, has nothing to check.
	 *
	 * @return void
	 */
	public function testNothingToCheckGivesNoEvidence(): void {
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertNull($this->guard()->assertHolds(task: $this->task(required: null, subject: false), uid: 'chris'));
		$this->assertSame([], $this->guard()->evidenceFor(task: $this->task(required: null, subject: false), uid: 'chris'));
	}//end testNothingToCheckGivesNoEvidence()

	/**
	 * A guard without its collaborators refuses rather than admits.
	 *
	 * @return void
	 */
	public function testAGuardWithoutCollaboratorsRefuses(): void {
		$this->assertNull((new TaskMandateGuard())->evidenceFor(task: $this->task(required: 'decide'), uid: 'chris'));
		$this->assertSame('decide', (new TaskMandateGuard())->verbFor(task: $this->task(required: 'decide')));
		$this->assertSame('read', (new TaskMandateGuard())->verbFor(task: $this->task(required: null)));
	}//end testAGuardWithoutCollaboratorsRefuses()
}//end class
