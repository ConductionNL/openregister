<?php

/**
 * The concept delete guard, asked about a delete.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\ConceptDeleteGuardListener;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepositoryInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A system-defined or held value refuses its delete with a 409; everything else passes.
 *
 * @covers \OCA\OpenRegister\Listener\ConceptDeleteGuardListener
 * @uses \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses \OCA\OpenRegister\Event\WriteVetoState
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 */
final class ConceptDeleteGuardListenerDeletingTest extends TestCase {

	private const SCHEME = 'https://example.org/scheme';

	private ConceptDeleteGuardInterface&MockObject $guard;

	private ConceptRepositoryInterface&MockObject $concepts;

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	/**
	 * Fresh collaborators per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(ConceptDeleteGuardInterface::class);
		$this->concepts = $this->createMock(ConceptRepositoryInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->schemas->method('find')->willReturn(new Schema());
		$this->guard->method('systemDefinedMessage')->willReturn('system message');
		$this->guard->method('inUseMessage')->willReturn('in use message');
	}//end setUp()

	/**
	 * The listener under test.
	 *
	 * @return ConceptDeleteGuardListener The listener.
	 */
	private function listener(): ConceptDeleteGuardListener {
		return new ConceptDeleteGuardListener(
			guard: $this->guard,
			concepts: $this->concepts,
			schemas: $this->schemas,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * A delete event for an object with the given schema and data.
	 *
	 * @param string $schema The schema reference.
	 * @param array|null $data The object data.
	 *
	 * @return ObjectDeletingEvent The handled event.
	 */
	private function handled(string $schema, ?array $data): ObjectDeletingEvent {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		if ($data !== null) {
			$object->setObject($data);
		}

		$event = new ObjectDeletingEvent(object: $object);
		$this->listener()->handle($event);
		return $event;
	}//end handled()

	/**
	 * A system-defined value is refused with the system code and a 409.
	 *
	 * @return void
	 */
	public function testASystemDefinedValueRefusesItsDelete(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(true);
		$this->guard->expects($this->never())->method('usage');

		$event = $this->handled(schema: '7', data: ['uri' => 'https://example.org/v']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => ConceptDeleteGuardListener::ERROR_CODE_SYSTEM,
				'message' => 'system message',
				'status' => 409,
				'concept' => 'https://example.org/v',
				'systemDefined' => true,
			],
			$event->getErrors()
		);
	}//end testASystemDefinedValueRefusesItsDelete()

	/**
	 * A value held by objects in its scheme (named by uri) is refused with the count and holders.
	 *
	 * @return void
	 */
	public function testAHeldValueRefusesItsDeleteNamingTheHolders(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(false);
		$this->concepts->expects($this->never())->method('schemeByReference');
		$this->guard->expects($this->once())->method('usage')
			->with($this->callback(static fn (array $c): bool => ($c['uri'] ?? null) === 'https://example.org/v'), self::SCHEME)
			->willReturn(['count' => 2, 'holders' => ['a', 'b']]);

		$event = $this->handled(schema: '7', data: ['uri' => 'https://example.org/v', 'inScheme' => ' ' . self::SCHEME . ' ']);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => ConceptDeleteGuardListener::ERROR_CODE_IN_USE,
				'message' => 'in use message',
				'status' => 409,
				'concept' => 'https://example.org/v',
				'inUse' => 2,
				'holders' => ['a', 'b'],
			],
			$event->getErrors()
		);
	}//end testAHeldValueRefusesItsDeleteNamingTheHolders()

	/**
	 * A value nothing holds is deletable.
	 *
	 * @return void
	 */
	public function testAnUnheldValueIsDeletable(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(false);
		$this->guard->expects($this->once())->method('usage')->willReturn(['count' => 0, 'holders' => []]);

		$event = $this->handled(schema: '7', data: ['inScheme' => self::SCHEME]);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAnUnheldValueIsDeletable()

	/**
	 * A scheme named by id in an inScheme object resolves through the repository to its uri.
	 *
	 * @return void
	 */
	public function testASchemeNamedByIdIsResolvedToItsUri(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(false);
		$this->concepts->expects($this->once())->method('schemeByReference')->with('42')
			->willReturn(['uri' => ' ' . self::SCHEME . ' ']);
		$this->guard->expects($this->once())->method('usage')
			->with($this->anything(), self::SCHEME)
			->willReturn(['count' => 1, 'holders' => ['x']]);

		$event = $this->handled(schema: '7', data: ['inScheme' => ['id' => '42']]);

		$this->assertSame(1, $event->getErrors()['inUse']);
		$this->assertSame('', $event->getErrors()['concept']);
	}//end testASchemeNamedByIdIsResolvedToItsUri()

	/**
	 * An inScheme object falls back from uri to id to uuid.
	 *
	 * @return void
	 */
	public function testAnInSchemeObjectFallsBackToItsUuid(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(false);
		$this->concepts->expects($this->once())->method('schemeByReference')->with('u-1')->willReturn(['uri' => self::SCHEME]);
		$this->guard->method('usage')->willReturn(['count' => 1, 'holders' => []]);

		$this->assertTrue($this->handled(schema: '7', data: ['inScheme' => ['uuid' => 'u-1']])->isPropagationStopped());
	}//end testAnInSchemeObjectFallsBackToItsUuid()

	/**
	 * A scheme the repository does not know (or knows without uri) lets the delete through.
	 *
	 * @return void
	 */
	public function testAnUnknownSchemeLetsTheDeleteThrough(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(false);
		$this->concepts->method('schemeByReference')->willReturnOnConsecutiveCalls(null, ['uri' => '  ']);
		$this->guard->expects($this->never())->method('usage');

		$this->assertFalse($this->handled(schema: '7', data: ['inScheme' => '42'])->isPropagationStopped());
		$this->assertFalse($this->handled(schema: '7', data: ['inScheme' => '43'])->isPropagationStopped());
	}//end testAnUnknownSchemeLetsTheDeleteThrough()

	/**
	 * A concept naming no scheme (missing, blank, not a string) is not checked for holders.
	 *
	 * @return void
	 */
	public function testAConceptWithoutSchemeIsNotCheckedForHolders(): void {
		$this->guard->method('isConcept')->willReturn(true);
		$this->guard->method('isSystemDefined')->willReturn(false);
		$this->guard->expects($this->never())->method('usage');
		$this->concepts->expects($this->never())->method('schemeByReference');

		$this->assertFalse($this->handled(schema: '7', data: [])->isPropagationStopped());
		$this->assertFalse($this->handled(schema: '7', data: ['inScheme' => '   '])->isPropagationStopped());
		$this->assertFalse($this->handled(schema: '7', data: ['inScheme' => 12])->isPropagationStopped());
		$this->assertFalse($this->handled(schema: '7', data: ['inScheme' => ['label' => 'x']])->isPropagationStopped());
	}//end testAConceptWithoutSchemeIsNotCheckedForHolders()

	/**
	 * An object of a schema that is no vocabulary is never asked about.
	 *
	 * @return void
	 */
	public function testAnObjectThatIsNoConceptIsDeletable(): void {
		$this->guard->method('isConcept')->willReturn(false);
		$this->guard->expects($this->never())->method('isSystemDefined');

		$this->assertFalse($this->handled(schema: '7', data: ['uri' => 'x'])->isPropagationStopped());
	}//end testAnObjectThatIsNoConceptIsDeletable()

	/**
	 * An object without schema is not looked up at all.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutSchemaIsNotLookedUp(): void {
		$this->schemas->expects($this->never())->method('find');
		$this->guard->expects($this->never())->method('isConcept');

		$this->assertFalse($this->handled(schema: '', data: ['uri' => 'x'])->isPropagationStopped());
	}//end testAnObjectWithoutSchemaIsNotLookedUp()

	/**
	 * A guard that throws lets the delete through and logs a warning naming the failure.
	 *
	 * @return void
	 */
	public function testAFailingGuardFailsOpenAndLogs(): void {
		$this->guard->method('isConcept')->willThrowException(new RuntimeException('table gone'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('allowing the delete: table gone'), $this->callback(static fn (array $c): bool => $c['exception'] === RuntimeException::class));

		$event = $this->handled(schema: '7', data: ['uri' => 'x']);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());
	}//end testAFailingGuardFailsOpenAndLogs()

	/**
	 * Events other than a delete are ignored.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		$this->schemas->expects($this->never())->method('find');
		$event = new Event();

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testOtherEventsAreIgnored()
}//end class
