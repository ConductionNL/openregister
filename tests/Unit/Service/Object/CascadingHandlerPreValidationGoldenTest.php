<?php

/**
 * Golden for CascadingHandler::handlePreValidationCascading
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Object
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\CascadeTarget;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\CascadingHandler;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Object\UtilityHandler;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Which nested objects the pre-validation cascade creates, and what it puts in their place.
 *
 * @covers \OCA\OpenRegister\Service\Object\CascadingHandler
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Object\CascadeTarget
 * @uses \OCA\OpenRegister\Db\Schema\SchemaPropertyDocument
 */
class CascadingHandlerPreValidationGoldenTest extends TestCase {

	private const UUID_A = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
	private const UUID_B = 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb';
	private const PARENT = 'cccccccc-cccc-cccc-cccc-cccccccccccc';

	/**
	 * Every createRelatedObject call, as [objectData, definition, parentUuid, register].
	 *
	 * @var array<int, array>
	 */
	public array $calls = [];

	/**
	 * A schema with every kind of inversedBy property the cascade distinguishes.
	 *
	 * @return Schema
	 */
	private static function schema(): Schema {
		$schema = new Schema();
		$schema->setUuid('s-1');
		$schema->setProperties(
			[
				'notes'   => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/note', 'inversedBy' => 'incident']],
				'owner'   => ['type' => 'object', '$ref' => '#/components/schemas/person', 'inversedBy' => 'owns'],
				'flatArr' => ['type' => 'array', 'inversedBy' => 'x', 'items' => ['type' => 'string']],
				'other'   => ['type' => 'object', 'inversedBy' => 'y'],
				'missing' => ['type' => 'object', 'inversedBy' => 'z'],
				'plain'   => ['type' => 'string'],
				'tags'    => ['type' => 'array', 'items' => ['type' => 'string']],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * A cascading handler on a real save handler, whose createRelatedObject records and answers.
	 *
	 * @param bool $realSave Whether the save handler is a real SaveObject (a mock hides its URL generator).
	 *
	 * @return CascadingHandler
	 */
	private function handler(bool $realSave=true): CascadingHandler {
		if ($realSave === true) {
			$save = (new ReflectionClass(SaveObject::class))->newInstanceWithoutConstructor();
			(new ReflectionClass(SaveObject::class))->getProperty('urlGenerator')->setValue($save, $this->createMock(IURLGenerator::class));
		} else {
			$save = $this->createMock(SaveObject::class);
		}

		$utility = $this->createMock(UtilityHandler::class);
		$utility->method('isUuid')->willReturnCallback(
			static fn (mixed $value): bool => is_string($value) && preg_match('/^[0-9a-f-]{36}$/', $value) === 1
		);

		$test = $this;
		return new class($save, $this->createMock(SchemaMapper::class), $utility, $test) extends CascadingHandler {
			/**
			 * @param SaveObject                              $save    The save handler.
			 * @param SchemaMapper                            $schemas The schema mapper.
			 * @param UtilityHandler                          $utility The utility handler.
			 * @param CascadingHandlerPreValidationGoldenTest $test    Where calls are recorded.
			 */
			public function __construct(SaveObject $save, SchemaMapper $schemas, UtilityHandler $utility, private CascadingHandlerPreValidationGoldenTest $test) {
				parent::__construct(saveHandler: $save, schemaMapper: $schemas, utilityHandler: $utility, logger: new NullLogger());
			}

			public function createRelatedObject(array $objectData, CascadeTarget $target): ?string {
				$this->test->calls[] = [$objectData, $target->definition(), $target->parentUuid, $target->register];
				return ($objectData['fail'] ?? false) === true ? null : 'created-' . count($this->test->calls);
			}
		};
	}//end handler()

	/**
	 * An object touching every branch: nested items, uuids, junk, a failing create, empty and absent values.
	 *
	 * @return array
	 */
	private static function object(): array {
		return [
			'notes'   => [['text' => 'one'], self::UUID_A, 'not-a-uuid', ['text' => 'two', 'fail' => true], 42, ['text' => 'three']],
			'owner'   => ['name' => 'Ann'],
			'flatArr' => [['k' => 'v']],
			'other'   => ['fail' => true],
			'missing' => [],
			'plain'   => 'p',
			'tags'    => [['t' => 1]],
		];
	}//end object()

	public function testNestedObjectsAreCreatedAndReplacedByTheirUuids(): void {
		[$object, $uuid] = $this->handler()->handlePreValidationCascading(self::object(), self::schema(), self::PARENT, 10);

		$this->assertSame(self::PARENT, $uuid);
		$this->assertSame(
			[
				'notes'   => ['created-1', self::UUID_A, ['text' => 'two', 'fail' => true], 'created-3'],
				'owner'   => 'created-4',
				'flatArr' => [['k' => 'v']],
				'other'   => ['fail' => true],
				'missing' => [],
				'plain'   => 'p',
				'tags'    => [['t' => 1]],
			],
			$object
		);
		$note = ['$ref' => '#/components/schemas/note', 'inversedBy' => 'incident'];
		$this->assertSame(
			[
				[['text' => 'one'], $note, self::PARENT, 10],
				[['text' => 'two', 'fail' => true], $note, self::PARENT, 10],
				[['text' => 'three'], $note, self::PARENT, 10],
				[['name' => 'Ann'], ['type' => 'object', '$ref' => '#/components/schemas/person', 'inversedBy' => 'owns'], self::PARENT, 10],
				[['fail' => true], ['type' => 'object', 'inversedBy' => 'y'], self::PARENT, 10],
			],
			$this->calls
		);
	}

	public function testAMissingParentUuidIsGeneratedAndHandedToEveryCreate(): void {
		[$object, $uuid] = $this->handler()->handlePreValidationCascading(['owner' => ['name' => 'Ann']], self::schema(), null, null);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string) $uuid);
		$this->assertSame(['owner' => 'created-1'], $object);
		$this->assertSame($uuid, $this->calls[0][2]);
		$this->assertNull($this->calls[0][3]);
	}

	public function testWithoutInversedByPropertiesNothingChangesAndNoUuidIsMade(): void {
		$schema = new Schema();
		$schema->setProperties(['plain' => ['type' => 'string'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']]]);

		$this->assertSame([['plain' => ['x' => 1]], null], $this->handler()->handlePreValidationCascading(['plain' => ['x' => 1]], $schema, null, 10));
		$this->assertSame([], $this->calls);
	}

	public function testAnUnreadableSchemaLeavesTheObjectAsSent(): void {
		$result = $this->handler(realSave: false)->handlePreValidationCascading(self::object(), self::schema(), null, 10);

		$this->assertSame([self::object(), null], $result);
		$this->assertSame([], $this->calls);
	}

	public function testOnlyEmptyOrAbsentInversedByValuesStillGetAParentUuid(): void {
		[$object, $uuid] = $this->handler()->handlePreValidationCascading(['missing' => [], 'notes' => []], self::schema(), null, 10);

		$this->assertSame(['missing' => [], 'notes' => []], $object);
		$this->assertNotNull($uuid);
		$this->assertSame([], $this->calls);
	}
}//end class
