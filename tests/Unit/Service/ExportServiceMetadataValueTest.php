<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ExportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the cell value ExportService writes for metadata headers (`@self.<field>`
 * and the legacy `_<field>`), for every value shape the metadata array holds.
 *
 * @covers \OCA\OpenRegister\Service\ExportService
 */
class ExportServiceMetadataValueTest extends TestCase {
	private const METADATA = [
		'name'      => 'Plain name',
		'created'   => '2026-03-04T05:06:07Z',
		'notADate'  => 'TZ-but-not-a-date',
		'tOnly'     => 'Tuesday',
		'zero'      => 0,
		'falseFlag' => false,
		'trueFlag'  => true,
		'float'     => 1.5,
		'empty'     => '',
		'list'      => ['a', 'b/c'],
		'map'       => ['k' => 'v'],
		'nothing'   => null,
	];

	/**
	 * @return array<string, array{string, string|null}>
	 */
	public static function headers(): array {
		$expected = [
			'name'      => 'Plain name',
			'created'   => '2026-03-04 05:06:07',
			'notADate'  => 'TZ-but-not-a-date',
			'tOnly'     => 'Tuesday',
			'zero'      => '0',
			'falseFlag' => 'false',
			'trueFlag'  => 'true',
			'float'     => '1.5',
			'empty'     => '',
			'list'      => '["a","b/c"]',
			'map'       => '{"k":"v"}',
			'nothing'   => null,
			'missing'   => null,
		];
		$cases = [];
		foreach ($expected as $field => $value) {
			$cases['@self.' . $field] = ['@self.' . $field, $value];
			$cases['_' . $field]      = ['_' . $field, $value];
		}

		return $cases;
	}//end headers()

	/**
	 * @dataProvider headers
	 */
	public function testMetadataHeaderValue(string $header, ?string $expected): void {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getObjectArray')->willReturn(self::METADATA);
		$object->expects($this->never())->method('getObject');

		$service = (new ReflectionClass(ExportService::class))->newInstanceWithoutConstructor();
		$method  = new \ReflectionMethod($service, 'getObjectValue');
		$method->setAccessible(true);

		$this->assertSame($expected, $method->invoke($service, $object, $header));
	}//end testMetadataHeaderValue()
}//end class
