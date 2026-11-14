<?php

/**
 * TaskBuilder: the mandate verb a task requires is validated against the
 * permission catalogue at creation.
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
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-may-name-the-mandate-its-performer-needs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Task\TaskBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The `requiredMandate` boundary of TaskBuilder::fromData().
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskBuilder
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Task\TaskPriority
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 */
class TaskBuilderTest extends TestCase {

	/**
	 * The catalogue, answering for the verbs it is told about.
	 *
	 * @var PermissionCatalogueInterface&MockObject
	 */
	private PermissionCatalogueInterface&MockObject $catalogue;

	/**
	 * A catalogue that knows `decide` only.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$this->catalogue->method('isGrantable')->willReturnCallback(static fn (string $verb): bool => $verb === 'decide');
	}//end setUp()

	/**
	 * A catalogue verb is stored on the task.
	 *
	 * @return void
	 */
	public function testAKnownVerbIsStored(): void {
		$task = (new TaskBuilder(catalogue: $this->catalogue))->fromData(data: ['title' => 'Decide', 'requiredMandate' => ' decide '], actor: 'anna');

		$this->assertSame('decide', $task->getRequiredMandate());
		$this->assertSame('decide', $task->jsonSerialize()['requiredMandate']);
	}//end testAKnownVerbIsStored()

	/**
	 * A verb the catalogue does not hold is refused, naming it.
	 *
	 * @return void
	 */
	public function testAnUnknownVerbIsRefusedNamingIt(): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("Unknown mandate 'decidee'");

		(new TaskBuilder(catalogue: $this->catalogue))->fromData(data: ['title' => 'Decide', 'requiredMandate' => 'decidee'], actor: 'anna');
	}//end testAnUnknownVerbIsRefusedNamingIt()

	/**
	 * Without a mandate the catalogue is not asked and the field stays null.
	 *
	 * @return void
	 */
	public function testNoVerbLeavesTheFieldEmpty(): void {
		$this->catalogue->expects($this->never())->method('isGrantable');

		$task = (new TaskBuilder(catalogue: $this->catalogue))->fromData(data: ['title' => 'Read', 'requiredMandate' => '  '], actor: 'anna');

		$this->assertNull($task->getRequiredMandate());
	}//end testNoVerbLeavesTheFieldEmpty()

	/**
	 * A builder that cannot ask the catalogue refuses a named verb.
	 *
	 * @return void
	 */
	public function testWithoutACatalogueANamedVerbIsRefused(): void {
		$this->expectException(TaskValidationException::class);
		$this->expectExceptionMessage("Unknown mandate 'decide'");

		(new TaskBuilder())->fromData(data: ['title' => 'Decide', 'requiredMandate' => 'decide'], actor: 'anna');
	}//end testWithoutACatalogueANamedVerbIsRefused()
}//end class
