<?php

/**
 * Unit tests for GeneratedIdentifierListener.
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
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-property-declares-a-generated-identifier-from-a-sequence-and-a-format
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the listener writes, what it refuses, and what it leaves alone.
 *
 * Two of these can only be asserted by COUNTING calls to the sequence service.
 * A create that supplies its own value must take NO number, and an update must
 * take none either. Both return the same object to the caller whether a number
 * was burnt or not, so the call count is the only thing that separates a
 * working guard from a counter quietly running away.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\GeneratedIdentifierListener
 */
class GeneratedIdentifierListenerTest extends TestCase {

	/**
	 * The sequence service double.
	 *
	 * @var SequenceService&MockObject
	 */
	private $sequences;

	/**
	 * The schema mapper double.
	 *
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
	 * Build fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sequences = $this->createMock(originalClassName: SequenceService::class);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->listener = new GeneratedIdentifierListener(
			$this->schemaMapper,
			$this->sequences,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end setUp()

	/**
	 * Wire the schema mapper to answer a schema with the given properties.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return void
	 */
	private function schemaWith(array $properties): void {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$this->schemaMapper->method('find')->willReturn($schema);

	}//end schemaWith()

	/**
	 * One declared identifier property.
	 *
	 * @param string $sequence The counter's name.
	 * @param string $format The format.
	 *
	 * @return array<string, mixed> The property definition.
	 */
	private function declaredProperty(string $sequence = 'case', string $format = 'Z-{year}-{seq:5}'): array {
		return [
			'type' => 'string',
			GeneratedIdentifierDeclaration::ANNOTATION => [
				'sequence' => $sequence,
				'format' => $format,
				'resetOn' => 'year',
			],
		];

	}//end declaredProperty()

	/**
	 * An object carrying a body and a schema.
	 *
	 * @param array<string, mixed> $body The object's data.
	 *
	 * @return ObjectEntity&MockObject The object double.
	 */
	private function objectWith(array $body): ObjectEntity {
		// A REAL entity, not a double. `setObject()` is answered by the
		// Entity base class's `__call`, so a double cannot be told what to do
		// with it, and a double that could would be asserting itself: the
		// listener's whole job on create is what it writes back through that
		// setter.
		$object = new ObjectEntity();
		$object->setSchema('777');
		$object->setObject($body);

		return $object;

	}//end objectWith()

	/**
	 * An empty declared property is filled from its counter.
	 *
	 * @return void
	 */
	public function testAnEmptyIdentifierIsFilled(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->willReturn(1);

		$object = $this->objectWith(['title' => 'A case']);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertMatchesRegularExpression(
			pattern: '/^Z-\d{4}-00001$/',
			string: (string)($object->getObject()['identifier'] ?? '')
		);
		$this->assertSame(
			expected: 'A case',
			actual: ($object->getObject()['title'] ?? null),
			message: 'filling the identifier must not drop the rest of the body'
		);

	}//end testAnEmptyIdentifierIsFilled()

