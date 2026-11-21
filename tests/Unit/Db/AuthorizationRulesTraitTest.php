<?php

/**
 * AuthorizationRulesTrait reads authorization rules from an array or a JSON string and
 * falls back to the entity's default rules for anything else.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\Organisation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour of setAuthorization() on the two entities that use the trait.
 */
class AuthorizationRulesTraitTest extends TestCase {

	/**
	 * @return array<string, array{0: class-string}>
	 */
	public static function entities(): array {
		return [
			'organisation' => [Organisation::class],
			'application'  => [Application::class],
		];
	}

	/**
	 * The rules a fresh entity of the class falls back to.
	 *
	 * @param class-string $class Entity class.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(string $class): array {
		$entity = new $class();
		$entity->setAuthorization(null);
		return $entity->getAuthorization();
	}

	#[DataProvider('entities')]
	public function testAJsonObjectStringIsDecodedIntoTheRules(string $class): void {
		$entity = new $class();

		$returned = $entity->setAuthorization('{"register":{"read":["staff"]}}');

		$this->assertSame($entity, $returned);
		$this->assertSame(['register' => ['read' => ['staff']]], $entity->getAuthorization());
		$this->assertArrayHasKey('authorization', $entity->getUpdatedFields());
	}

	#[DataProvider('entities')]
	public function testAnArrayIsStoredAsGiven(string $class): void {
		$entity = new $class();

		$entity->setAuthorization(['schema' => ['delete' => ['admin']]]);

		$this->assertSame(['schema' => ['delete' => ['admin']]], $entity->getAuthorization());
	}

	#[DataProvider('entities')]
	public function testInvalidJsonFallsBackToTheDefaultRules(string $class): void {
		$entity = new $class();
		$entity->setAuthorization(['schema' => ['delete' => ['admin']]]);

		$entity->setAuthorization('{not json');

		$defaults = $this->defaults($class);
		$this->assertSame($defaults, $entity->getAuthorization());
		$this->assertNotSame(['schema' => ['delete' => ['admin']]], $defaults);
		$this->assertNotSame([], $defaults);
	}

	#[DataProvider('entities')]
	public function testAJsonScalarFallsBackToTheDefaultRules(string $class): void {
		$entity = new $class();

		$entity->setAuthorization('"admin"');

		$this->assertSame($this->defaults($class), $entity->getAuthorization());
	}

	#[DataProvider('entities')]
	public function testAJsonNullFallsBackToTheDefaultRules(string $class): void {
		$entity = new $class();
		$entity->setAuthorization(['x' => []]);

		$entity->setAuthorization('null');

		$this->assertSame($this->defaults($class), $entity->getAuthorization());
	}
}
