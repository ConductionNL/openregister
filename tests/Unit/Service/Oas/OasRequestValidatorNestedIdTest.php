<?php

/**
 * Unit tests for validating against a schema that declares a nested $id beside a dynamic anchor.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * A nested $id opens a new resource scope, so dynamic refs are left for the validator to resolve;
 * validation still accepts what the schema allows and refuses what it does not.
 *
 * @covers \OCA\OpenRegister\Service\Oas\OasRequestValidator
 */
class OasRequestValidatorNestedIdTest extends TestCase {

	/**
	 * A schema with a dynamic anchor and a nested resource carrying its own $id.
	 *
	 * @return array<string, mixed>
	 */
	private static function schema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'type'       => 'object',
			'required'   => ['name'],
			'properties' => [
				'name' => ['type' => 'string'],
				'tag'  => ['$ref' => '#/$defs/tag'],
			],
			'$defs'      => [
				'tag'  => ['$id' => 'https://or.example/schemas/tag', 'type' => 'string', 'maxLength' => 5],
				'node' => ['$dynamicAnchor' => 'node', 'type' => 'object'],
			],
		];

	}//end schema()

	/**
	 * A body the schema allows has no errors.
	 *
	 * @return void
	 */
	public function testAnAllowedBodyPasses(): void {
		$validator = new OasRequestValidator();

		$this->assertSame([], $validator->validate(['name' => 'Jansen', 'tag' => 'vip'], self::schema()));
		$this->assertTrue($validator->isValid(['name' => 'Jansen'], self::schema()));

	}//end testAnAllowedBodyPasses()

	/**
	 * A body that breaks the nested resource is refused.
	 *
	 * @return void
	 */
	public function testABodyBreakingTheNestedResourceIsRefused(): void {
		$validator = new OasRequestValidator();

		$this->assertNotSame([], $validator->validate(['name' => 'Jansen', 'tag' => 'far-too-long'], self::schema()));
		$this->assertFalse($validator->isValid(['tag' => 'vip'], self::schema()));

	}//end testABodyBreakingTheNestedResourceIsRefused()
}//end class
