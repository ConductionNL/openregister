<?php

/**
 * The schema and register reads give a signed-in user a limit of their own.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/api-authorization/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\RegistersController;
use OCA\OpenRegister\Controller\SchemasController;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Nextcloud's RateLimitingMiddleware applies the anonymous limit, counted per IP address, to a
 * signed-in user whenever the action declares no UserRateLimit. Every signed-in user behind one
 * address (an office NAT, a CI runner) then shares 120 schema reads a minute, which an app's
 * first page load of a register with 46 schemas uses up. Each read declares a user limit of
 * five times its anonymous one, as ObjectsController's reads do.
 *
 * @spec openspec/specs/api-authorization/spec.md
 */
class SchemaRegisterReadRateLimitTest extends TestCase {

	/**
	 * The reads under test.
	 *
	 * @return array<string, array{class-string, string}>
	 */
	public static function reads(): array {
		return [
			'schemas index'               => [SchemasController::class, 'index'],
			'schemas show'                => [SchemasController::class, 'show'],
			'schemas resolveByImplements' => [SchemasController::class, 'resolveByImplements'],
			'registers index'             => [RegistersController::class, 'index'],
			'registers show'              => [RegistersController::class, 'show'],
		];
	}

	/**
	 * A signed-in user gets a limit of their own, five times the anonymous one.
	 *
	 * @param class-string $class  The controller.
	 * @param string       $method The action.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-authorization/spec.md
	 */
	#[DataProvider('reads')]
	public function testASignedInUserHasALimitOfTheirOwn(string $class, string $method): void {
		$reflection = new ReflectionMethod($class, $method);
		$user = $reflection->getAttributes(UserRateLimit::class);
		$anon = $reflection->getAttributes(AnonRateLimit::class);

		$this->assertCount(1, $user, $method . ' declares no UserRateLimit, so signed-in users share the per-IP anonymous limit.');
		$this->assertCount(1, $anon);
		$this->assertSame(5 * $anon[0]->getArguments()['limit'], $user[0]->getArguments()['limit']);
	}
}
