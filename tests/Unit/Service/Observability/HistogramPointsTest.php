<?php

/**
 * The histogram point builder.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Observability;

use OCA\OpenRegister\Service\Observability\HistogramPoints;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Observability\HistogramPoints
 */
final class HistogramPointsTest extends TestCase {

	/**
	 * A bucket per bound with seconds labels, then +Inf, the sum in seconds and the count, each with the labels.
	 *
	 * @return void
	 */
	public function testBuildsBucketInfSumAndCountPoints(): void {
		$points = HistogramPoints::points(labels: ['type' => 'x'], buckets: [100 => 1, 2500 => 3], count: 4, sumMs: 1250.0);

		$this->assertSame(
			[
				['labels' => ['type' => 'x', 'le' => '0.1'], 'value' => 1, 'suffix' => '_bucket'],
				['labels' => ['type' => 'x', 'le' => '2.5'], 'value' => 3, 'suffix' => '_bucket'],
				['labels' => ['type' => 'x', 'le' => '+Inf'], 'value' => 4, 'suffix' => '_bucket'],
				['labels' => ['type' => 'x'], 'value' => 1.25, 'suffix' => '_sum'],
				['labels' => ['type' => 'x'], 'value' => 4, 'suffix' => '_count'],
			],
			$points
		);
	}//end testBuildsBucketInfSumAndCountPoints()

	/**
	 * Millisecond bounds read as the spec's seconds labels.
	 *
	 * @return void
	 */
	public function testSecondsLabels(): void {
		$this->assertSame(
			['0.01', '0.05', '0.25', '1.0', '2.5', '10.0', '30.0'],
			array_map(static fn (int $ms): string => HistogramPoints::seconds(milliseconds: $ms), [10, 50, 250, 1000, 2500, 10000, 30000])
		);
	}//end testSecondsLabels()
}//end class
