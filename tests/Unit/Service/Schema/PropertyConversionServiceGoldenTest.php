<?php

/**
 * Golden test for PropertyConversionService's preview and refusal texts.
 *
 * Pins the complete preview array (keys, order, counts, samples) and both
 * refusal sentences exactly, as they were before the preview was split
 * into small units.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Schema;

use OCA\OpenRegister\Service\Schema\PropertyConversionService;
use PHPUnit\Framework\TestCase;

class PropertyConversionServiceGoldenTest extends TestCase {

	public function testAnUnsupportedPreviewIsTheExactRefusal(): void {
		$this->assertSame(
			[
				'supported' => false,
				'total' => 2,
				'convertible' => 0,
				'rejected' => 2,
				'samples' => [],
				'reason' => 'A property of type "file" cannot be converted to another type, because there is '
					.'no per-value test that says honestly whether a value would survive it.',
			],
			(new PropertyConversionService())->preview(['a', 'b'], 'file', 'number')
		);
	}

	public function testARefusalWithTargetsNamesThem(): void {
		$this->assertSame(
			'A property of type "boolean" cannot be converted to "number". The supported targets are: string.',
			(new PropertyConversionService())->refusalReason('boolean', 'number')
		);
	}

	public function testASupportedPreviewIsExact(): void {
		$values = [null, '', 'ja', ' NEE ', 'maybe', 2, 1, true, ['x' => 1], str_repeat('é', 130)];

		$this->assertSame(
			[
				'supported' => true,
				'total' => 10,
				'convertible' => 6,
				'rejected' => 4,
				'samples' => [
					['value' => 'maybe', 'reason' => 'Not a valid boolean.'],
					['value' => '2', 'reason' => 'Not a valid boolean.'],
					['value' => '{"x":1}', 'reason' => 'Not a valid boolean.'],
					['value' => str_repeat('é', 117) . '...', 'reason' => 'Not a valid boolean.'],
				],
				'reason' => null,
			],
			(new PropertyConversionService())->preview($values, 'string', 'boolean')
		);
	}

	public function testSamplesStopAtTheSampleSizeButTheCountDoesNot(): void {
		$preview = (new PropertyConversionService())->preview(array_fill(0, 15, 'x'), 'string', 'integer');

		$this->assertSame(15, $preview['rejected']);
		$this->assertCount(PropertyConversionService::SAMPLE_SIZE, $preview['samples']);
		$this->assertSame(0, $preview['convertible']);
	}
}
