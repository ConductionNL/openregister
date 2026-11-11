<?php

/**
 * DynamicAnchorScanTest.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/oas-validation/spec.md#requirement-request-validation-against-oas-schema
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\DynamicAnchorScan;
use PHPUnit\Framework\TestCase;

/**
 * The walk records every string anchor at its escaped pointer and counts `$id`s below the root only.
 */
class DynamicAnchorScanTest extends TestCase {
	public function testAnchorsAndNestedIdsAreRecordedThroughTheWholeTree(): void {
		$scan = new DynamicAnchorScan();
		$scan->collect(
			[
				'$id' => 'root',
				'$dynamicAnchor' => 'top',
				'$defs' => [
					'a/b' => ['$id' => 'nested', '$dynamicAnchor' => 'node'],
					'c~d' => ['$dynamicAnchor' => 7, 'items' => [['$dynamicAnchor' => 'node']]],
				],
			]
		);

		$this->assertSame(['top' => [''], 'node' => ['/$defs/a~1b', '/$defs/c~0d/items/0']], $scan->anchors);
		$this->assertSame(1, $scan->nestedIds);
	}

	public function testANonArrayNodeRecordsNothing(): void {
		$scan = new DynamicAnchorScan();
		$scan->collect('not a schema');

		$this->assertSame([], $scan->anchors);
		$this->assertSame(0, $scan->nestedIds);
	}
}
