<?php

/**
 * Unit tests for a generated identifier whose counter is split per value of a property.
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
 * @spec openspec/changes/generated-identifier-partition/specs/computed-fields/spec.md#requirement-a-sequence-may-count-separately-per-value-of-a-property
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\GeneratedIdentifierListener;
use OCA\OpenRegister\Service\Object\ObjectEventGate;
use OCA\OpenRegister\Service\RunningNumbers;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierDeclaration;
use OCA\OpenRegister\Service\Schemas\GeneratedIdentifierException;
use OCA\OpenRegister\Service\SequenceScope;
use OCA\OpenRegister\Service\SequenceService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Two units number from one each; an object without a unit keeps the shared counter.
 *
 * The counter itself is a double that keeps one number per scope key, so the
 * tests read what the listener asked for the way the database would answer it.
 *
 * @coversDefaultClass \OCA\OpenRegister\Listener\GeneratedIdentifierListener
 */
class GeneratedIdentifierPartitionTest extends TestCase {

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
	 * The last number handed out per scope key.
	 *
	 * @var array<string, int>
	 */
	private array $counters = [];

	/**
	 * Every scope key a number was asked from, in order.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	/**
	 * Build fresh doubles for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->counters = [];
		$this->asked = [];
		$this->sequences = $this->createMock(originalClassName: SequenceService::class);
		$this->sequences->method('reserveNext')->willReturnCallback(
			function (SequenceScope $scope): int {
				$this->asked[] = $scope->scopeKey;
				$this->counters[$scope->scopeKey] = (($this->counters[$scope->scopeKey] ?? 0) + 1);
				return $this->counters[$scope->scopeKey];
			}
		);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->listener = new GeneratedIdentifierListener(
			$this->schemaMapper,
			new RunningNumbers($this->sequences),
			$this->createMock(originalClassName: LoggerInterface::class),
			new ObjectEventGate($this->createMock(originalClassName: IEventDispatcher::class))
		);

		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn(['registrationNumber' => self::partitionedProperty()]);
		$this->schemaMapper->method('find')->willReturn($schema);

	}//end setUp()

	/**
	 * A registration number counted per unit and reset each year.
	 *
	 * @param mixed  $partitionBy The partitionBy value to declare.
	 * @param string $sequence    The sequence name to declare.
	 *
	 * @return array<string, mixed> The property definition.
	 */
	private static function partitionedProperty(mixed $partitionBy='unit', string $sequence='post'): array {
		return [
			'type' => 'string',
			GeneratedIdentifierDeclaration::ANNOTATION => [
				'sequence' => $sequence,
				'format' => '{year}-{seq:5}',
				'resetOn' => 'year',
				'partitionBy' => $partitionBy,
			],
		];

	}//end partitionedProperty()

	/**
	 * Create one object through the listener and answer what it was numbered.
	 *
	 * @param array<string, mixed> $body The object's data.
	 *
	 * @return string The issued number.
	 */
	private function create(array $body): string {
		$object = new ObjectEntity();
		$object->setSchema('777');
		$object->setObject($body);
		$this->listener->handle(new ObjectCreatingEvent($object));

		return (string)($object->getObject()['registrationNumber'] ?? '');

	}//end create()

	/**
	 * Two units each start at one, and each counts on from its own last number.
	 *
	 * @return void
	 */
	public function testTwoUnitsEachStartAtOne(): void {
		$year = date('Y');

		$this->assertSame(expected: $year.'-00001', actual: $this->create(body: ['unit' => 'Finance']));
		$this->assertSame(expected: $year.'-00001', actual: $this->create(body: ['unit' => 'Housing']));
		$this->assertSame(expected: $year.'-00002', actual: $this->create(body: ['unit' => 'Finance']));
		$this->assertNotSame(expected: $this->asked[0], actual: $this->asked[1], message: 'two units must not share a counter');
		$this->assertSame(expected: $this->asked[0], actual: $this->asked[2]);

	}//end testTwoUnitsEachStartAtOne()

	/**
	 * The year still keys the counter: the unit is added to the key, not put in its place.
	 *
	 * @return void
	 */
	public function testThePeriodStillKeysAPartitionedCounter(): void {
		$this->create(body: ['unit' => 'Finance']);

		$this->assertStringEndsWith(suffix: '|'.date('Y'), string: $this->asked[0]);
		$this->assertStringContainsString(needle: 'post@Finance', haystack: $this->asked[0]);

	}//end testThePeriodStillKeysAPartitionedCounter()

