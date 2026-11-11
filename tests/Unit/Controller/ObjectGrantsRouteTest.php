<?php

/**
 * The grants list is reachable, once, at the path its consumers call.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectGrantsController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Route reachability for `GET /api/objects/{register}/{schema}/{id}/grants`.
 *
 * @coversNothing
 */
class ObjectGrantsRouteTest extends TestCase {

	/**
	 * The path dossiq's Toegang card calls.
	 *
	 * @var string
	 */
	private const URL = '/api/objects/{register}/{schema}/{id}/grants';

	/**
	 * Every declared route on the grants path.
	 *
	 * @return array<int, array<string, mixed>> The routes.
	 */
	private function grantsRoutes(): array {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';

		return array_values(
			array_filter(
				($declared['routes'] ?? []),
				static fn (array $route): bool => ($route['url'] ?? '') === self::URL
			)
		);
	}//end grantsRoutes()

	/**
	 * Exactly one GET route, aimed at the grants controller.
	 *
	 * A second route on the same path is how `/shares` ended up with an
	 * unreachable twin: the first registration wins and the other never runs.
	 *
	 * @return void
	 */
	public function testTheGrantsPathIsDeclaredOnce(): void {
		$routes = $this->grantsRoutes();

		$this->assertCount(1, $routes);
		$this->assertSame('objectGrants#index', $routes[0]['name']);
		$this->assertSame('GET', $routes[0]['verb']);
		$this->assertSame('[^/]+', ($routes[0]['requirements']['id'] ?? null));
	}//end testTheGrantsPathIsDeclaredOnce()

	/**
	 * The target method exists and lets a non-admin GET through the middleware.
	 *
	 * @return void
	 */
	public function testTheTargetIsReachableForANonAdmin(): void {
		$method = new ReflectionMethod(ObjectGrantsController::class, 'index');

		$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class));
		$this->assertNotEmpty($method->getAttributes(NoCSRFRequired::class));
	}//end testTheTargetIsReachableForANonAdmin()
}//end class
