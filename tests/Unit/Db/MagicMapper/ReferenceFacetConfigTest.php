<?php

/**
 * ReferenceFacetConfigTest: reading `_facets[_ref]` and refusing what it cannot count.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/query-filter-through-a-reference/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper\ReferenceFacetConfig;
use OCA\OpenRegister\Exception\RelatedRowFilterNotSupportedException;
use PHPUnit\Framework\TestCase;

/**
 * The three spellings of a facet through a reference read the same; a
 * malformed block or a type other than terms is refused naming the part.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\ReferenceFacetConfig
 */
class ReferenceFacetConfigTest extends TestCase {

	/**
	 * An object with a type, a bare type and a list of names each ask for a terms facet per field.
	 *
	 * @return void
	 */
	public function testEverySpellingReadsAsATermsFacetPerField(): void {
		$entries = (new ReferenceFacetConfig())->entries(
			config: [
				'aanvrager' => ['woonplaats' => ['type' => 'terms', 'title' => 'Woonplaats'], 'naam' => 'terms'],
				'cursus' => ['periode'],
			]
		);

		$this->assertSame(
			[
				['property' => 'aanvrager', 'field' => 'woonplaats', 'config' => ['type' => 'terms', 'title' => 'Woonplaats']],
				['property' => 'aanvrager', 'field' => 'naam', 'config' => ['type' => 'terms']],
				['property' => 'cursus', 'field' => 'periode', 'config' => ['type' => 'terms']],
			],
			$entries
		);
	}//end testEverySpellingReadsAsATermsFacetPerField()

	/**
	 * An empty value means a terms facet, the default type.
	 *
	 * @return void
	 */
	public function testAnEmptyConfigurationIsATermsFacet(): void {
		$this->assertSame(
			[['property' => 'aanvrager', 'field' => 'woonplaats', 'config' => ['type' => 'terms']]],
			(new ReferenceFacetConfig())->entries(config: ['aanvrager' => ['woonplaats' => '']])
		);
	}//end testAnEmptyConfigurationIsATermsFacet()

	/**
	 * Malformed blocks and other facet types are refused naming the part, never dropped.
	 *
	 * @return void
	 */
	public function testAMalformedBlockOrAnotherTypeIsRefused(): void {
		foreach (
			[
				['x', '_facets[_ref] must be an object of reference-property blocks, and it is not.'],
				[['woonplaats'], '_facets[_ref] must be an object of reference-property blocks, and it is not.'],
				[['aanvrager' => 'woonplaats'], '_facets[_ref][aanvrager] must name at least one field of the referenced schema.'],
				[['aanvrager' => []], '_facets[_ref][aanvrager] must name at least one field of the referenced schema.'],
				[['aanvrager' => [' ']], '_facets[_ref][aanvrager] names a field that is not a name.'],
				[['aanvrager' => ['woonplaats' => 3]], '_facets[_ref][aanvrager][woonplaats] must be a facet type or an object with one.'],
				[
					['aanvrager' => ['geboortedatum' => ['type' => 'date_histogram']]],
					"_facets[_ref][aanvrager][geboortedatum]: a facet through a reference counts terms; 'date_histogram' is not supported.",
				],
			] as [$config, $message]
		) {
			try {
				(new ReferenceFacetConfig())->entries(config: $config);
				$this->fail('Accepted ' . json_encode($config));
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testAMalformedBlockOrAnotherTypeIsRefused()

	/**
	 * Across several schemas a `_ref` facet is refused; without one nothing happens.
	 *
	 * @return void
	 */
	public function testAReferenceFacetAcrossSeveralSchemasIsRefused(): void {
		ReferenceFacetConfig::refuseAcrossSchemas(facetConfig: ['status' => ['type' => 'terms']]);
		ReferenceFacetConfig::refuseAcrossSchemas(facetConfig: 'extend');
		ReferenceFacetConfig::refuseAcrossSchemas(facetConfig: null);

		$this->expectException(RelatedRowFilterNotSupportedException::class);
		$this->expectExceptionMessage("'_facets[_ref]' is not supported on a facet request across several schemas; narrow it to one schema.");
		ReferenceFacetConfig::refuseAcrossSchemas(facetConfig: ['_ref' => ['aanvrager' => ['woonplaats']]]);
	}//end testAReferenceFacetAcrossSeveralSchemasIsRefused()
}//end class
