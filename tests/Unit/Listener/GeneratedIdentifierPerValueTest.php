<?php

/**
 * Unit tests for a generated identifier counted per value of another property.
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
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `per` gives every value of a property (a unit, a department) its own counter.
 *
 * The counter double below is a real in-memory counter keyed on the scope it
 * is handed, so "two units each start at 1" is asserted on the numbers the
 * listener writes, not on the call shape.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\GeneratedIdentifierListener
 */
class GeneratedIdentifierPerValueTest extends TestCase {

	/**
	 * The sequence service double.
	 *
	 * @var SequenceService&MockObject
	 */
	private $sequences;

	/**
	 * The listener under test.
	 *
	 * @var GeneratedIdentifierListener
	 */
	private GeneratedIdentifierListener $listener;

	/**
	 * The last number handed out per scope key.
	 *
	 * @var array<string, int>
	 */
	private array $counters = [];

	/**
	 * Wire a schema whose `registrationNumber` is counted per `unit` and per year.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->sequences = $this->createMock(originalClassName: SequenceService::class);
		$this->sequences->method('reserveNext')->willReturnCallback(
			function (SequenceScope $scope): int {
				$this->counters[$scope->scopeKey] = (($this->counters[$scope->scopeKey] ?? 0) + 1);
				return $this->counters[$scope->scopeKey];
			}
		);

		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'unit'               => ['type' => 'string'],
				'registrationNumber' => [
					'type' => 'string',
					GeneratedIdentifierDeclaration::ANNOTATION => [
						'sequence' => 'post',
						'format'   => 'P-{year}-{seq:4}',
						'resetOn'  => 'year',
						'per'      => 'unit',
					],
				],
			]
		);
		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($schema);

		$this->listener = new GeneratedIdentifierListener(
			$schemaMapper,
			$this->sequences,
			$this->createMock(originalClassName: LoggerInterface::class),
			new ObjectEventGate($this->createMock(originalClassName: IEventDispatcher::class))
		);

	}//end setUp()

	/**
	 * Create one object and answer the number it was given.
	 *
	 * @param array<string, mixed> $body The object's data.
	 *
	 * @return string The issued registration number.
	 */
	private function create(array $body): string {
		$object = new ObjectEntity();
		$object->setSchema('777');
		$object->setObject($body);
		$this->listener->handle(new ObjectCreatingEvent($object));

		return (string)($object->getObject()['registrationNumber'] ?? '');

	}//end create()

	/**
	 * Two units each start at 1, and each continues from its own count.
	 *
	 * @return void
	 */
	public function testTwoUnitsEachStartAtOne(): void {
		$year = date('Y');

		$this->assertSame(expected: 'P-'.$year.'-0001', actual: $this->create(body: ['unit' => 'north']));
		$this->assertSame(expected: 'P-'.$year.'-0001', actual: $this->create(body: ['unit' => 'south']));
		$this->assertSame(expected: 'P-'.$year.'-0002', actual: $this->create(body: ['unit' => 'north']));
		$this->assertSame(expected: 'P-'.$year.'-0002', actual: $this->create(body: ['unit' => 'south']));
		$this->assertCount(expectedCount: 2, haystack: $this->counters, message: 'one counter per unit');

	}//end testTwoUnitsEachStartAtOne()

	/**
	 * A unit's counter is still keyed on the year, so a new year starts it at 1 again.
	 *
	 * @return void
	 */
	public function testAUnitCounterIsKeyedOnTheYear(): void {
		$this->create(body: ['unit' => 'north']);

		$key = (string)array_key_first($this->counters);
		$this->assertStringStartsWith(prefix: 'gen:post|'.date('Y').'|', string: $key);
		$this->assertMatchesRegularExpression(pattern: '/\|[0-9a-f]{16}$/', string: $key);

	}//end testAUnitCounterIsKeyedOnTheYear()

	/**
	 * A reference to the unit keys the same counter as its bare id.
	 *
	 * @return void
	 */
	public function testAReferenceKeysTheSameCounterAsItsId(): void {
		$this->create(body: ['unit' => 'u-1']);
		$second = $this->create(body: ['unit' => ['id' => 'u-1', 'title' => 'North']]);

		$this->assertStringEndsWith(suffix: '-0002', string: $second);
		$this->assertCount(expectedCount: 1, haystack: $this->counters);

	}//end testAReferenceKeysTheSameCounterAsItsId()

	/**
	 * Objects without a unit share one counter of their own, apart from every unit's.
	 *
	 * @return void
	 */
	public function testObjectsWithoutAUnitShareTheirOwnCounter(): void {
		$this->create(body: ['unit' => 'north']);
		$this->create(body: []);
		$third = $this->create(body: ['unit' => null]);

		$this->assertStringEndsWith(suffix: '-0002', string: $third);
		$this->assertCount(expectedCount: 2, haystack: $this->counters);

	}//end testObjectsWithoutAUnitShareTheirOwnCounter()

	/**
	 * A supplied number advances the counter of its own unit only.
	 *
	 * @return void
	 */
	public function testASuppliedNumberAdvancesItsUnitsCounter(): void {
		$this->create(body: ['unit' => 'north']);
		$northKey = (string)array_key_first($this->counters);

		$advanced = null;
		$this->sequences->expects($this->once())
			->method('advanceTo')
			->willReturnCallback(
				static function (SequenceScope $scope, int $reserved) use (&$advanced): void {
					$advanced = [$scope->scopeKey, $reserved];
				}
			);

		$this->create(body: ['unit' => 'south', 'registrationNumber' => 'P-2026-0040']);

		$this->assertSame(expected: 40, actual: $advanced[1]);
		$this->assertStringStartsWith(prefix: 'gen:post|2026|', string: $advanced[0]);
		$this->assertNotSame(
			expected: $northKey,
			actual: $advanced[0],
			message: 'a number supplied in one unit must not advance another unit'
		);

	}//end testASuppliedNumberAdvancesItsUnitsCounter()
}//end class
