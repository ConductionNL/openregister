<?php

/**
 * A children read reports how many rows a capped level may still take.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rbac\ChildrenRead;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ChildrenRead
 */
class ChildrenReadTest extends TestCase {

	public function testAnUncappedReadHasNoRemainderAndNeverMeetsItsLimit(): void {
		$read           = new ChildrenRead('oc_or_cases', 'parent', null);
		$read->children = array_fill(0, 500, ['uuid' => 'x']);

		$this->assertNull($read->remaining());
		$this->assertFalse($read->limitMet());
	}

	public function testACappedReadCountsDownToItsLimit(): void {
		$read = new ChildrenRead('oc_or_cases', 'parent', 3);

		$this->assertSame(3, $read->remaining());
		$this->assertFalse($read->limitMet());

		$read->children = [['uuid' => 'a'], ['uuid' => 'b']];
		$this->assertSame(1, $read->remaining());
		$this->assertFalse($read->limitMet());

		$read->children[] = ['uuid' => 'c'];
		$this->assertSame(0, $read->remaining());
		$this->assertTrue($read->limitMet());
	}

	public function testAnOverfullReadStillMeetsItsLimit(): void {
		$read           = new ChildrenRead('oc_or_cases', 'parent', 1);
		$read->children = [['uuid' => 'a'], ['uuid' => 'b']];

		$this->assertSame(-1, $read->remaining());
		$this->assertTrue($read->limitMet());
	}

	public function testAZeroLimitIsMetAtOnce(): void {
		$read = new ChildrenRead('oc_or_cases', 'parent', 0);

		$this->assertSame(0, $read->remaining());
		$this->assertTrue($read->limitMet());
		$this->assertSame('oc_or_cases', $read->table);
		$this->assertSame('parent', $read->parentColumn);
	}
}
