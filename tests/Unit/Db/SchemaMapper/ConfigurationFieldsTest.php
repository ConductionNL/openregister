<?php

/**
 * OpenRegister ConfigurationFieldsTest
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\ConfigurationFields;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The name and description fields of a schema's configuration: filled from
 * the properties when empty, and refused when they name nothing.
 */
#[CoversClass(ConfigurationFields::class)]
#[UsesClass(Schema::class)]
final class ConfigurationFieldsTest extends TestCase {
	/**
	 * A schema with these properties and configuration.
	 *
	 * @param list<string>        $properties    Property names.
	 * @param array<string,mixed> $configuration The configuration.
	 *
	 * @return Schema
	 */
	private static function schema(array $properties, array $configuration = []): Schema {
		$schema = new Schema();
		$schema->setProperties(array_fill_keys($properties, ['type' => 'string']));
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	public function testPopulateTakesTheFirstCandidateInOrder(): void {
		$schema = self::schema(properties: ['titel', 'naam', 'omschrijving', 'summary']);
		(new ConfigurationFields(schema: $schema))->populate();

		self::assertSame('naam', $schema->getConfiguration()['objectNameField']);
		self::assertSame('omschrijving', $schema->getConfiguration()['objectDescriptionField']);
	}//end testPopulateTakesTheFirstCandidateInOrder()

	public function testPopulateKeepsAConfiguredFieldAndLeavesOutWhatHasNoCandidate(): void {
		$schema = self::schema(properties: ['name', 'code'], configuration: ['objectNameField' => 'code']);
		(new ConfigurationFields(schema: $schema))->populate();

		self::assertSame(['objectNameField' => 'code'], $schema->getConfiguration());
	}//end testPopulateKeepsAConfiguredFieldAndLeavesOutWhatHasNoCandidate()

	public function testATemplateNamingOnlyRealPropertiesIsAccepted(): void {
		$schema = self::schema(properties: ['first', 'last'], configuration: ['objectNameField' => '{{ first }} {{last}}']);
		(new ConfigurationFields(schema: $schema))->validate();

		$this->addToAssertionCount(1);
	}//end testATemplateNamingOnlyRealPropertiesIsAccepted()

	public function testATemplateNamingAMissingPropertyIsRefused(): void {
		$schema = self::schema(properties: ['first'], configuration: ['objectDescriptionField' => '{{ first }} {{ middle }}']);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("The template property 'middle' in objectDescriptionField does not exist.");
		(new ConfigurationFields(schema: $schema))->validate();
	}//end testATemplateNamingAMissingPropertyIsRefused()

	public function testFallbacksNeedOneRealPropertyAndAreRefusedWithNone(): void {
		(new ConfigurationFields(schema: self::schema(properties: ['code'], configuration: ['objectNameField' => 'name | code'])))->validate();

		$this->expectException(Exception::class);
		$this->expectExceptionMessage("None of the fallback fields in objectNameField ('name | label') exist as properties in the schema.");
		(new ConfigurationFields(schema: self::schema(properties: ['code'], configuration: ['objectNameField' => 'name | label'])))->validate();
	}//end testFallbacksNeedOneRealPropertyAndAreRefusedWithNone()
}//end class
