<?php

/**
 * PropertyFacetBlock: the facet block regenerated from facetable properties.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\Schema;

use OCA\OpenRegister\Db\Schema\FacetBlockStamp;
use OCA\OpenRegister\Db\Schema\PropertyFacetBlock;
use PHPUnit\Framework\TestCase;

/**
 * Pins the block's shape: null without properties, the version default and the stamp.
 *
 * @covers \OCA\OpenRegister\Db\Schema\PropertyFacetBlock
 */
class PropertyFacetBlockTest extends TestCase {

	/**
	 * A schema without properties has no block.
	 *
	 * @return void
	 */
	public function testNoPropertiesHasNoBlock(): void {
		$this->assertNull((new PropertyFacetBlock())->builtFor([], new FacetBlockStamp('2.0', 1700000000)));
	}//end testNoPropertiesHasNoBlock()

	/**
	 * Only facetable properties are listed; the stamp and the version default are carried.
	 *
	 * @return void
	 */
	public function testTheBlockListsFacetablePropertiesAndCarriesTheStamp(): void {
		$properties = [
			'status' => ['type' => 'string', 'facetable' => true, 'enum' => ['open', 'closed']],
			'name' => ['type' => 'string'],
		];

		$block = (new PropertyFacetBlock())->builtFor($properties, new FacetBlockStamp(null, 1700000000));

		$this->assertSame(1700000000, $block['generated_at']);
		$this->assertSame('1.0', $block['schema_version']);
		$this->assertSame(['status'], array_keys($block['object_fields']));
		$this->assertSame(['open', 'closed'], $block['object_fields']['status']['predefined_values']);
		$this->assertSame('2.1', (new PropertyFacetBlock())->builtFor($properties, new FacetBlockStamp('2.1', 1))['schema_version']);
	}//end testTheBlockListsFacetablePropertiesAndCarriesTheStamp()
}//end class
