<?php

/**
 * EntityRelation: an image region's page and box.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Db\EntityRelation;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\EntityRelation
 * @uses   \OCA\OpenRegister\Db\EntityRelation\EntityRelationFieldTypes
 */
final class EntityRelationBoxTest extends TestCase {

	public function testPageAndBoxAreNullForATextFinding(): void {
		$relation = new EntityRelation();

		$this->assertNull($relation->getPage());
		$this->assertNull($relation->getBox());
		$this->assertNull($relation->jsonSerialize()['page']);
		$this->assertNull($relation->jsonSerialize()['box']);
	}//end testPageAndBoxAreNullForATextFinding()

	public function testPageAndBoxAreTypedAndMarkedForTheUpdate(): void {
		$relation = new EntityRelation();
		$relation->setPage(2);
		$relation->setBox(['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.4]);

		$this->assertSame(['page' => 'integer', 'box' => 'json'], array_intersect_key($relation->getFieldTypes(), ['page' => 1, 'box' => 1]));
		$this->assertArrayHasKey('page', $relation->getUpdatedFields());
		$this->assertArrayHasKey('box', $relation->getUpdatedFields());
		$this->assertSame(2, $relation->jsonSerialize()['page']);
		$this->assertSame(['x' => 0.1, 'y' => 0.2, 'w' => 0.3, 'h' => 0.4], $relation->jsonSerialize()['box']);
	}//end testPageAndBoxAreTypedAndMarkedForTheUpdate()
}//end class
