<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A cross-app URL reference is checked on syntax only, and a filtered reference also passes its filter.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ReferenceValidationFailedEvent;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard;
use OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun;
use OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator;
use OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack;
use OCA\OpenRegister\Service\Object\SaveObject\SaveLookups;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceValidator
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceExistence
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceFilterGuard
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferenceEvents
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ReferencePropertyRun
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Event\AbstractReferenceValidationEvent
 * @uses   \OCA\OpenRegister\Event\ReferenceValidationFailedEvent
 * @uses   \OCA\OpenRegister\Exception\ValidationException
 * @uses   \OCA\OpenRegister\Exception\ReferenceValidationException
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceStrictness
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\ReferenceText
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\RegisterReferenceLookup
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveCallStack
 * @uses   \OCA\OpenRegister\Service\Object\SaveObject\SaveLookups
 * @uses   \OCA\OpenRegister\Service\Schemas\ReferenceFilterDeclaration
 * @uses \OCA\OpenRegister\Formats\UuidFormat
 */
class ReferenceValidatorTest extends TestCase {

	private const UUID = '11111111-1111-4111-8111-111111111111';

	private const REF = '#/components/schemas/author';

	/**
	 * @var MagicMapper&MockObject
	 */
	private MagicMapper $mapper;

	/**
	 * The events dispatched.
	 *
	 * @var list<Event>
	 */
	private array $events = [];

	protected function setUp(): void {
		$this->mapper = $this->createMock(MagicMapper::class);
	}//end setUp()

	/**
	 * Validate one value of property `author`.
	 *
	 * @param mixed $value    The value.
	 * @param array $property Extra property definition.
	 * @param array $data     Other data of the record.
	 *
	 * @return void
	 */
	private function validate(mixed $value, array $property = [], array $data = []): void {
		$target = new Schema();
		$target->setId(2);
		$target->setSlug('author');
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('findAll')->willReturn([$target]);
		$schemas->method('find')->willReturn($target);
		$lookups = new SaveLookups(schemaMapper: $schemas, registerMapper: $this->createMock(RegisterMapper::class));

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->events[] = $event;
			}
		);
		$events = new ReferenceEvents(dispatcher: $dispatcher, logger: new NullLogger());
		$validator = new ReferenceValidator(
			existence: new ReferenceExistence(
				lookups: $lookups,
				saveCalls: new SaveCallStack(),
				mapper: $this->mapper,
				events: $events
			),
			filter: new ReferenceFilterGuard(lookups: $lookups, mapper: $this->mapper),
			events: $events,
			logger: new NullLogger()
		);

		$schema = new Schema();
		$schema->setProperties(['author' => (['type' => 'string', '$ref' => self::REF, 'validateReference' => true] + $property)]);
		$validator->validate(schema: $schema, run: ReferencePropertyRun::forSave(data: (['author' => $value] + $data), register: null));
	}//end validate()

	public function testAWellFormedUrlPassesWithoutAnyLookup(): void {
		$this->mapper->expects($this->never())->method('find');

		$this->validate('https://example.org/authors/1');

		$this->assertSame([], $this->events);
	}//end testAWellFormedUrlPassesWithoutAnyLookup()

	public function testAUrlWithoutAHostIsRefusedInUrlTerms(): void {
		$this->mapper->expects($this->never())->method('find');

		try {
			$this->validate('https://');
			$this->fail('no refusal');
		} catch (ReferenceValidationException $e) {
			$this->assertSame(
				'External URL reference "https://" is malformed: scheme MUST be http or https and host MUST parse.',
				$e->getMessage()
			);
			$this->assertSame('author', $e->getTargetSchemaSlug());
		}

		$this->assertCount(1, $this->events);
		$this->assertInstanceOf(ReferenceValidationFailedEvent::class, $this->events[0]);
		$this->assertSame('author', $this->events[0]->getTargetSchemaSlug());
	}//end testAUrlWithoutAHostIsRefusedInUrlTerms()

	public function testAnExistingReferenceOutsideItsFilterIsRefused(): void {
		$found = new ObjectEntity();
		$found->setUuid(self::UUID);
		$found->setObject(['city' => 'Utrecht']);
		$this->mapper->method('find')->willReturn($found);

		$this->expectException(ReferenceValidationException::class);
		$this->expectExceptionMessage("'author' only accepts an object whose 'city' matches this record.");

		$this->validate(
			self::UUID,
			['x-openregister-reference-filter' => [['field' => 'city', 'op' => 'eq', 'from' => 'city']]],
			['city' => 'Amsterdam']
		);
	}//end testAnExistingReferenceOutsideItsFilterIsRefused()
}//end class