	/**
	 * A named counter is drawn at register 0 and schema 0, keyed by its name.
	 *
	 * That sentinel is what lets two schemas share one counter. Scoped to the
	 * real register and schema instead, "cases and complaints number from one
	 * counter" silently becomes two counters that both start at one.
	 *
	 * @return void
	 */
	public function testANamedCounterIsGlobalAndKeyedByItsName(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty(sequence: 'register')]);

		$seenScope = null;
		$this->sequences->expects($this->once())
			->method('reserveNext')
			->willReturnCallback(
				static function (int $registerId, int $schemaId, string $scopeKey) use (&$seenScope): int {
					$seenScope = [$registerId, $schemaId, $scopeKey];

					return 7;
				}
			);

		$this->listener->handle(new ObjectCreatingEvent($this->objectWith([])));

		$this->assertSame(expected: 0, actual: $seenScope[0]);
		$this->assertSame(expected: 0, actual: $seenScope[1]);
		$this->assertStringContainsString(needle: 'register', haystack: $seenScope[2]);

	}//end testANamedCounterIsGlobalAndKeyedByItsName()

	/**
	 * A second declared identifier gets its own number, not nothing.
	 *
	 * A loop that stopped at the first declaration would leave this one empty,
	 * and an empty string is exactly what the property would have held anyway.
	 *
	 * @return void
	 */
	public function testASecondDeclaredIdentifierIsAlsoFilled(): void {
		$this->schemaWith(
			[
				'identifier' => $this->declaredProperty(sequence: 'case'),
				'publicNumber' => $this->declaredProperty(sequence: 'public', format: 'P-{year}-{seq:3}'),
			]
		);
		$this->sequences->expects($this->exactly(count: 2))
			->method('reserveNext')
			->willReturn(4);

		$object = $this->objectWith([]);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertMatchesRegularExpression(
			pattern: '/^Z-\d{4}-00004$/',
			string: (string)($object->getObject()['identifier'] ?? '')
		);
		$this->assertMatchesRegularExpression(
			pattern: '/^P-\d{4}-004$/',
			string: (string)($object->getObject()['publicNumber'] ?? '')
		);

	}//end testASecondDeclaredIdentifierIsAlsoFilled()

	/**
	 * A supplied value is kept, and the counter is pushed past it.
	 *
	 * Both halves are asserted. Keeping the value without advancing leaves the
	 * counter at zero, so the next create issues number one and the collision
	 * surfaces on the hundred-and-twentieth create rather than the first.
	 *
	 * @return void
	 */
	public function testASuppliedValueIsKeptAndAdvancesTheCounter(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);
		$this->sequences->expects($this->never())->method('reserveNext');
		$this->sequences->expects($this->once())
			->method('advanceTo')
			->with(0, 0, $this->stringContains('case'), 120);

		$object = $this->objectWith(['identifier' => 'Z-2026-00120']);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame(
			expected: 'Z-2026-00120',
			actual: ($object->getObject()['identifier'] ?? null),
			message: 'a supplied identifier must be kept, not overwritten'
		);

	}//end testASuppliedValueIsKeptAndAdvancesTheCounter()

	/**
	 * A supplied value the format does not recognise is left entirely alone.
	 *
	 * An instance that numbered its cases by hand before the annotation
	 * existed still has to be importable, and its old numbers are not this
	 * counter's to reason about.
	 *
	 * @return void
	 */
	public function testAForeignSuppliedValueDoesNotTouchTheCounter(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);
		$this->sequences->expects($this->never())->method('reserveNext');
		$this->sequences->expects($this->never())->method('advanceTo');

		$this->listener->handle(new ObjectCreatingEvent($this->objectWith(['identifier' => 'OLD/7'])));

	}//end testAForeignSuppliedValueDoesNotTouchTheCounter()

	/**
	 * A schema declaring nothing never touches the counter.
	 *
	 * The control: without it, a listener that issued a number for every
	 * create would pass every test above.
	 *
	 * @return void
	 */
	public function testASchemaWithoutADeclarationIsUntouched(): void {
		$this->schemaWith(['title' => ['type' => 'string']]);
		$this->sequences->expects($this->never())->method('reserveNext');

		$object = $this->objectWith(['title' => 'A case']);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertSame(
			expected: 'A case',
			actual: ($object->getObject()['title'] ?? null),
			message: 'a schema declaring nothing must leave the body as it was'
		);
		$this->assertArrayNotHasKey(
			key: 'identifier',
			array: $object->getObject(),
			message: 'nothing may be written where no identifier was declared'
		);

	}//end testASchemaWithoutADeclarationIsUntouched()

	/**
	 * A counter that cannot be read refuses the create rather than issuing none.
	 *
	 * An object created without the number it was declared to carry is a record
	 * somebody will quote in a letter. Swallowing the failure would ship it.
	 *
	 * @return void
	 */
	public function testAnUnavailableCounterRefusesTheCreate(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);
		$this->sequences->method('reserveNext')->willThrowException(new \RuntimeException('db down'));

		$event = new ObjectCreatingEvent($this->objectWith([]));
		$this->listener->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertNotEmpty(actual: $event->getErrors());

	}//end testAnUnavailableCounterRefusesTheCreate()

	/**
	 * An update that changes the identifier is refused.
	 *
	 * @return void
	 */
	public function testAnUpdateThatChangesTheIdentifierIsRefused(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);

		$event = new ObjectUpdatingEvent(
			$this->objectWith(['identifier' => 'Z-2026-00009']),
			$this->objectWith(['identifier' => 'Z-2026-00001'])
		);

		$this->listener->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(
			expected: GeneratedIdentifierListener::ERROR_CODE,
			actual: ($event->getErrors()['code'] ?? null)
		);

	}//end testAnUpdateThatChangesTheIdentifierIsRefused()

	/**
	 * An update that leaves the identifier alone is allowed.
	 *
	 * The control for the refusal: without it, a guard that refused every
	 * update would look exactly like a guard that works, and nothing on a
	 * schema with a case number could ever be edited again.
	 *
	 * @return void
	 */
	public function testAnUpdateThatKeepsTheIdentifierIsAllowed(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);

		$event = new ObjectUpdatingEvent(
			$this->objectWith(['identifier' => 'Z-2026-00001', 'title' => 'New title']),
			$this->objectWith(['identifier' => 'Z-2026-00001', 'title' => 'Old title'])
		);

		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());

	}//end testAnUpdateThatKeepsTheIdentifierIsAllowed()

	/**
	 * An update that omits the identifier is not a renumbering.
	 *
	 * A partial write that never mentioned the number is an ordinary edit.
	 * Refusing it would refuse most of them.
	 *
	 * @return void
	 */
	public function testAPartialUpdateOmittingTheIdentifierIsAllowed(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);

		$event = new ObjectUpdatingEvent(
			$this->objectWith(['title' => 'New title']),
			$this->objectWith(['identifier' => 'Z-2026-00001', 'title' => 'Old title'])
		);

		$this->listener->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());

	}//end testAPartialUpdateOmittingTheIdentifierIsAllowed()

	/**
	 * An update never burns a number.
	 *
	 * @return void
	 */
	public function testAnUpdateNeverTakesANumber(): void {
		$this->schemaWith(['identifier' => $this->declaredProperty()]);
		$this->sequences->expects($this->never())->method('reserveNext');

		$this->listener->handle(
			new ObjectUpdatingEvent(
				$this->objectWith(['identifier' => 'Z-2026-00001']),
				$this->objectWith(['identifier' => 'Z-2026-00001'])
			)
		);

	}//end testAnUpdateNeverTakesANumber()

	/**
	 * Some other event is not this listener's business.
	 *
	 * @return void
	 */
	public function testAnUnrelatedEventIsIgnored(): void {
		$this->sequences->expects($this->never())->method('reserveNext');
		$this->schemaMapper->expects($this->never())->method('find');

		$this->listener->handle(new Event());

	}//end testAnUnrelatedEventIsIgnored()
}//end class
