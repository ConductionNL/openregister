<?php

/**
 * A schema slug an import names resolves inside the register being imported.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Configuration\ImportHandler
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaRefInRegister
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
final class ImportHandlerSchemaSlugScopeTest extends TestCase {

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var ImportHandler The handler, built without its collaborators. */
	private ImportHandler $handler;

	/** @var ReflectionClass The handler's class, to reach its private lookups. */
	private ReflectionClass $class;

	protected function setUp(): void {
		parent::setUp();
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->class = new ReflectionClass(ImportHandler::class);
		$this->handler = $this->class->newInstanceWithoutConstructor();
		$this->class->getProperty('schemaMapper')->setValue($this->handler, $this->schemas);
		$this->class->getProperty('schemasMap')->setValue($this->handler, []);
	}

	private static function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);
		$schema->setSlug('agent');

		return $schema;
	}

	private static function register(): Register {
		$register = new Register();
		$register->setSchemas([36]);

		return $register;
	}

	public function testASeedReferenceSlugResolvesInsideTheSeedRegister(): void {
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('agent', [36])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');

		$found = $this->class->getMethod('seedReferenceSchema')->invoke($this->handler, 'agent', self::register());

		$this->assertSame(36, $found?->getId());
	}

	public function testASeedSchemaSlugResolvesInsideTheSeedRegisterAndIsRemembered(): void {
		$this->schemas->expects($this->once())->method('findBySlugInIds')->with('agent', [36])->willReturn(self::schema(36));
		$this->schemas->expects($this->never())->method('find');

		$found = $this->class->getMethod('lookUpSeedSchema')->invoke($this->handler, 'agent', self::register());

		$this->assertSame(36, $found?->getId());
		$this->assertSame($found, $this->class->getProperty('schemasMap')->getValue($this->handler)['agent']);
	}

	public function testASlugTheRegisterLacksStillResolvesGloballyOutsideRbacAndTenancy(): void {
		$this->schemas->method('findBySlugInIds')->willReturn(null);
		$this->schemas->expects($this->once())->method('find')->with('agent', [], false, false)->willReturn(self::schema(22));

		$found = $this->class->getMethod('seedReferenceSchema')->invoke($this->handler, 'agent', self::register());

		$this->assertSame(22, $found?->getId());
	}
}
