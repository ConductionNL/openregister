<?php

/**
 * Unit tests for {@see \OCA\OpenRegister\Service\Vocabulary\BranchFilter}.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Vocabulary
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Vocabulary;

use OCA\OpenRegister\Service\Vocabulary\BranchFilter;
use PHPUnit\Framework\TestCase;

/**
 * Parsing a `{branch, depth}` filter value.
 *
 * @covers \OCA\OpenRegister\Service\Vocabulary\BranchFilter
 */
class BranchFilterTest extends TestCase {

	/**
	 * Values that are not a branch filter yield null.
	 *
	 * @return void
	 */
	public function testNonBranchValuesAreNull(): void {
		$this->assertNull(BranchFilter::fromFilterValue('urn:a'));
		$this->assertNull(BranchFilter::fromFilterValue(null));
		$this->assertNull(BranchFilter::fromFilterValue([]));
		$this->assertNull(BranchFilter::fromFilterValue(['branch' => '   ']));
		$this->assertNull(BranchFilter::fromFilterValue(['branch' => 12]));
		$this->assertNull(BranchFilter::fromFilterValue(['depth' => 2]));

	}//end testNonBranchValuesAreNull()

	/**
	 * The root is trimmed and a numeric depth is cast to int.
	 *
	 * @return void
	 */
	public function testRootAndDepthAreParsed(): void {
		$filter = BranchFilter::fromFilterValue(['branch' => '  urn:root ', 'depth' => '3']);

		$this->assertInstanceOf(BranchFilter::class, $filter);
		$this->assertSame('urn:root', $filter->root);
		$this->assertSame(3, $filter->depth);

	}//end testRootAndDepthAreParsed()

	/**
	 * A missing or non-numeric depth means unbounded (null).
	 *
	 * @return void
	 */
	public function testNonNumericDepthIsNull(): void {
		$this->assertNull(BranchFilter::fromFilterValue(['branch' => 'urn:r'])->depth);
		$this->assertNull(BranchFilter::fromFilterValue(['branch' => 'urn:r', 'depth' => 'deep'])->depth);
		$this->assertSame(0, BranchFilter::fromFilterValue(['branch' => 'urn:r', 'depth' => 0])->depth);

	}//end testNonNumericDepthIsNull()
}//end class
