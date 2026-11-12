<?php

/**
 * A property that declares an inverse relation but no `type` is analysed
 * as a single relation, without reading a missing key.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObjects\BulkSchemaAnalysis;
use PHPUnit\Framework\TestCase;

/**
 * Untyped-relation test for {@see BulkSchemaAnalysis}.
 */
class BulkSchemaAnalysisUntypedRelationTest extends TestCase {

	/**
	 * An untyped property with `inversedBy` is a single (non-array) relation.
	 *
	 * @return void
	 */
	public function testAnUntypedInverseRelationIsASingleRelation(): void {
		$schema = new Schema();
		$schema->setProperties(['owner' => ['inversedBy' => 'cases', '$ref' => '#/components/schemas/Person']]);

		$inverse = (new BulkSchemaAnalysis())->analyse(schema: $schema)['inverseProperties'];

		$this->assertSame(['owner' => ['inversedBy' => 'cases', 'writeBack' => false, 'isArray' => false]], $inverse);
	}//end testAnUntypedInverseRelationIsASingleRelation()
}//end class
