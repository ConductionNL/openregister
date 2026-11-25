<?php

/**
 * ObjectKeyOrderRoundTripTest: object-typed property keys keep their submitted order through the save and read steps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/put-preserve-key-order/specs/objects-crud/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\DefaultValueContext;
use OCA\OpenRegister\Service\Object\SaveObject\EmptyValues;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * REQ-OBJ-KO-01: no step of validate, store and read reorders the keys of an object-typed property.
 *
 * Each step is pure PHP over arrays, so the test runs the submitted object through
 * the defaults, the empty-value sanitiser, the column encoding and the row decoding
 * in turn, then saves the reversed order (a drag-reorder) and reads it back.
 * The database column type is covered by MagicColumnSqlTypesTest and
 * MagicMapperKeyOrderColumnTypeTest.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\PropertyDefaults
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\EmptyValues
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicPropertyColumns
 */
class ObjectKeyOrderRoundTripTest extends TestCase {

	private Schema $schema;

	private IURLGenerator $urls;

	protected function setUp(): void {
		parent::setUp();
		$this->urls = $this->createMock(IURLGenerator::class);
		$this->schema = new Schema();
		$this->schema->setUuid('schema-1');
		$this->schema->setProperties(
			[
				'title' => ['type' => 'string'],
				'rows' => ['type' => 'object'],
				'status' => ['type' => 'string', 'default' => 'open'],
				'kind' => ['type' => 'string', 'const' => 'board'],
			]
		);
	}//end setUp()

	/**
	 * The data as the save path leaves it, then as a row stores and reads it.
	 *
	 * @param array $data The submitted data.
	 *
	 * @return array{saved: array, read: array}
	 */
	private function roundTrip(array $data): array {
		$defaults = new PropertyDefaults($this->createMock(MetadataHydrationHandler::class), $this->urls);
		$saved = $defaults->always($this->schema, $data);
		$saved = $defaults->forSave($this->schema, new DefaultValueContext(data: $saved, twigContext: $saved));
		$saved = (new EmptyValues($this->urls))->sanitize($saved, $this->schema);

		$columns = (new MagicPropertyColumns($this->createMock(ContainerInterface::class), $this->createMock(LoggerInterface::class)))
			->forObject($saved, $this->schema);

		$mapper = (new ReflectionClass(MagicMapper::class))->newInstanceWithoutConstructor();
		$decode = new \ReflectionMethod(MagicMapper::class, 'decodedColumnValue');
		$read = [];
		foreach ($columns as $column => $value) {
			$read[$column] = $decode->invoke($mapper, $value);
		}

		return ['saved' => $saved, 'read' => $read];
	}//end roundTrip()

	public function testSubmittedKeyOrderSurvivesSaveAndRead(): void {
		$result = $this->roundTrip(['title' => 'Board', 'rows' => ['zeta' => 1, 'alpha' => 2, 'mid' => ['b' => 1, 'a' => 2]]]);

		$this->assertSame(['zeta', 'alpha', 'mid'], array_keys($result['saved']['rows']));
		$this->assertSame(['zeta', 'alpha', 'mid'], array_keys($result['read']['rows']));
		$this->assertSame(['b', 'a'], array_keys($result['read']['rows']['mid']));
	}//end testSubmittedKeyOrderSurvivesSaveAndRead()

	public function testADragReorderPersistsAndAnUnchangedSiblingKeepsItsValue(): void {
		$first = $this->roundTrip(['title' => 'Board', 'rows' => ['a' => 1, 'b' => 2, 'c' => 3]]);
		$this->assertSame(['a', 'b', 'c'], array_keys($first['read']['rows']));

		// PUT semantic: the stored object carried forward, with the reordered property replacing its old value.
		$reordered = array_merge($first['read'], ['rows' => ['c' => 3, 'b' => 2, 'a' => 1]]);
		$second = $this->roundTrip($reordered);

		$this->assertSame(['c', 'b', 'a'], array_keys($second['read']['rows']));
		$this->assertSame(['c' => 3, 'b' => 2, 'a' => 1], $second['read']['rows']);
		$this->assertSame('Board', $second['read']['title']);
	}//end testADragReorderPersistsAndAnUnchangedSiblingKeepsItsValue()

	public function testDefaultsAndConstantsAreAppendedAfterTheSubmittedKeys(): void {
		$result = $this->roundTrip(['rows' => ['y' => 1, 'x' => 2], 'title' => 'Board']);

		$this->assertSame(['rows', 'title', 'status', 'kind'], array_keys($result['saved']));
		$this->assertSame(['y', 'x'], array_keys($result['saved']['rows']));
	}//end testDefaultsAndConstantsAreAppendedAfterTheSubmittedKeys()

	public function testAPresentNullDefaultIsFilledInPlace(): void {
		$result = $this->roundTrip(['status' => null, 'rows' => ['b' => 1, 'a' => 2], 'title' => 'Board']);

		$this->assertSame(['status', 'rows', 'title', 'kind'], array_keys($result['saved']));
		$this->assertSame('open', $result['saved']['status']);
	}//end testAPresentNullDefaultIsFilledInPlace()
}//end class
