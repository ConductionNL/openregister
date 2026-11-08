<?php

/**
 * Characterisation tests for ListPresentationResolver column description.
 *
 * Pins the label and type fallbacks of a declared column for every input
 * shape, so restructuring the description cannot change a column header.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Hinge
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Hinge;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Hinge\ListPresentationResolver;
use PHPUnit\Framework\TestCase;

final class ListPresentationResolverGoldenTest extends TestCase {

	public function testEveryLabelAndTypeFallbackIsPinned(): void {
		$schema = $this->createMock(Schema::class);
		$schema->method('getProperties')->willReturn(
			[
				'a' => ['title' => 'Titel A', 'type' => 'integer'],
				'b' => ['title' => '', 'type' => ['string', 'null']],
				'c' => 'not-an-array',
				'd' => ['title' => 5],
			]
		);
		$schema->method('getListPresentation')->willReturn(
			[
				'columns' => [
					['property' => 'a', 'label' => 'Eigen'],
					['property' => 'a', 'label' => ''],
					['property' => 'a.sub', 'label' => 7],
					['property' => 'b'],
					['property' => 'c', 'label' => null],
					['property' => 'd'],
					['property' => 'zz'],
				],
				'searchFields' => [],
			]
		);

		$this->assertSame(
			[
				'declared' => true,
				'columns' => [
					['property' => 'a', 'label' => 'Eigen', 'type' => 'integer', 'source' => 'property'],
					['property' => 'a', 'label' => 'Titel A', 'type' => 'integer', 'source' => 'property'],
					['property' => 'a.sub', 'label' => 'Titel A', 'type' => 'integer', 'source' => 'property'],
					['property' => 'b', 'label' => 'b', 'type' => 'string', 'source' => 'property'],
					['property' => 'c', 'label' => 'c', 'type' => 'string', 'source' => 'property'],
					['property' => 'd', 'label' => 'd', 'type' => 'string', 'source' => 'property'],
					['property' => 'zz', 'label' => 'zz', 'type' => 'string', 'source' => 'property'],
				],
				'searchFields' => [],
			],
			(new ListPresentationResolver())->resolve($schema)
		);
	}
}
