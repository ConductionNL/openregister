<?php

/**
 * Unit tests for HingeAnnotationValidator.
 *
 * The three hinge annotations all name properties on the schema they sit on. A
 * name that matches nothing renders as empty, which is what this validator
 * turns into a warning at save time.
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

use OCA\OpenRegister\Service\Hinge\HingeAnnotationValidator;
use PHPUnit\Framework\TestCase;

final class HingeAnnotationValidatorTest extends TestCase {

	private function codes(array $schema): array {
		return array_column((new HingeAnnotationValidator())->validate($schema), 'code');
	}

	public function testASchemaDeclaringNoneOfTheThreeIsSilent(): void {
		$this->assertSame([], $this->codes(['properties' => ['besluit' => ['type' => 'string']]]));
	}

	public function testAWellFormedDeclarationIsSilent(): void {
		$codes = $this->codes([
			'properties' => [
				'besluit' => ['type' => 'string'],
				'adres' => ['type' => 'string'],
				'onderwerp' => ['type' => 'string'],
			],
			'x-openregister-lenses' => [
				'besluitDatum' => ['through' => 'besluit', 'property' => 'datum'],
			],
			'x-openregister-list' => [
				'columns' => ['onderwerp', ['property' => 'besluit', 'label' => 'Besluit']],
				'searchFields' => ['onderwerp'],
			],
			'x-openregister-geo-inheritance' => [
				'from' => ['adres', ['through' => 'besluit', 'label' => 'Besluit']],
			],
		]);

		$this->assertSame([], $codes);
	}

	public function testALensThroughAPropertyThatDoesNotExistIsReported(): void {
		$codes = $this->codes([
			'properties' => ['onderwerp' => ['type' => 'string']],
			'x-openregister-lenses' => [
				'besluitDatum' => ['through' => 'besluit', 'property' => 'datum'],
			],
		]);

		$this->assertContains('lens-unknown-through', $codes);
	}

	public function testALensMissingItsHalvesIsReported(): void {
		$codes = $this->codes([
			'properties' => ['besluit' => ['type' => 'string']],
			'x-openregister-lenses' => [
				'a' => ['property' => 'datum'],
				'b' => ['through' => 'besluit'],
				'c' => 'not an object',
			],
		]);

		$this->assertContains('lens-missing-through', $codes);
		$this->assertContains('lens-missing-property', $codes);
		$this->assertContains('lens-malformed', $codes);
	}

	public function testALensNamedAfterAStoredPropertyIsReported(): void {
		$codes = $this->codes([
			'properties' => ['besluit' => ['type' => 'string'], 'datum' => ['type' => 'string']],
			'x-openregister-lenses' => [
				'datum' => ['through' => 'besluit', 'property' => 'datum'],
			],
		]);

		$this->assertContains('lens-shadows-property', $codes);
	}

	public function testAColumnOrSearchFieldNamingNothingIsReported(): void {
		$codes = $this->codes([
			'properties' => ['onderwerp' => ['type' => 'string']],
			'x-openregister-list' => [
				'columns' => ['status', ['label' => 'Geen property']],
				'searchFields' => ['kenmerk'],
			],
		]);

		$this->assertContains('list-column-unknown', $codes);
		$this->assertContains('list-column-unnamed', $codes);
		$this->assertContains('list-search-field-unknown', $codes);
	}

	public function testADotPathColumnResolvesAgainstItsRootProperty(): void {
		$codes = $this->codes([
			'properties' => ['contact' => ['type' => 'object']],
			'x-openregister-list' => ['columns' => ['contact.naam'], 'searchFields' => ['contact.email']],
		]);

		$this->assertSame([], $codes);
	}

	public function testGeographicInheritanceThroughAnUnknownPropertyIsReported(): void {
		$codes = $this->codes([
			'properties' => ['onderwerp' => ['type' => 'string']],
			'x-openregister-geo-inheritance' => ['from' => ['adres']],
		]);

		$this->assertContains('geo-inheritance-unknown', $codes);
	}

	public function testGeographicInheritanceWithoutSourcesIsReported(): void {
		$codes = $this->codes([
			'properties' => ['adres' => ['type' => 'string']],
			'x-openregister-geo-inheritance' => ['enabled' => true],
		]);

		$this->assertContains('geo-inheritance-empty', $codes);
	}
}
