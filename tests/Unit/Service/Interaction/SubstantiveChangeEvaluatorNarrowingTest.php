<?php

/**
 * A leaf app narrows, per object, which declared properties make a write news.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectReadStatePropertiesEvent;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCA\OpenRegister\Service\Interaction\ValueNormaliser;
use OCA\OpenRegister\Service\Lifecycle\SchemaReading;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(SubstantiveChangeEvaluator::class)]
#[CoversClass(ObjectReadStatePropertiesEvent::class)]
#[UsesClass(SchemaReading::class)]
#[UsesClass(ValueNormaliser::class)]
final class SubstantiveChangeEvaluatorNarrowingTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	private IEventDispatcher&MockObject $dispatcher;

	protected function setUp(): void {
		$this->schemas    = $this->createMock(SchemaMapperInterface::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	private function evaluator(?IEventDispatcher $dispatcher): SubstantiveChangeEvaluator {
		return new SubstantiveChangeEvaluator(new SchemaReading($this->schemas, $this->logger), $dispatcher);
	}//end evaluator()

	private static function object(array $body): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('5');
		$object->setObject($body);
		return $object;
	}//end object()

	private function schemaDeclaring(?array $properties): Schema {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setProperties(['status' => ['type' => 'string'], 'assignee' => ['type' => 'string'], 'note' => ['type' => 'string']]);
		$annotation = null;
		if ($properties !== null) {
			$annotation = [SubstantiveChangeEvaluator::ANNOTATION => ['properties' => $properties]];
		}

		$schema->setConfiguration($annotation);
		$this->schemas->method('find')->willReturn($schema);
		return $schema;
	}//end schemaDeclaring()

	/**
	 * Let the listener narrow to these properties, recording the event it saw.
	 *
	 * @param array<int, mixed> $to The properties the listener keeps.
	 *
	 * @return \ArrayObject<string, ObjectReadStatePropertiesEvent> The event under `event`, once dispatched.
	 */
	private function listenerNarrowingTo(array $to): \ArrayObject {
		$seen = new \ArrayObject();
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			static function (ObjectReadStatePropertiesEvent $event) use ($to, $seen): void {
				$seen['event'] = $event;
				$event->narrowTo(properties: $to);
			}
		);
		return $seen;
	}//end listenerNarrowingTo()

	public function testAListenerNarrowsTheDeclaredPropertiesForThisObject(): void {
		$schema = $this->schemaDeclaring(['status', 'assignee']);
		$seen   = $this->listenerNarrowingTo(['status']);
		$new    = self::object(['status' => 'open', 'assignee' => 'bob']);

		$this->assertFalse($this->evaluator($this->dispatcher)->isSubstantive($new, self::object(['status' => 'open', 'assignee' => 'ann'])));
		$this->assertSame($new, $seen['event']->getObject());
		$this->assertSame($schema, $seen['event']->getSchema());
		$this->assertSame(['status', 'assignee'], $seen['event']->getDeclared());
		$this->assertSame(['status'], $seen['event']->getProperties());
	}//end testAListenerNarrowsTheDeclaredPropertiesForThisObject()

	public function testANarrowedPropertyStillCounts(): void {
		$this->schemaDeclaring(['status', 'assignee']);
		$this->listenerNarrowingTo(['status']);

		$this->assertTrue($this->evaluator($this->dispatcher)->isSubstantive(self::object(['status' => 'closed']), self::object(['status' => 'open'])));
	}//end testANarrowedPropertyStillCounts()

	public function testAListenerCannotWidenTheSchemasList(): void {
		$this->schemaDeclaring(['status']);
		$seen = $this->listenerNarrowingTo(['status', 'note', '', 7]);

		$this->assertFalse($this->evaluator($this->dispatcher)->isSubstantive(self::object(['note' => 'b']), self::object(['note' => 'a'])));
		$this->assertSame(['status'], $seen['event']->getProperties());
	}//end testAListenerCannotWidenTheSchemasList()

	public function testNarrowingTwiceKeepsOnlyWhatBothKeep(): void {
		$event = new ObjectReadStatePropertiesEvent(object: self::object([]), schema: new Schema(), declared: ['status', 'assignee', 'result']);

		$event->narrowTo(properties: ['status', 'assignee']);
		$event->narrowTo(properties: ['assignee', 'result']);

		$this->assertSame(['assignee'], $event->getProperties());
		$this->assertSame(['status', 'assignee', 'result'], $event->getDeclared());
	}//end testNarrowingTwiceKeepsOnlyWhatBothKeep()

	public function testASchemaThatDeclaresNothingAsksNoListener(): void {
		$this->schemaDeclaring(null);
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->assertTrue($this->evaluator($this->dispatcher)->isSubstantive(self::object(['note' => 'b']), self::object(['note' => 'a'])));
	}//end testASchemaThatDeclaresNothingAsksNoListener()

	public function testAFailingListenerIsLoggedAndTheSchemasListStands(): void {
		$this->schemaDeclaring(['status', 'assignee']);
		$this->dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('type not found'));
		$this->logger->expects($this->once())->method('warning')
			->with("[SubstantiveChangeEvaluator] narrowing the read-state properties failed, the schema's list stands: type not found");

		$this->assertTrue($this->evaluator($this->dispatcher)->isSubstantive(self::object(['assignee' => 'bob']), self::object(['assignee' => 'ann'])));
	}//end testAFailingListenerIsLoggedAndTheSchemasListStands()

	public function testWithoutADispatcherTheSchemasListStands(): void {
		$this->schemaDeclaring(['status', 'assignee']);

		$this->assertTrue($this->evaluator(null)->isSubstantive(self::object(['assignee' => 'bob']), self::object(['assignee' => 'ann'])));
	}//end testWithoutADispatcherTheSchemasListStands()
}//end class
