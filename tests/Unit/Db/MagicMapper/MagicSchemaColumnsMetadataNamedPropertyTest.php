<?php

/**
 * A property named like a metadata column leaves that column in the table plan.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicMetadataColumns;
use OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns
 * @uses   \OCA\OpenRegister\Db\MagicMapper\MagicColumnNameClaims
 */
class MagicSchemaColumnsMetadataNamedPropertyTest extends TestCase {
	private function plan(array $properties, array $linkedTypes = []): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getLinkedTypes')->willReturn($linkedTypes);

		return (new MagicSchemaColumns(logger: $this->createMock(LoggerInterface::class)))->forSchema(schema: $schema);
	}//end plan()

	public function testEveryMetadataColumnSurvivesAPropertyOfTheSameName(): void {
		$properties = array_fill_keys(array_keys(MagicMetadataColumns::ALL), ['type' => 'string']);

		$plan = $this->plan($properties);

		foreach (MagicMetadataColumns::ALL as $name => $definition) {
			$this->assertSame($definition, $plan[$name], $name);
		}

		$this->assertCount(count(MagicMetadataColumns::ALL), $plan);
	}//end testEveryMetadataColumnSurvivesAPropertyOfTheSameName()

	public function testALinkedTypeColumnSurvivesAPropertyOfTheSameName(): void {
		$plan = $this->plan(['_mail' => ['type' => 'string']], ['mail']);

		$this->assertSame(['name' => '_mail', 'type' => 'json', 'nullable' => true, 'index' => true], $plan['_mail']);
	}//end testALinkedTypeColumnSurvivesAPropertyOfTheSameName()

	public function testAnUnderscorePropertyWithoutAMetadataColumnKeepsItsOwnColumn(): void {
		$plan = $this->plan(['_meta' => ['type' => 'object']]);

		$this->assertSame('_meta', $plan['_meta']['name']);
		$this->assertSame('json_ordered', $plan['_meta']['type']);
	}//end testAnUnderscorePropertyWithoutAMetadataColumnKeepsItsOwnColumn()
}//end class
