<?php

/**
 * A property named like a schema keyword (`required`) still gets its column.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/magic-table-columns/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicSchemaColumns;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * planninq's `projectField` schema has a boolean property `required` (is the
 * field mandatory). The table was built without that column while the writer
 * still wrote it, so every save failed on PostgreSQL with
 * "column \"required\" ... does not exist".
 */
class MagicSchemaColumnsConfigurationNamedPropertyTest extends TestCase {
	private function plan(array $properties): array {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn($properties);
		$schema->method('getLinkedTypes')->willReturn([]);

		return (new MagicSchemaColumns(logger: $this->createMock(LoggerInterface::class)))->forSchema(schema: $schema);
	}//end plan()

	public function testAPropertyNamedRequiredGetsItsColumn(): void {
		$plan = $this->plan(['label' => ['type' => 'string'], 'required' => ['type' => 'boolean']]);

		$this->assertArrayHasKey('required', $plan);
		$this->assertSame('required', $plan['required']['name']);
	}//end testAPropertyNamedRequiredGetsItsColumn()

	public function testTheRequiredKeywordListIsStillNoColumn(): void {
		$plan = $this->plan(['label' => ['type' => 'string'], 'required' => ['label']]);

		$this->assertArrayNotHasKey('required', $plan);
		$this->assertArrayHasKey('label', $plan);
	}//end testTheRequiredKeywordListIsStillNoColumn()

	public function testConfigurationEntryTellsAKeywordFromADefinition(): void {
		$this->assertTrue(MagicSchemaColumns::isConfigurationEntry(name: 'required', config: ['label']));
		$this->assertTrue(MagicSchemaColumns::isConfigurationEntry(name: 'required', config: []));
		$this->assertTrue(MagicSchemaColumns::isConfigurationEntry(name: 'objectNameField', config: 'title'));
		$this->assertFalse(MagicSchemaColumns::isConfigurationEntry(name: 'required', config: ['type' => 'boolean']));
		$this->assertFalse(MagicSchemaColumns::isConfigurationEntry(name: 'label', config: ['type' => 'string']));
		$this->assertTrue(MagicSchemaColumns::isConfigurationEntry(name: '$schema', config: ['type' => 'string']));
	}//end testConfigurationEntryTellsAKeywordFromADefinition()
}//end class
