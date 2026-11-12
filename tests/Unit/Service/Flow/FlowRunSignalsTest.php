<?php

/**
 * Unit tests for FlowRunSignals.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAssignee;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\FlowRunSignals;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowRunSignals
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunSignalService
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunAssignee
 */
final class FlowRunSignalsTest extends TestCase {

	/**
	 * Read a private property of an object.
	 *
	 * @param object $object The object.
	 * @param string $name   The property name.
	 *
	 * @return mixed The value.
	 */
	private static function field(object $object, string $name): mixed {
		return (new ReflectionProperty($object, $name))->getValue($object);
	}//end field()

	/**
	 * The seam is a signal service wired to the given mapper, runner and group manager.
	 *
	 * @return void
	 */
	public function testTheSeamIsWiredToTheGivenCollaborators(): void {
		$mapper = $this->createMock(FlowRunMapperInterface::class);
		$runner = $this->createMock(FlowRunServiceInterface::class);
		$groups = $this->createMock(IGroupManager::class);

		$seam = (new FlowRunSignals(mapper: $mapper, runner: $runner, groupManager: $groups))->seam();

		self::assertInstanceOf(FlowRunSignalService::class, $seam);
		self::assertSame($mapper, self::field($seam, 'mapper'));
		self::assertSame($runner, self::field($seam, 'runner'));
		$assignees = self::field($seam, 'assignees');
		self::assertInstanceOf(FlowRunAssignee::class, $assignees);
		self::assertSame($groups, self::field($assignees, 'groupManager'));
	}//end testTheSeamIsWiredToTheGivenCollaborators()

	/**
	 * Without a group manager the assignee resolver gets none, and each call builds a fresh seam.
	 *
	 * @return void
	 */
	public function testWithoutAGroupManagerEachSeamIsFresh(): void {
		$signals = new FlowRunSignals(
			mapper: $this->createMock(FlowRunMapperInterface::class),
			runner: $this->createMock(FlowRunServiceInterface::class),
			groupManager: null
		);

		$first = $signals->seam();

		self::assertNotSame($first, $signals->seam());
		self::assertNull(self::field(self::field($first, 'assignees'), 'groupManager'));
	}//end testWithoutAGroupManagerEachSeamIsFresh()
}//end class
