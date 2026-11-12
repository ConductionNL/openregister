<?php

/**
 * How an import resolves a reference to a register or schema: a known slug
 * or id from the map first, then an entity imported under that key, else the
 * reference unchanged.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Configuration\ImportReferenceMap;
use PHPUnit\Framework\TestCase;

/**
 * ImportReferenceMap.
 *
 * @covers \OCA\OpenRegister\Service\Configuration\ImportReferenceMap
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ImportReferenceMapTest extends TestCase {

	private function schema(int $id): Schema {
		$schema = new Schema();
		$schema->setId($id);

		return $schema;
	}//end schema()

	public function testAMappedReferenceResolvesToItsId(): void {
		$map = new ImportReferenceMap(['case' => 12, 'note' => 13]);

		$this->assertSame(12, $map->resolve('case', ['case' => $this->schema(99)]));
	}//end testAMappedReferenceResolvesToItsId()

	public function testAnImportedEntityResolvesToItsId(): void {
		$map = new ImportReferenceMap([]);

		$this->assertSame(99, $map->resolve('case', ['case' => $this->schema(99)]));
	}//end testAnImportedEntityResolvesToItsId()

	public function testANullMappingFallsThroughToTheEntity(): void {
		$map = new ImportReferenceMap(['case' => null]);

		$this->assertSame(99, $map->resolve('case', ['case' => $this->schema(99)]));
	}//end testANullMappingFallsThroughToTheEntity()

	public function testAnUnknownReferenceIsKeptAsGiven(): void {
		$map = new ImportReferenceMap(['case' => 12]);

		$this->assertSame('https://example.org/schemas/other', $map->resolve('https://example.org/schemas/other', []));
		$this->assertSame(7, $map->resolve(7, []));
	}//end testAnUnknownReferenceIsKeptAsGiven()
}//end class
