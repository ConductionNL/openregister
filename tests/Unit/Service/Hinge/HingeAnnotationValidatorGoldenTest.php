<?php

/**
 * Characterisation tests for HingeAnnotationValidator.
 *
 * Pins the exact findings, in order, for every branch of the lens, list and
 * geographic-inheritance checks, so a restructuring of the validator cannot
 * change a code, a message or the order they are reported in.
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

use OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator;
use PHPUnit\Framework\TestCase;

final class HingeAnnotationValidatorGoldenTest extends TestCase {

	public function testEveryFindingIsReportedInOrder(): void {
		$schema = [
			'properties' => ['zaak' => [], 'adres' => [], 'naam' => []],
			'x-openregister-lenses' => [
				'besluitdatum' => ['through' => 'zaak', 'property' => 'datum'],
				'kapot' => 'string',
				'leeg' => ['through' => '', 'property' => ''],
				'niets' => [],
				'vreemd' => ['through' => 'onbekend', 'property' => 7],
				'naam' => ['through' => 'zaak', 'property' => 'naam'],
				3 => ['through' => 'zaak'],
			],
			'x-openregister-list' => [
				'columns' => ['naam', ['property' => 'adres.straat'], ['label' => 'x'], '', 5, 'onbekend', ['property' => 'nope.deep'], 'naam.x'],
				'searchFields' => ['naam', '', ['naam'], 'onbekend', 'adres.huisnummer'],
			],
			'x-openregister-geo-inheritance' => [
				'from' => ['adres', ['through' => 'zaak'], ['label' => 'x'], '', 'onbekend', ['through' => 'adres.x'], 9],
			],
		];

		$this->assertSame(
			[
				['code' => 'lens-malformed', 'message' => 'Lens "kapot" must be an object declaring through and property.'],
				['code' => 'lens-missing-through', 'message' => 'Lens "leeg" does not name the reference property to look through.'],
				['code' => 'lens-missing-property', 'message' => 'Lens "leeg" does not name the property to read.'],
				['code' => 'lens-missing-through', 'message' => 'Lens "niets" does not name the reference property to look through.'],
				['code' => 'lens-missing-property', 'message' => 'Lens "niets" does not name the property to read.'],
				['code' => 'lens-unknown-through', 'message' => 'Lens "vreemd" looks through "onbekend", which this schema does not declare.'],
				['code' => 'lens-missing-property', 'message' => 'Lens "vreemd" does not name the property to read.'],
				['code' => 'lens-shadows-property', 'message' => 'Lens "naam" has the name of a stored property, which it would overwrite on every read.'],
				['code' => 'lens-missing-property', 'message' => 'Lens "3" does not name the property to read.'],
				['code' => 'list-column-unnamed', 'message' => 'A declared list column names no property.'],
				['code' => 'list-column-unnamed', 'message' => 'A declared list column names no property.'],
				['code' => 'list-column-unnamed', 'message' => 'A declared list column names no property.'],
				['code' => 'list-column-unknown', 'message' => 'List column "onbekend" is not a property of this schema.'],
				['code' => 'list-column-unknown', 'message' => 'List column "nope.deep" is not a property of this schema.'],
				['code' => 'list-search-field-unnamed', 'message' => 'A declared search field names no property.'],
				['code' => 'list-search-field-unnamed', 'message' => 'A declared search field names no property.'],
				['code' => 'list-search-field-unknown', 'message' => 'Search field "onbekend" is not a property of this schema.'],
				['code' => 'geo-inheritance-unnamed', 'message' => 'A geographic-inheritance source names no reference property.'],
				['code' => 'geo-inheritance-unnamed', 'message' => 'A geographic-inheritance source names no reference property.'],
				['code' => 'geo-inheritance-unknown', 'message' => 'Geographic inheritance reads through "onbekend", which this schema does not declare.'],
				['code' => 'geo-inheritance-unknown', 'message' => 'Geographic inheritance reads through "adres.x", which this schema does not declare.'],
				['code' => 'geo-inheritance-unnamed', 'message' => 'A geographic-inheritance source names no reference property.'],
			],
			(new HingeAnnotationValidator())->validate($schema)
		);
	}

	public function testNonArrayBlocksAreSilentOrEmpty(): void {
		$validator = new HingeAnnotationValidator();

		$this->assertSame(
			[],
			$validator->validate(
				[
					'properties' => 'not-an-array',
					'x-openregister-lenses' => 'nope',
					'x-openregister-list' => ['columns' => 'nope', 'searchFields' => 3],
				]
			)
		);
		$this->assertSame([], $validator->validate(['x-openregister-list' => 'nope', 'x-openregister-geo-inheritance' => 'nope']));
		$this->assertSame(
			[
				[
					'code' => 'geo-inheritance-empty',
					'message' => 'x-openregister-geo-inheritance must declare `from`: the reference properties to collect features through.',
				],
			],
			$validator->validate(['x-openregister-geo-inheritance' => ['from' => 'adres']])
		);
		$this->assertSame([], $validator->validate(['x-openregister-geo-inheritance' => ['from' => []]]));
	}

	public function testWithoutPropertiesEveryReferenceIsUnknown(): void {
		$this->assertSame(
			['lens-unknown-through', 'list-column-unknown', 'list-search-field-unknown', 'geo-inheritance-unknown'],
			array_column(
				(new HingeAnnotationValidator())->validate(
					[
						'x-openregister-lenses' => ['l' => ['through' => 'a', 'property' => 'b']],
						'x-openregister-list' => ['columns' => ['a'], 'searchFields' => ['a']],
						'x-openregister-geo-inheritance' => ['from' => ['a']],
					]
				),
				'code'
			)
		);
	}
}
