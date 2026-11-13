<?php

/**
 * GeneratedIdentifierListener: reserved values are skipped, and a second identifier draws its own sequence.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-second-human-identifier-reserved-values-and-a-scheme-change-as-a-migration-req-gid-006
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationInterface;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * REQ-GID-006: a reserved value or a value matching a reserved pattern is
 * never issued; the counter moves past it. A schema with two identifiers
 * draws each from its own sequence.
 *
 * @covers \OCA\OpenRegister\Listener\GeneratedIdentifierListener
 * @uses   \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration
 * @uses   \OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclarationReader
 * @uses   \OCA\OpenRegister\Service\Object\ObjectEventGate
 * @uses   \OCA\OpenRegister\Service\SequenceScope
 * @uses   \OCA\OpenRegister\Listener\GeneratedIdentifierIssueRun
 * @uses   \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
final class GeneratedIdentifierReservedTest extends TestCase {

	/**
	 * @var SequenceService&MockObject
	 */
	private $sequences;

	/**
	 * @var SchemaMapper&MockObject
	 */
	private $schemaMapper;

	/**
	 * The listener under test.
	 *
	 * @var GeneratedIdentifierListener
	 */
	private GeneratedIdentifierListener $listener;

	/**
	 * Build the listener over doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sequences    = $this->createMock(originalClassName: SequenceService::class);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->listener     = new GeneratedIdentifierListener(
			$this->schemaMapper,
			$this->sequences,
			$this->createMock(originalClassName: LoggerInterface::class),
			new ObjectEventGate($this->createMock(originalClassName: IEventDispatcher::class))
		);
	}//end setUp()

	/**
	 * Answer a schema with these properties.
	 *
	 * @param array<string, mixed> $properties The properties.
	 *
	 * @return void
	 */
	private function schemaWith(array $properties): void {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$this->schemaMapper->method('find')->willReturn($schema);
	}//end schemaWith()

	/**
	 * Create an object and return its data after the listener ran.
	 *
	 * @return array{0: array<string, mixed>, 1: ObjectCreatingEvent}
	 */
	private function create(): array {
		$object = new ObjectEntity();
		$object->setSchema('777');
		$object->setObject(['title' => 'A case']);
		$event = new ObjectCreatingEvent($object);
		$this->listener->handle($event);

		return [$object->getObject(), $event];
	}//end create()

	/**
	 * A reserved value and a reserved pattern are skipped; the next value is issued.
	 *
	 * @return void
	 */
	public function testReservedValuesAreSkipped(): void {
		$this->schemaWith(properties: 
			[
				'identifier' => [
					'type' => 'string',
					'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{seq:4}', 'reserved' => ['Z-0002', '/0003$/']],
				],
			]
		);
		$this->sequences->expects($this->exactly(count: 3))->method('reserveNext')->willReturnOnConsecutiveCalls(2, 3, 4);

		[$data] = $this->create();

		$this->assertSame(expected: 'Z-0004', actual: $data['identifier']);
		$this->assertSame(expected: 'A case', actual: $data['title']);
	}//end testReservedValuesAreSkipped()

	/**
	 * A pattern that reserves everything refuses the create after the skip limit, naming the sequence.
	 *
	 * @return void
	 */
	public function testAPatternReservingEverythingRefusesTheCreate(): void {
		$this->schemaWith(properties: 
			['identifier' => ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{seq}', 'reserved' => ['/^Z-/']]]]
		);
		$taken = 0;
		$this->sequences->method('reserveNext')->willReturnCallback(
			static function () use (&$taken): int {
				return ++$taken;
			}
		);

		[$data, $event] = $this->create();

		$this->assertSame(expected: GeneratedIdentifierDeclarationInterface::MAX_RESERVED_SKIPS + 1, actual: $taken);
		$this->assertArrayNotHasKey(key: 'identifier', array: $data);
		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'generated-identifier-unavailable', actual: $event->getErrors()['code'] ?? null);
		$this->assertSame(expected: 'case', actual: $event->getErrors()['sequence'] ?? null);
	}//end testAPatternReservingEverythingRefusesTheCreate()

	/**
	 * Two identifiers on one schema each draw from their own named sequence.
	 *
	 * @return void
	 */
	public function testASecondIdentifierDrawsItsOwnSequence(): void {
		$this->schemaWith(properties: 
			[
				'identifier' => ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'case', 'format' => 'Z-{seq:3}']],
				'letterNumber' => ['type' => 'string', 'x-openregister-generated' => ['sequence' => 'letter', 'format' => 'B/{seq}']],
			]
		);
		$keys = [];
		$this->sequences->method('reserveNext')->willReturnCallback(
			static function (SequenceScope $scope) use (&$keys): int {
				$keys[] = $scope->toArray()['scopeKey'];
				return count($keys) * 10;
			}
		);

		[$data] = $this->create();

		$this->assertSame(expected: ['gen:case|', 'gen:letter|'], actual: $keys);
		$this->assertSame(expected: 'Z-010', actual: $data['identifier']);
		$this->assertSame(expected: 'B/20', actual: $data['letterNumber']);
	}//end testASecondIdentifierDrawsItsOwnSequence()
}//end class
