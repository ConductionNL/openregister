<?php

/**
 * Characterisation of AuthorizationRulesTrait::setAuthorization() on the two entities that use
 * it: an array is kept, a JSON object string is decoded, and anything else (null, invalid JSON,
 * a JSON scalar) falls back to the entity's default authorization.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Organisation;
use OCP\AppFramework\Db\Entity;
use PHPUnit\Framework\TestCase;

/**
 * Golden authorization decoding.
 *
 * @covers \OCA\OpenRegister\Db\AuthorizationRulesTrait
 */
final class AuthorizationRulesDecodeGoldenTest extends TestCase {

	/**
	 * The entities using the trait.
	 *
	 * @return array<string, array{0: class-string<Entity>}>
	 */
	public static function entities(): array {
		return [
			'application' => [Application::class],
			'organisation' => [Organisation::class],
		];
	}//end entities()

	/**
	 * Granted rules are kept, from an array or a JSON object; anything else is the default.
	 *
	 * @param class-string<Entity> $class The entity.
	 *
	 * @return void
	 *
	 * @dataProvider entities
	 */
	public function testSetAuthorization(string $class): void {
		$default = (new $class())->getAuthorization();
		$rules = ['register' => ['read' => ['staff']]];

		$cases = [
			[$rules, $rules],
			['{"register":{"read":["staff"]}}', $rules],
			['[]', []],
			[null, $default],
			['not json', $default],
			['"text"', $default],
			['42', $default],
			['', $default],
		];
		foreach ($cases as [$given, $expected]) {
			$entity = new $class();
			$entity->resetUpdatedFields();
			$this->assertSame($entity, $entity->setAuthorization($given));
			$this->assertSame($expected, $entity->getAuthorization(), var_export($given, true));
			$this->assertArrayHasKey('authorization', $entity->getUpdatedFields());
		}
	}//end testSetAuthorization()
}//end class
