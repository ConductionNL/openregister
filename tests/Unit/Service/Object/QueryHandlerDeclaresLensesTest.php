<?php

/**
 * A list whose schema declares a lens takes the render path, so its rows
 * carry the lens values a single read shows (a case's Objects tab reads the
 * linked object's name and status through `objectUrl`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\QueryHandler;
use PHPUnit\Framework\TestCase;

/**
 * Which schemas send list rows through rendering for their lenses.
 */
class QueryHandlerDeclaresLensesTest extends TestCase {

	/**
	 * A schema entity or a serialised schema with a lens is found; one
	 * without is not.
	 *
	 * @return void
	 */
	public function testASchemaWithALensIsFound(): void {
		$lensed = new Schema();
		$lensed->setConfiguration(['x-openregister-lenses' => ['objectStatus' => ['through' => 'objectUrl', 'property' => 'status']]]);
		$plain = new Schema();
		$plain->setConfiguration([]);

		$this->assertTrue(condition: QueryHandler::declaresLenses(schemas: [$plain, $lensed]));
		$this->assertFalse(condition: QueryHandler::declaresLenses(schemas: [$plain]));
		$this->assertTrue(
			condition: QueryHandler::declaresLenses(
				schemas: [['configuration' => ['x-openregister-lenses' => ['objectTitle' => ['through' => 'objectUrl', 'property' => 'name']]]]]
			)
		);
		$this->assertFalse(condition: QueryHandler::declaresLenses(schemas: [['configuration' => []], ['properties' => []]]));
	}//end testASchemaWithALensIsFound()
}//end class
