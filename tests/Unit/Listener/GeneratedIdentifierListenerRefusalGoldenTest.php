<?php

/**
 * Golden tests for what GeneratedIdentifierListener refuses with, and logs.
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
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-a-generated-identifier-is-frozen-after-creation
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the exact refusal payloads and log lines, not only the error code.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\GeneratedIdentifierListener
 */
class GeneratedIdentifierListenerRefusalGoldenTest extends TestCase {

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
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private $logger;

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
		$this->sequences = $this->createMock(SequenceService::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->listener = new GeneratedIdentifierListener(
			$this->schemaMapper,
			$this->sequences,
			$this->logger,
			new ObjectEventGate($this->createMock(IEventDispatcher::class))
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
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$this->schemaMapper->method('find')->willReturn($schema);

	}//end schemaWith()

	/**
	 * One declared identifier property.
	 *
	 * @param string $sequence The counter's name.
	 *
	 * @return array<string, mixed> The property definition.
	 */
	private function declaredProperty(string $sequence): array {
		return [
			'type' => 'string',
			GeneratedIdentifierDeclaration::ANNOTATION => [
				'sequence' => $sequence,
				'format' => 'Z-{year}-{seq:5}',
				'resetOn' => 'year',
			],
		];

	}//end declaredProperty()

	/**
	 * A real object carrying a body and schema 777.
	 *
	 * @param array<string, mixed> $body The object's data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function objectWith(array $body): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('777');
		$object->setObject($body);

		return $object;

	}//end objectWith()

	/**
	 * A failing second counter refuses the create naming that property, and writes nothing.
	 *
	 * @return void
	 */
	public function testASecondUnavailableCounterRefusesWithItsOwnNameAndWritesNothing(): void {
		$this->schemaWith(
			properties: [
				'identifier' => $this->declaredProperty(sequence: 'case'),
				'publicNumber' => $this->declaredProperty(sequence: 'public'),
			]
		);
		$calls = 0;
		$this->sequences->method('reserveNext')->willReturnCallback(
			static function () use (&$calls): int {
				$calls++;
				if ($calls === 2) {
					throw new \RuntimeException('db down');
				}

				return 3;
			}
		);
		$this->logger->expects($this->once())
			->method('error')
			->with('[GeneratedIdentifierListener] could not issue publicNumber: db down', $this->anything());

		$object = $this->objectWith(body: ['title' => 'A case']);
		$event = new ObjectCreatingEvent($object);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => 'generated-identifier-unavailable',
				'message' => 'The identifier for "publicNumber" could not be issued, so the object was not created.',
				'property' => 'publicNumber',
				'sequence' => 'public',
			],
			$event->getErrors()
		);
		$this->assertArrayNotHasKey('identifier', $object->getObject());
		$this->assertArrayNotHasKey('publicNumber', $object->getObject());

	}//end testASecondUnavailableCounterRefusesWithItsOwnNameAndWritesNothing()

	/**
	 * A changed identifier is refused with the exact payload, naming the issued value.
	 *
	 * @return void
	 */
	public function testAChangedIdentifierRefusalPayload(): void {
		$this->schemaWith(
			properties: [
				'identifier' => $this->declaredProperty(sequence: 'case'),
				'publicNumber' => $this->declaredProperty(sequence: 'public'),
			]
		);

		$event = new ObjectUpdatingEvent(
			$this->objectWith(body: ['identifier' => 'Z-2026-00001', 'publicNumber' => 'P-9']),
			$this->objectWith(body: ['identifier' => 'Z-2026-00001', 'publicNumber' => 'P-1'])
		);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => GeneratedIdentifierListener::ERROR_CODE,
				'message' => '"publicNumber" is a generated identifier and cannot be changed. It was issued as "P-1".',
				'property' => 'publicNumber',
				'issued' => 'P-1',
			],
			$event->getErrors()
		);

	}//end testAChangedIdentifierRefusalPayload()

	/**
	 * An identifier first set on update (none issued before) is not a renumbering.
	 *
	 * @return void
	 */
	public function testSettingAnIdentifierThatWasNeverIssuedIsAllowed(): void {
		$this->schemaWith(properties: ['identifier' => $this->declaredProperty(sequence: 'case')]);

		$event = new ObjectUpdatingEvent(
			$this->objectWith(body: ['identifier' => 'Z-2026-00009']),
			$this->objectWith(body: ['title' => 'x'])
		);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getErrors());

	}//end testSettingAnIdentifierThatWasNeverIssuedIsAllowed()

	/**
	 * An unusable declaration is logged with schema and property, and skipped; the usable one is filled.
	 *
	 * @return void
	 */
	public function testAnUnusableDeclarationIsLoggedAndSkipped(): void {
		$this->schemaWith(
			properties: [
				'broken' => ['type' => 'string', GeneratedIdentifierDeclaration::ANNOTATION => ['sequence' => 'x']],
				'identifier' => $this->declaredProperty(sequence: 'case'),
			]
		);
		$this->sequences->expects($this->once())->method('reserveNext')->willReturn(2);
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringStartsWith('[GeneratedIdentifierListener] schema 777 property broken has an unusable declaration: '),
				$this->callback(
					static fn (array $context): bool => array_keys($context) === ['file', 'line']
						&& $context['file'] === (new \ReflectionClass(GeneratedIdentifierListener::class))->getFileName()
				)
			);

		$object = $this->objectWith(body: []);
		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertArrayNotHasKey('broken', $object->getObject());
		$this->assertMatchesRegularExpression('/^Z-\d{4}-00002$/', (string)$object->getObject()['identifier']);

	}//end testAnUnusableDeclarationIsLoggedAndSkipped()
}//end class