	/**
	 * An object without a unit draws from the counter the sequence had before it was split.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutAUnitKeepsTheSharedCounter(): void {
		$this->create(body: []);
		$this->create(body: ['unit' => '  ']);
		$this->create(body: ['unit' => ['Finance']]);

		$this->assertSame(expected: ['gen:post|'.date('Y')], actual: array_values(array_unique($this->asked)));

	}//end testAnObjectWithoutAUnitKeepsTheSharedCounter()

	/**
	 * A unit value cannot pose as another sequence's name or period.
	 *
	 * @return void
	 */
	public function testAUnitValueIsEncodedInTheKey(): void {
		$this->create(body: ['unit' => 'A|2020']);

		$this->assertSame(expected: 'gen:post@A%7C2020|'.date('Y'), actual: $this->asked[0]);

	}//end testAUnitValueIsEncodedInTheKey()

	/**
	 * A key that would overflow the 64-character scope_key column is hashed, and
	 * each long unit still keeps a counter of its own.
	 *
	 * @return void
	 */
	public function testALongUnitValueStillFitsTheScopeKeyColumn(): void {
		$schema = $this->createMock(originalClassName: Schema::class);
		$schema->method('getProperties')->willReturn(
			['registrationNumber' => self::partitionedProperty(sequence: 'filinq-post-register')]
		);
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->listener = new GeneratedIdentifierListener(
			$this->schemaMapper,
			new RunningNumbers($this->sequences),
			$this->createMock(originalClassName: LoggerInterface::class),
			new ObjectEventGate($this->createMock(originalClassName: IEventDispatcher::class))
		);
		$year = date('Y');
		$finance = '6f1c0d3e-2b4a-4c5d-9e8f-0a1b2c3d4e5f';
		$housing = '7a2d1e4f-3c5b-4d6e-8f9a-1b2c3d4e5f60';

		$this->assertSame(expected: $year.'-00001', actual: $this->create(body: ['unit' => $finance]));
		$this->assertSame(expected: $year.'-00001', actual: $this->create(body: ['unit' => $housing]));
		$this->assertSame(expected: $year.'-00002', actual: $this->create(body: ['unit' => $finance]));

		foreach ($this->asked as $key) {
			$this->assertLessThanOrEqual(expected: 64, actual: strlen($key), message: $key);
			$this->assertStringEndsWith(suffix: '|'.$year, string: $key);
		}

		$this->assertNotSame(expected: $this->asked[0], actual: $this->asked[1], message: 'two units must not share a counter');
		$this->assertSame(expected: $this->asked[0], actual: $this->asked[2]);
		$this->assertSame(expected: 'gen:#'.sha1('filinq-post-register@'.$finance).'|'.$year, actual: $this->asked[0]);

	}//end testALongUnitValueStillFitsTheScopeKeyColumn()

	/**
	 * A supplied number pushes on the counter of its own unit, not the shared one.
	 *
	 * @return void
	 */
	public function testASuppliedNumberAdvancesItsOwnUnitsCounter(): void {
		$seen = null;
		$this->sequences->expects($this->once())
			->method('advanceTo')
			->willReturnCallback(
				static function (SequenceScope $scope, int $reserved) use (&$seen): void {
					$seen = [$scope->scopeKey, $reserved];
				}
			);

		$this->create(body: ['unit' => 'Housing', 'registrationNumber' => '2026-00042']);

		$this->assertSame(expected: ['gen:post@Housing|2026', 42], actual: $seen);

	}//end testASuppliedNumberAdvancesItsOwnUnitsCounter()

	/**
	 * The declaration reads `partitionBy`, and a sequence without it has none.
	 *
	 * @return void
	 */
	public function testTheDeclarationReadsPartitionBy(): void {
		$this->assertSame(expected: 'unit', actual: GeneratedIdentifierDeclaration::fromProperty(property: self::partitionedProperty())?->getPartitionBy());

		$plain = self::partitionedProperty();
		unset($plain[GeneratedIdentifierDeclaration::ANNOTATION]['partitionBy']);
		$this->assertNull(actual: GeneratedIdentifierDeclaration::fromProperty(property: $plain)?->getPartitionBy());

	}//end testTheDeclarationReadsPartitionBy()

	/**
	 * A path, an expression or a non-text value is refused at schema save.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedPartitions(): array {
		return [
			'a path' => ['unit.name'],
			'empty' => [''],
			'a placeholder' => ['{unit}'],
			'a number' => [7],
			'a list' => [['unit']],
		];

	}//end refusedPartitions()

	/**
	 * A malformed `partitionBy` is refused rather than counted under a name nobody meant.
	 *
	 * @param mixed $partitionBy The value declared.
	 *
	 * @dataProvider refusedPartitions
	 *
	 * @return void
	 */
	public function testAMalformedPartitionByIsRefused(mixed $partitionBy): void {
		$this->expectException(exception: GeneratedIdentifierException::class);
		$this->expectExceptionMessage(message: 'partitionBy');

		GeneratedIdentifierDeclaration::fromProperty(property: self::partitionedProperty(partitionBy: $partitionBy), path: 'registrationNumber');

	}//end testAMalformedPartitionByIsRefused()
}//end class
