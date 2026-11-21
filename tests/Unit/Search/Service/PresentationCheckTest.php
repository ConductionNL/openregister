<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Search\Service\PresentationCheck}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service
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

namespace OCA\OpenRegister\Tests\Unit\Search\Service;

use OCA\OpenRegister\Search\Service\PresentationCheck;
use PHPUnit\Framework\TestCase;

/**
 * Reading declared presentation values by "section.key" path.
 *
 * @covers \OCA\OpenRegister\Search\Service\PresentationCheck
 */
class PresentationCheckTest extends TestCase {

	/**
	 * A declared value is found by its two-part path; anything missing is null.
	 *
	 * @return void
	 */
	public function testDeclared(): void {
		$check = new PresentationCheck(
			['table' => ['columns' => ['a', 'b'], 'pageSize' => 25], 'kanban' => ['groupByField' => 'status.code']],
			[]
		);

		$this->assertSame(['a', 'b'], $check->declared('table.columns'));
		$this->assertSame(25, $check->declared('table.pageSize'));
		$this->assertNull($check->declared('table.sort'));
		$this->assertNull($check->declared('map.center'));

	}//end testDeclared()

	/**
	 * Only the first dot splits the path, so the key may itself contain dots.
	 *
	 * @return void
	 */
	public function testPathSplitsOnFirstDotOnly(): void {
		$check = new PresentationCheck(['card' => ['title.field' => 'name']], []);

		$this->assertSame('name', $check->declared('card.title.field'));

	}//end testPathSplitsOnFirstDotOnly()

	/**
	 * The schema reference is the query's first schema, or null.
	 *
	 * @return void
	 */
	public function testSchemaRef(): void {
		$this->assertSame('7', (new PresentationCheck([], ['schemas' => ['7', '8']]))->schemaRef());
		$this->assertNull((new PresentationCheck([], ['schemas' => []]))->schemaRef());
		$this->assertNull((new PresentationCheck([], []))->schemaRef());

	}//end testSchemaRef()
}//end class
