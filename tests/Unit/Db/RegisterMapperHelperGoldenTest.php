<?php

/**
 * Golden test for RegisterMapperHelper: version bumping and schemas decoding.
 *
 * Pins the exact output of bumpPatchVersion() and decodeSchemasField() over a
 * wide spread of inputs, including the odd and legacy shapes, so a refactor
 * that changes any of them shows up here.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\RegisterMapperHelper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\RegisterMapperHelper
 */
class RegisterMapperHelperGoldenTest extends TestCase {

	private const BUMPS = [
		'1.0.0' => '1.0.1',
		'1.0.0-beta' => '1.0.1-beta',
		'1' => '1.0.1',
		'1.2' => '1.2.1',
		'  2.3.4  ' => '2.3.5',
		'1.2.3.4' => '1.2.4.4',
		'v1.0.0' => '0.0.1',
		'' => '0.0.1',
		'abc' => '0.0.1',
		'10.20.30+build.5' => '10.20.31+build.5',
		'1.2.3-rc.1+b' => '1.2.4-rc.1+b',
		'0.0.9' => '0.0.10',
		'1..2' => '1.0.1..2',
		'007.08.09' => '7.8.10',
		'1.2.x' => '1.2.1.x',
		"1.0.0\nx" => '0.0.1',
	];

	public function testBumpPatchVersionMatchesGolden(): void {
		$helper = new RegisterMapperHelper();
		foreach (self::BUMPS as $input => $expected) {
			$this->assertSame($expected, $helper->bumpPatchVersion(version: (string) $input), var_export($input, true));
		}
	}//end testBumpPatchVersionMatchesGolden()

	public function testDecodeSchemasFieldMatchesGolden(): void {
		$cases = [
			'list' => [[1, 2], [1, 2]],
			'keyed array' => [['a' => 3], ['a' => 3]],
			'empty array' => [[], []],
			'null' => [null, []],
			'empty string' => ['', []],
			'int zero' => [0, []],
			'float' => [1.5, []],
			'bool' => [true, []],
			'json list' => ['[1,"2"]', [1, '2']],
			'json object' => ['{"a":4}', ['a' => 4]],
			'comma separated' => ['3, 4 ,,5', [0 => '3', 1 => '4', 3 => '5']],
			'single space' => [' ', []],
			'json null' => ['null', ['null']],
			'json string' => ['"x"', ['"x"']],
			'json empty list' => ['[]', []],
			'zero string' => ['0', []],
			'one id' => ['5', ['5']],
		];

		$helper = new RegisterMapperHelper();
		foreach ($cases as $name => [$raw, $expected]) {
			$this->assertSame($expected, $helper->decodeSchemasField(raw: $raw), $name);
		}
	}//end testDecodeSchemasFieldMatchesGolden()
}//end class
