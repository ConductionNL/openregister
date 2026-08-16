<?php

declare(strict_types=1);

/**
 * ValidateObject memoization tests — request-scoped validator + prepared-schema cache.
 *
 * Proves that validating N objects against the same schema builds the Opis
 * Validator once, prepares the schema pipeline once (cached per
 * schemaId:version), that a schema version bump produces a fresh cache
 * entry, and that memoization never changes validation outcomes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class ValidateObjectMemoizationTest extends TestCase {

	private ValidateObject $handler;

	private SchemaMapper&MockObject $schemaMapper;

	protected function setUp(): void {
		parent::setUp();

		$config = $this->createMock(IAppConfig::class);
		$objectMapper = $this->createMock(MagicMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$logger = $this->createMock(LoggerInterface::class);

		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost');

		$this->handler = new ValidateObject(
			$config,
			$objectMapper,
			$this->schemaMapper,
			$urlGenerator,
			$logger,
			$this->createMock(\OCP\IUserManager::class)
		);
	}//end setUp()

	/**
	 * Build a Schema entity with a reflected id, a version, and properties.
	 */
	private function buildSchema(int $id, string $version, array $properties): Schema {
		$schema = new Schema();
		$ref = new ReflectionClass($schema);
		$prop = $ref->getProperty('id');
		$prop->setAccessible(true);
		$prop->setValue($schema, $id);
		$schema->setUuid('schema-uuid-' . $id);
		$schema->setSlug('memo-schema-' . $id);
		$schema->setTitle('Memo Schema');
		$schema->setVersion($version);
		$schema->setProperties($properties);
		$schema->setConfiguration([]);
		$schema->setRequired([]);
		return $schema;
	}//end buildSchema()

	/**
	 * Read a private property off the handler.
	 */
	private function readPrivate(string $property): mixed {
		$ref = new ReflectionClass($this->handler);
		$prop = $ref->getProperty($property);
		$prop->setAccessible(true);
		return $prop->getValue($this->handler);
	}//end readPrivate()

	/**
	 * Validating two objects against the same schema populates exactly one
	 * prepared-schema cache entry (keyed id:version), reuses one Validator
	 * instance, and returns correct outcomes for valid AND invalid input —
	 * proving memoization does not leak state between validations.
	 */
	public function testRepeatValidationReusesPreparedSchemaAndValidator(): void {
		$schema = $this->buildSchema(
			7,
			'1.0.0',
			[
				'title' => ['type' => 'string'],
				'count' => ['type' => 'integer'],
			]
		);

		$resultValid = $this->handler->validateObject(object: ['title' => 'ok', 'count' => 3], schema: $schema);
		$this->assertTrue($resultValid->isValid());

		$cache = $this->readPrivate('preparedSchemaCache');
		$this->assertCount(1, $cache);
		$this->assertArrayHasKey('7:1.0.0', $cache);
		$validatorAfterFirst = $this->readPrivate('validator');
		$this->assertInstanceOf(Validator::class, $validatorAfterFirst);

		// Second validation: same cache entry, same validator, and an INVALID
		// object still fails (the cached schema is not weakened by reuse).
		$resultInvalid = $this->handler->validateObject(object: ['title' => 'ok', 'count' => 'not-an-int'], schema: $schema);
		$this->assertFalse($resultInvalid->isValid());

		$cacheAfterSecond = $this->readPrivate('preparedSchemaCache');
		$this->assertCount(1, $cacheAfterSecond);
		$this->assertSame($validatorAfterFirst, $this->readPrivate('validator'));

		// Third validation with valid input still passes.
		$resultValidAgain = $this->handler->validateObject(object: ['title' => 'still ok'], schema: $schema);
		$this->assertTrue($resultValidAgain->isValid());
	}//end testRepeatValidationReusesPreparedSchemaAndValidator()

	/**
	 * A schema version bump gets its own cache entry — stale prepared
	 * schemas are never served across versions.
	 */
	public function testVersionBumpCreatesFreshCacheEntry(): void {
		$schemaV1 = $this->buildSchema(7, '1.0.0', ['title' => ['type' => 'string']]);
		$this->handler->validateObject(object: ['title' => 'ok'], schema: $schemaV1);

		// Version 2 makes `count` required — the fresh pipeline must pick
		// that up even though schema id 7 was already cached.
		$schemaV2 = $this->buildSchema(
			7,
			'2.0.0',
			[
				'title' => ['type' => 'string'],
				'count' => ['type' => 'integer'],
			]
		);
		$schemaV2->setRequired(['count']);

		$resultMissingRequired = $this->handler->validateObject(object: ['title' => 'ok'], schema: $schemaV2);
		$this->assertFalse($resultMissingRequired->isValid());

		$cache = $this->readPrivate('preparedSchemaCache');
		$this->assertCount(2, $cache);
		$this->assertArrayHasKey('7:1.0.0', $cache);
		$this->assertArrayHasKey('7:2.0.0', $cache);
	}//end testVersionBumpCreatesFreshCacheEntry()

	/**
	 * A caller-supplied custom schema object bypasses the cache entirely
	 * (no stable cache key exists for ad-hoc schema objects).
	 */
	public function testCustomSchemaObjectBypassesCache(): void {
		$schemaObject = (object)[
			'type' => 'object',
			'properties' => (object)['name' => (object)['type' => 'string']],
		];

		$result = $this->handler->validateObject(object: ['name' => 'x'], schema: null, schemaObject: $schemaObject);
		$this->assertTrue($result->isValid());

		$this->assertSame([], $this->readPrivate('preparedSchemaCache'));
	}//end testCustomSchemaObjectBypassesCache()

	/**
	 * Computed properties are stripped from the object per cached prepared
	 * schema on EVERY call, not only the first.
	 */
	public function testComputedPropertiesStrippedOnEveryCall(): void {
		$schema = $this->buildSchema(
			9,
			'1.0.0',
			[
				'title' => ['type' => 'string'],
				'score' => [
					'type' => 'number',
					'computed' => true,
				],
			]
		);

		// `score` carries a type-violating value; because it is computed it
		// must be stripped before validation on both the cold and warm path.
		$first = $this->handler->validateObject(object: ['title' => 'a', 'score' => 'bogus'], schema: $schema);
		$this->assertTrue($first->isValid());

		$second = $this->handler->validateObject(object: ['title' => 'b', 'score' => 'bogus'], schema: $schema);
		$this->assertTrue($second->isValid());
	}//end testComputedPropertiesStrippedOnEveryCall()
}//end class
