<?php

/**
 * A hook can still veto a delete.
 *
 * The dossiq guard `case-delete-guard` refuses a delete that would strand
 * something, and it refuses by stopping propagation on ObjectDeletingEvent. This
 * change adds a window, a right and a scope around the delete path, and none of
 * them may move the veto: a guard that stops propagation still throws before a
 * single row is touched.
 *
 * The mapper is built without its constructor and given only the dispatcher,
 * because the veto happens on the first statement of the method and nothing
 * after it is reached. That is the property under test, so reaching anything
 * further would be the failure.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Deletion
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Deletion;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class DeleteHookVetoTest extends TestCase {
	public function testAGuardThatStopsPropagationStillVetoesTheDelete(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event): void {
				if ($event instanceof ObjectDeletingEvent) {
					$event->setErrors(['message' => 'This case still has an open bezwaar.']);
					$event->stopPropagation();
				}
			}
		);

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods([])
			->getMock();

		$property = new ReflectionProperty(MagicMapper::class, 'eventDispatcher');
		$property->setAccessible(true);
		$property->setValue($mapper, $dispatcher);

		$object = new ObjectEntity();
		$object->setUuid('zaak-100');

		$this->expectException(HookStoppedException::class);
		$this->expectExceptionMessage('This case still has an open bezwaar.');

		$mapper->deleteObjectEntity($object, new Register(), new Schema());
	}//end testAGuardThatStopsPropagationStillVetoesTheDelete()
}//end class
