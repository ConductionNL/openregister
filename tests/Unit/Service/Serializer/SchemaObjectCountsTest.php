<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Serializer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Serializer;

use OCA\OpenRegister\Service\Serializer\SchemaObjectCounts;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Serializer\SchemaObjectCounts
 */
class SchemaObjectCountsTest extends TestCase {

	public function testACountedSchemaAnswersItsTotal(): void {
		$counts = new SchemaObjectCounts([10 => ['total' => 3], 'abc' => ['total' => '7']]);

		$this->assertSame(3, $counts->totalFor(10));
		$this->assertSame(7, $counts->totalFor('abc'));
	}

	public function testAnythingNotCountedIsZero(): void {
		$counts = new SchemaObjectCounts([10 => ['other' => 3]]);

		$this->assertSame(0, $counts->totalFor(10));
		$this->assertSame(0, $counts->totalFor(11));
		$this->assertSame(0, $counts->totalFor(null));
		$this->assertSame(0, (new SchemaObjectCounts())->totalFor(10));
	}
}//end class
