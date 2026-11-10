<?php

/**
 * LifecycleEventObject tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\WorkflowEngine
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/visual-flow-builder/specs/integration-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\WorkflowEngine;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\WorkflowEngine\LifecycleEventObject;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

final class LifecycleEventObjectTest extends TestCase {
	public function testReadsTheObjectOffALifecycleEvent(): void {
		$object = new ObjectEntity();

		self::assertSame($object, (new LifecycleEventObject())->of(event: new ObjectCreatedEvent($object)));
		self::assertSame($object, (new LifecycleEventObject())->of(event: new ObjectDeletedEvent($object)));
	}//end testReadsTheObjectOffALifecycleEvent()

	public function testAnyOtherEventCarriesNoObject(): void {
		self::assertNull((new LifecycleEventObject())->of(event: new Event()));
	}//end testAnyOtherEventCarriesNoObject()
}//end class
