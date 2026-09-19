<?php

/**
 * Unit tests for the three hinge accessors on Schema.
 *
 * The annotations only work if setConfiguration() keeps them: a key outside
 * ANNOTATION_VOCABULARY is dropped in silence, and every capability behind it
 * is then inert while the schema saves with no error. These tests pin the
 * round trip as well as the reading.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

final class SchemaHingeAnnotationsTest extends TestCase {

	private function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		return $schema;
	}

	public function testTheThreeAnnotationsSurviveSetConfiguration(): void {
		$schema = $this->schema([
			Schema::LENS_ANNOTATION => ['besluitDatum' => ['through' => 'besluit', 'property' => 'datum']],
			Schema::LIST_ANNOTATION => ['columns' => ['onderwerp'], 'searchFields' => ['onderwerp']],
			Schema::GEO_INHERITANCE_ANNOTATION => ['from' => ['adres']],
		]);

		$configuration = ($schema->getConfiguration() ?? []);

		$this->assertArrayHasKey(Schema::LENS_ANNOTATION, $configuration);
		$this->assertArrayHasKey(Schema::LIST_ANNOTATION, $configuration);
		$this->assertArrayHasKey(Schema::GEO_INHERITANCE_ANNOTATION, $configuration);
		$this->assertSame([], $schema->consumeDroppedAnnotationKeys());
	}

	public function testALensIsReadWithItsTwoHalvesAndItsLabel(): void {
		$schema = $this->schema([
			Schema::LENS_ANNOTATION => [
				'besluitDatum' => ['through' => 'besluit', 'property' => 'datum', 'label' => 'Datum besluit'],
			],
		]);

		$this->assertSame(
			['besluitDatum' => ['through' => 'besluit', 'property' => 'datum', 'label' => 'Datum besluit']],
			$schema->getLenses()
		);
	}

	public function testAHalfDeclaredLensIsDroppedRatherThanHalfApplied(): void {
		$schema = $this->schema([
			Schema::LENS_ANNOTATION => [
				'a' => ['through' => 'besluit'],
				'b' => ['property' => 'datum'],
				'c' => 'not an object',
				'ok' => ['through' => 'besluit', 'property' => 'datum'],
			],
		]);

		$this->assertSame(['ok'], array_keys($schema->getLenses()));
	}

	public function testListColumnsAcceptABareStringOrAnObject(): void {
		$schema = $this->schema([
			Schema::LIST_ANNOTATION => [
				'columns' => ['onderwerp', ['property' => 'status', 'label' => 'Status'], ['label' => 'nameless'], ''],
				'searchFields' => ['onderwerp', 'onderwerp', ''],
			],
		]);

		$this->assertSame(
			[['property' => 'onderwerp'], ['property' => 'status', 'label' => 'Status']],
			$schema->getListPresentation()['columns']
		);
		$this->assertSame(['onderwerp'], $schema->getListPresentation()['searchFields']);
	}

	public function testASchemaDeclaringNoneOfThemReadsAsEmpty(): void {
		$schema = new Schema();

		$this->assertSame([], $schema->getLenses());
		$this->assertSame(['columns' => [], 'searchFields' => []], $schema->getListPresentation());
		$this->assertSame([], $schema->getGeoInheritance());
	}

	public function testGeographicInheritanceAcceptsABareStringOrAnObject(): void {
		$schema = $this->schema([
			Schema::GEO_INHERITANCE_ANNOTATION => [
				'from' => ['adres', ['through' => 'aanvrager', 'label' => 'Aanvrager'], ['label' => 'nameless'], ''],
			],
		]);

		$this->assertSame(
			[['through' => 'adres'], ['through' => 'aanvrager', 'label' => 'Aanvrager']],
			$schema->getGeoInheritance()
		);
	}
}
