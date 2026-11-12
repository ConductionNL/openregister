<?php

/**
 * What the sanitiser makes of items and allOf values that are no schema.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasPropertySanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses   \OCA\OpenRegister\Service\Oas\OasNames
 */
class OasPropertySanitizerTest extends TestCase {

	/**
	 * Definitions and what they become.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function definitions(): array {
		$filled = ['type' => 'string', 'description' => 'Property value'];

		return [
			'text items' => [
				['type' => 'array', 'items' => 'text'],
				['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Property value'],
			],
			'empty items' => [
				['type' => 'array', 'items' => []],
				['type' => 'array', 'items' => $filled, 'description' => 'Property value'],
			],
			'list items' => [
				['type' => 'array', 'items' => [['type' => 'integer'], ['type' => 'string']]],
				['type' => 'array', 'items' => $filled, 'description' => 'Property value'],
			],
			'empty allOf item' => [
				['allOf' => [[], 'text']],
				['allOf' => [$filled], 'type' => 'string', 'description' => 'Property value'],
			],
			'allOf of text only' => [
				['allOf' => ['text']],
				$filled,
			],
		];
	}//end definitions()

	/**
	 * Each definition sanitises as listed.
	 *
	 * @dataProvider definitions
	 *
	 * @param array<string, mixed> $definition The definition
	 * @param array<string, mixed> $expected   The result
	 *
	 * @return void
	 */
	public function testADefinitionSanitises(array $definition, array $expected): void {
		$sanitizer = new OasPropertySanitizer();

		$this->assertEquals($expected, $sanitizer->sanitize(definition: $definition));
	}//end testADefinitionSanitises()
}//end class
