<?php

/**
 * PropertyConversionService preview: the sample cap, how a rejected value is
 * rendered (scalar, JSON, truncated), date and string targets, and integer
 * booleans.
 *
 * PropertyConversionServiceTest covers the matrix, refusals and the boolean and
 * integer rules; this file covers the remaining value branches.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schema
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

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Schema\PropertyConversionService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Schema\PropertyConversionService
 */
class PropertyConversionPreviewSamplesTest extends TestCase {

	public function testRejectionsAreCountedInFullButSampledUpToTheCap(): void {
		$values = array_map(static fn (int $i): string => "x{$i}", range(1, PropertyConversionService::SAMPLE_SIZE + 3));
		$values[] = '42';

		$preview = (new PropertyConversionService())->preview($values, 'string', 'number');

		$this->assertSame(PropertyConversionService::SAMPLE_SIZE + 4, $preview['total']);
		$this->assertSame(1, $preview['convertible']);
		$this->assertSame(PropertyConversionService::SAMPLE_SIZE + 3, $preview['rejected']);
		$this->assertCount(PropertyConversionService::SAMPLE_SIZE, $preview['samples']);
		$this->assertSame(['value' => 'x1', 'reason' => 'Not a valid number.'], $preview['samples'][0]);
		$this->assertNull($preview['reason']);
	}

	public function testANonScalarSampleIsShownAsJsonAndALongOneIsTruncated(): void {
		$long = str_repeat('a', 130);

		$preview = (new PropertyConversionService())->preview([['k' => 1], $long], 'string', 'date');

		$this->assertSame('{"k":1}', $preview['samples'][0]['value']);
		$this->assertSame(str_repeat('a', 117) . '...', $preview['samples'][1]['value']);
		$this->assertSame(120, mb_strlen($preview['samples'][1]['value']));
	}

	public function testDatesAndDatetimesSurviveOnlyWhenParseable(): void {
		$service = new PropertyConversionService();

		$this->assertSame(1, $service->preview(['2026-03-04'], 'string', 'date')['convertible']);
		$this->assertSame(1, $service->preview(['2026-03-04T10:00:00+01:00'], 'date', 'datetime')['convertible']);
		$this->assertSame(2, $service->preview(['31-31-2026', '   ', ], 'string', 'datetime')['rejected']);
		$this->assertSame(1, $service->preview([20260304], 'string', 'date')['rejected']);
	}

	public function testOnlyScalarsSurviveAConversionToString(): void {
		$preview = (new PropertyConversionService())->preview([12, 1.5, true, [1]], 'integer', 'string');

		$this->assertSame(3, $preview['convertible']);
		$this->assertSame(1, $preview['rejected']);
	}

	public function testIntegerZeroAndOneAreBooleansButTwoIsNot(): void {
		$preview = (new PropertyConversionService())->preview([0, 1, 2, 1.0], 'string', 'boolean');

		$this->assertSame(2, $preview['convertible']);
		$this->assertSame(['2', '1'], array_column($preview['samples'], 'value'));
	}
}
