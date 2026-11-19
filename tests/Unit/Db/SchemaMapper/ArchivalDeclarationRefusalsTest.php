<?php

/**
 * ArchivalDeclarationRefusalsTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\SchemaMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\SchemaMapper;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\ArchivalDeclarationRefusals;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The MDTO mapping and archival annotation refuse a schema save as they did inside RecordAnnotationRefusals.
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\ArchivalDeclarationRefusals
 */
class ArchivalDeclarationRefusalsTest extends TestCase {

	/**
	 * A schema `dossiers` with one string property and this configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('dossiers');
		$schema->setProperties(['titel' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	public function testASchemaWithoutEitherAnnotationPasses(): void {
		$refusals = new ArchivalDeclarationRefusals(logger: new NullLogger());
		$refusals->checkMdtoMapping(schema: self::schema([]));
		$refusals->checkArchival(schema: self::schema([]));

		$this->addToAssertionCount(1);
	}//end testASchemaWithoutEitherAnnotationPasses()

	public function testAMappingOfAnElementMdtoDoesNotHaveRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Invalid x-openregister-mdto-mapping declaration');

		(new ArchivalDeclarationRefusals(logger: new NullLogger()))->checkMdtoMapping(
			schema: self::schema(['x-openregister-mdto-mapping' => ['nonsense' => ['property' => 'titel']]])
		);
	}//end testAMappingOfAnElementMdtoDoesNotHaveRefusesTheSave()

	public function testAMappingWrittenAsAScalarIsNotChecked(): void {
		(new ArchivalDeclarationRefusals(logger: new NullLogger()))->checkMdtoMapping(
			schema: self::schema(['x-openregister-mdto-mapping' => 'nonsense'])
		);

		$this->addToAssertionCount(1);
	}//end testAMappingWrittenAsAScalarIsNotChecked()

	public function testAnArchivalBlockWithoutRetentionRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-archival: ');

		(new ArchivalDeclarationRefusals(logger: new NullLogger()))->checkArchival(
			schema: self::schema(['x-openregister-archival' => ['foo' => 1]])
		);
	}//end testAnArchivalBlockWithoutRetentionRefusesTheSave()

	public function testAnUnknownArchivalKeyIsLoggedAndIgnored(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('unknown x-openregister-archival key'));

		(new ArchivalDeclarationRefusals(logger: $logger))->checkArchival(
			schema: self::schema(['x-openregister-archival' => ['retention' => ['default' => 'P30D'], 'foo' => 1]])
		);
	}//end testAnUnknownArchivalKeyIsLoggedAndIgnored()
}//end class
