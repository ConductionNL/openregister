<?php

/**
 * Unit tests for normalising values before they are compared.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Service\Interaction\ValueNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * JSON text decodes, empties become null, whole floats become ints, maps are key-sorted and lists keep order.
 *
 * @covers \OCA\OpenRegister\Service\Interaction\ValueNormaliser
 */
class ValueNormaliserTest extends TestCase {

	/**
	 * Inputs and their normal form.
	 *
	 * @return array<string, array{0: mixed, 1: mixed}>
	 */
	public static function values(): array {
		return [
			'json map'              => ['{"b":1,"a":2.0}', ['a' => 2, 'b' => 1]],
			'json list keeps order' => ['[3,1]', [3, 1]],
			'bracketed non-json'    => ['[not json', '[not json'],
			'braced non-json'       => ['{draft}', '{draft}'],
			'empty string'          => ['', null],
			'empty list'            => [[], null],
			'empty json list'       => ['[]', null],
			'whole float'           => [4.0, 4],
			'fractional float'      => [4.5, 4.5],
			'plain text'            => ['open', 'open'],
			'nested'                => [['z' => ['', 1.0], 'a' => '{"y":1,"x":[]}'], ['a' => ['x' => null, 'y' => 1], 'z' => [null, 1]]],
		];

	}//end values()

	/**
	 * Each value has one normal form.
	 *
	 * @param mixed $value    The value.
	 * @param mixed $expected Its normal form.
	 *
	 * @return void
	 */
	#[DataProvider('values')]
	public function testNormalises(mixed $value, mixed $expected): void {
		$this->assertSame($expected, (new ValueNormaliser())->normalised($value));

	}//end testNormalises()
}//end class
