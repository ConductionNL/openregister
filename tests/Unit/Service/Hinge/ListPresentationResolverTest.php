<?php

/**
 * Unit tests for ListPresentationResolver.
 *
 * Covers a schema declaring its columns and search fields, a schema declaring
 * neither keeping today's defaults, and the label a column falls back to.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Hinge\ListPresentationResolver;
use PHPUnit\Framework\TestCase;

final class ListPresentationResolverTest extends TestCase {

	private function schema(array $properties, ?array $configuration = null): Schema {
		$schema = new Schema();
		$schema->setProperties($properties);
		if ($configuration !== null) {
			$schema->setConfiguration($configuration);
		}

		return $schema;
	}

	public function testAnObjectTypeIsAsUsableAsACaseList(): void {
		$schema = $this->schema(
			[
				'onderwerp' => ['type' => 'string', 'title' => 'Onderwerp'],
				'status' => ['type' => 'string'],
				'aanvrager' => ['type' => 'string', 'title' => 'Aanvrager'],
				'datum' => ['type' => 'string'],
			],
			[
				Schema::LIST_ANNOTATION => [
					'columns' => [
						'onderwerp',
						['property' => 'status', 'label' => 'Stand van zaken'],
						'aanvrager',
						'datum',
					],
					'searchFields' => ['onderwerp', 'aanvrager'],
				],
			]
		);

		$resolved = (new ListPresentationResolver())->resolve($schema);

		$this->assertTrue($resolved['declared']);
		$this->assertCount(4, $resolved['columns']);
		$this->assertSame(['onderwerp', 'aanvrager'], $resolved['searchFields']);

		// The declared label wins; otherwise the property's own title; otherwise
		// the property name, so a column is never headless.
		$labels = array_column($resolved['columns'], 'label');
		$this->assertSame(['Onderwerp', 'Stand van zaken', 'Aanvrager', 'datum'], $labels);
	}

	public function testASchemaDeclaringNoneKeepsTodaysColumns(): void {
		$resolved = (new ListPresentationResolver())->resolve(
			$this->schema(['onderwerp' => ['type' => 'string']])
		);

		$this->assertFalse($resolved['declared']);
		$this->assertSame(ListPresentationResolver::DEFAULT_COLUMNS, $resolved['columns']);
		$this->assertSame([], $resolved['searchFields']);
	}

	public function testDeclaringOnlySearchFieldsStillCountsAsDeclared(): void {
		$resolved = (new ListPresentationResolver())->resolve(
			$this->schema(
				['onderwerp' => ['type' => 'string']],
				[Schema::LIST_ANNOTATION => ['searchFields' => ['onderwerp']]]
			)
		);

		$this->assertTrue($resolved['declared']);
		$this->assertSame(ListPresentationResolver::DEFAULT_COLUMNS, $resolved['columns']);
		$this->assertSame(['onderwerp'], $resolved['searchFields']);
	}

	public function testAColumnCarriesThePropertysDeclaredType(): void {
		$resolved = (new ListPresentationResolver())->resolve(
			$this->schema(
				['bedrag' => ['type' => 'number', 'title' => 'Bedrag']],
				[Schema::LIST_ANNOTATION => ['columns' => ['bedrag']]]
			)
		);

		$this->assertSame('number', $resolved['columns'][0]['type']);
		$this->assertSame('property', $resolved['columns'][0]['source']);
	}
}
