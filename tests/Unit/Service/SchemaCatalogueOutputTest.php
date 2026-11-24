<?php

/**
 * The classification and catalogue block in the OpenAPI output, the JSON-LD context and the import comparison.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/modelling-type-catalogue-metadata/specs/schema-catalogue-metadata/spec.md#requirement-catalogue-metadata-travels-with-the-register
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison;
use OCA\OpenRegister\Service\JsonLd\JsonLdContextService;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\OasSources;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The output carries both fields (OpenAPI without the contact), the context maps maintainer and frequency, and import compares them.
 *
 * @covers \OCA\OpenRegister\Service\OasService
 * @covers \OCA\OpenRegister\Service\JsonLd\JsonLdContextService
 * @covers \OCA\OpenRegister\Service\Configuration\ImportedSchemaComparison
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\PropertyAuthorization
 * @uses   \OCA\OpenRegister\Service\JsonLd\JsonLdContextSource
 * @uses   \OCA\OpenRegister\Service\JsonLd\JsonLdRouteSlug
 * @uses   \OCA\OpenRegister\Service\OasSources
 * @uses   \OCA\OpenRegister\Service\Oas\OasExtendedTypes
 * @uses   \OCA\OpenRegister\Service\Oas\OasIssue
 * @uses   \OCA\OpenRegister\Service\Oas\OasNames
 * @uses   \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses   \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 * @uses   \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses   \OCA\OpenRegister\Service\Oas\OasValidationReport
 * @uses   \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 * @uses   \OCA\OpenRegister\Service\Rbac\EffectiveAuthorization
 * @uses   \OCA\OpenRegister\Service\Schemas\SchemaCatalogue
 * @uses   \OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator
 */
final class SchemaCatalogueOutputTest extends TestCase {

	private const CATALOGUE = [
		'maintainer'      => ['organisation' => 'Gemeente Voorbeeld'],
		'contact'         => ['name' => 'Team data', 'email' => 'data@voorbeeld.nl'],
		'sourceSystem'    => 'Meldingen app',
		'updateFrequency' => 'daily',
	];

	private function describedSchema(): Schema {
		$schema = new Schema();
		$schema->setTitle('Meldingen');
		$schema->setSlug('meldingen');
		$schema->setProperties(['naam' => ['type' => 'string']]);
		$schema->setClassification('internal');
		$schema->setCatalogue(self::CATALOGUE);
		$ref = new \ReflectionProperty($schema, 'id');
		$ref->setAccessible(true);
		$ref->setValue($schema, 10);

		return $schema;
	}

	public function testTheOpenApiComponentCarriesTheFieldsWithoutTheContact(): void {
		$register = new Register();
		$register->setTitle('Meldingen register');
		$register->setVersion('1.0');
		$registerRef = new \ReflectionProperty($register, 'id');
		$registerRef->setAccessible(true);
		$registerRef->setValue($register, 1);
		$schemasProp = new \ReflectionProperty($register, 'schemas');
		$schemasProp->setAccessible(true);
		$schemasProp->setValue($register, [10]);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultiple')->willReturn([$this->describedSchema()]);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('http://localhost/api');

		$oas = (new OasService(new OasSources($registerMapper, $schemaMapper, $url)))->createOas('1');

		$component = $oas['components']['schemas']['Meldingen'];
		$this->assertSame('internal', $component['x-openregister-classification']);
		$this->assertSame('Meldingen app', $component['x-openregister-catalogue']['sourceSystem']);
		$this->assertArrayNotHasKey('contact', $component['x-openregister-catalogue'], 'the OpenAPI is public: no contact');
	}

	public function testASchemaWithoutTheFieldsHasNoExtensions(): void {
		$register = new Register();
		$register->setTitle('R');
		$register->setVersion('1.0');
		$registerRef = new \ReflectionProperty($register, 'id');
		$registerRef->setAccessible(true);
		$registerRef->setValue($register, 1);
		$schemasProp = new \ReflectionProperty($register, 'schemas');
		$schemasProp->setAccessible(true);
		$schemasProp->setValue($register, [11]);
		$plain = new Schema();
		$plain->setTitle('Plain');
		$plain->setProperties(['a' => ['type' => 'string']]);
		$plainRef = new \ReflectionProperty($plain, 'id');
		$plainRef->setAccessible(true);
		$plainRef->setValue($plain, 11);

		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('findAll')->willReturn([$register]);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultiple')->willReturn([$plain]);
		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('http://localhost/api');

		$component = (new OasService(new OasSources($registerMapper, $schemaMapper, $url)))->createOas('1')['components']['schemas']['Plain'];

		$this->assertArrayNotHasKey('x-openregister-classification', $component);
		$this->assertArrayNotHasKey('x-openregister-catalogue', $component);
	}

	public function testTheJsonLdContextMapsMaintainerAndFrequency(): void {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('http://localhost/context');
		$register = new Register();
		$register->setSlug('meldingen');
		$register->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));

		$context = (new JsonLdContextService($url))->buildSchemaContext($register, $this->describedSchema());

		$this->assertSame('dcat:contactPoint', $context['maintainer']);
		$this->assertSame('dct:accrualPeriodicity', $context['updateFrequency']);
		$this->assertSame('http://www.w3.org/ns/dcat#', $context['dcat']);
	}

	public function testAPropertyNamedMaintainerKeepsItsOwnTerm(): void {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('http://localhost/context');
		$schema = $this->describedSchema();
		$schema->setProperties(['maintainer' => ['type' => 'string']]);
		$register = new Register();
		$register->setSlug('meldingen');
		$register->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));

		$context = (new JsonLdContextService($url))->buildSchemaContext($register, $schema);

		$this->assertNotSame('dcat:contactPoint', $context['maintainer'], 'the property term wins');
	}

	public function testASchemaWithoutACatalogueGetsNoCatalogueTerms(): void {
		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('http://localhost/context');
		$plain = new Schema();
		$plain->setSlug('plain');
		$plain->setProperties(['a' => ['type' => 'string']]);
		$plain->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));
		$register = new Register();
		$register->setSlug('reg');
		$register->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));

		$context = (new JsonLdContextService($url))->buildSchemaContext($register, $plain);

		$this->assertArrayNotHasKey('dcat', $context);
		$this->assertArrayNotHasKey('updateFrequency', $context);
	}

	public function testImportComparesTheCarriedFieldsOnly(): void {
		$stored = new Schema();
		$stored->setProperties(['a' => ['type' => 'string']]);
		$stored->setClassification('internal');
		$stored->setCatalogue(self::CATALOGUE);
		$comparison = new ImportedSchemaComparison();
		$same = ['properties' => ['a' => ['type' => 'string']]];

		$this->assertFalse($comparison->differs($same + ['classification' => 'internal', 'catalogue' => self::CATALOGUE], $stored));
		$this->assertFalse($comparison->differs($same, $stored), 'a file carrying neither field is not a change');
		$this->assertTrue($comparison->differs($same + ['classification' => 'open'], $stored));
		$this->assertTrue($comparison->differs($same + ['catalogue' => ['sourceSystem' => 'Other']], $stored));
	}
}
