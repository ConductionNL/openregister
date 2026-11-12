<?php

/**
 * Which schema object validateObject() validates against, and when its preparation is cached.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Object\ValidationLookups;
use OCA\OpenRegister\Service\Object\ValidationSchemaSource;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @covers \OCA\OpenRegister\Service\Object\ValidateObject
 * @uses \OCA\OpenRegister\Service\Object\NotSuppliedHandler
 *
 * @spec openspec/specs/object-validation/spec.md
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Formats\UserFormat
 * @uses \OCA\OpenRegister\Formats\FormatFactory
 * @uses \OCA\OpenRegister\Service\Object\ExtendedFieldCheck
 * @uses \OCA\OpenRegister\Service\Object\RefLoopGuardParser
 * @uses \OCA\OpenRegister\Service\Object\ValidationLookups
 * @uses \OCA\OpenRegister\Service\Object\ValidationSchemaSource
 */
class ValidateObjectPreparedSchemaTest extends TestCase {

	private function validator(): ValidateObject {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getBaseUrl')->willReturn('http://localhost:8080');
		return new ValidateObject(
			schemas: new ValidationSchemaSource(config: $this->createMock(IAppConfig::class), schemaMapper: $this->createMock(SchemaMapper::class), urlGenerator: $urlGenerator),
			lookups: new ValidationLookups(objectMapper: $this->createMock(MagicMapper::class), userManager: $this->createMock(IUserManager::class)),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end validator()

	/**
	 * A schema entity whose own schema object requires `title`.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = $this->getMockBuilder(Schema::class)->onlyMethods(['getSchemaObject'])->getMock();
		(new \ReflectionProperty($schema, 'id'))->setValue($schema, 12);
		$schema->setVersion('1.0.0');
		$schema->setSlug('case');
		$schema->method('getSchemaObject')->willReturnCallback(
			fn () => json_decode('{"type":"object","required":["title"],"properties":{"title":{"type":"string"}}}')
		);
		return $schema;
	}//end schema()

	private function cache(ValidateObject $validator): array {
		return (new \ReflectionProperty($validator, 'preparedSchemaCache'))->getValue($validator);
	}//end cache()

	public function testTheSchemaEntitysOwnSchemaObjectIsUsedAndCached(): void {
		$validator = $this->validator();

		$this->assertFalse($validator->validateObject(['note' => 'n'], $this->schema())->isValid());
		$this->assertSame(['12:1.0.0'], array_keys($this->cache($validator)));
	}//end testTheSchemaEntitysOwnSchemaObjectIsUsedAndCached()

	public function testACachedPreparationIsReused(): void {
		$validator = $this->validator();
		$open = ['schemaObject' => json_decode('{"type":"object","properties":{"title":{"type":"string"}}}'), 'computed' => [], 'required' => []];
		(new \ReflectionProperty($validator, 'preparedSchemaCache'))->setValue($validator, ['12:1.0.0' => $open]);

		$this->assertTrue($validator->validateObject(['note' => 'n'], $this->schema())->isValid(), 'the cached preparation, which requires nothing, must be the one used');
	}//end testACachedPreparationIsReused()

	public function testACallersOwnSchemaObjectIsNotCached(): void {
		$validator = $this->validator();
		$own = json_decode('{"type":"object","required":["code"],"properties":{"code":{"type":"string"}}}');

		$this->assertFalse($validator->validateObject(['title' => 't'], $this->schema(), $own)->isValid());
		$this->assertSame([], $this->cache($validator));
	}//end testACallersOwnSchemaObjectIsNotCached()

	public function testAPropertyNotSuppliedIsExcusedAndTheCallIsNotCached(): void {
		$validator = $this->validator();

		$result = $validator->validateObject(['note' => 'n'], $this->schema(), new stdClass(), ['title' => 'not_known']);

		$this->assertTrue($result->isValid(), 'a required property recorded as not supplied is excused');
		$this->assertSame([], $this->cache($validator), 'an excused call must not leave its excusals in the cache');
	}//end testAPropertyNotSuppliedIsExcusedAndTheCallIsNotCached()
}//end class
