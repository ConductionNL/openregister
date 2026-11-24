<?php

/**
 * McpDeclarationCheckTest
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
use OCA\OpenRegister\Db\SchemaMapper\McpDeclarationCheck;
use PHPUnit\Framework\TestCase;

/**
 * Schema save refuses a malformed `x-openregister-mcp` block with the validator's codes (REQ-DIALECT-002).
 *
 * @covers \OCA\OpenRegister\Db\SchemaMapper\McpDeclarationCheck
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidators
 * @uses   \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator
 */
class McpDeclarationCheckTest extends TestCase {

	/**
	 * A schema with one string property and this configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	private static function refusal(Schema $schema): ?string {
		try {
			(new McpDeclarationCheck())->check($schema);
		} catch (Exception $e) {
			return $e->getMessage();
		}

		return null;
	}//end refusal()

	public function testASchemaWithoutTheBlockPasses(): void {
		$this->assertNull(self::refusal(self::schema([])));
	}//end testASchemaWithoutTheBlockPasses()

	public function testAScalarBlockIsLeftAlone(): void {
		$this->assertNull(self::refusal(self::schema(['x-openregister-mcp' => 'yes'])));
	}//end testAScalarBlockIsLeftAlone()

	public function testADisabledBlockPasses(): void {
		$this->assertNull(self::refusal(self::schema(['x-openregister-mcp' => ['enabled' => false]])));
	}//end testADisabledBlockPasses()

	public function testAMissingEnabledIsRefusedWithItsCode(): void {
		$this->assertStringStartsWith(
			'x-openregister-mcp: mcp-missing-enabled',
			(string) self::refusal(self::schema(['x-openregister-mcp' => []]))
		);
	}//end testAMissingEnabledIsRefusedWithItsCode()

	public function testANonBooleanEnabledIsRefusedWithItsCode(): void {
		$this->assertStringStartsWith(
			'x-openregister-mcp: mcp-bad-enabled',
			(string) self::refusal(self::schema(['x-openregister-mcp' => ['enabled' => 'yes']]))
		);
	}//end testANonBooleanEnabledIsRefusedWithItsCode()
}//end class
