<?php

/**
 * The object API routes that moved out of ObjectsController keep their URL and verb.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/functional-areas-depend-in-one-direction/specs/backend-modularity/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use OCA\OpenRegister\Controller\ObjectsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Each moved route is declared once, at its old URL and verb, and names a public method of the area's controller.
 */
class ObjectRoutesServedByTheirAreaTest extends TestCase {

	/**
	 * The moved routes: URL, verb, the route name now, the controller class and the method.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
	 */
	public static function movedRoutes(): array {
		return [
			'object history' => [
				'/api/objects/{register}/{schema}/{id}/logs',
				'GET',
				'auditTrail#objectLogs',
				\OCA\OpenRegister\Controller\AuditTrailController::class,
				'objectLogs',
			],
			'bulk download' => [
				'/api/objects/{register}/{schema}/{id}/files/download',
				'GET',
				'objectFilesArchive#downloadFiles',
				\OCA\OpenRegister\Files\Controller\ObjectFilesArchiveController::class,
				'downloadFiles',
			],
		];
	}//end movedRoutes()

	/**
	 * The declared routes.
	 *
	 * @return array<int, array<string, mixed>> The routes.
	 */
	private function routes(): array {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';

		return ($declared['routes'] ?? []);
	}//end routes()

	/**
	 * The route is declared exactly once at its URL and verb, under its new name.
	 *
	 * @param string $url    The URL.
	 * @param string $verb   The HTTP verb.
	 * @param string $name   The route name.
	 * @param string $class  The controller class.
	 * @param string $method The controller method.
	 *
	 * @return void
	 */
	#[DataProvider('movedRoutes')]
	public function testTheUrlAndVerbAreKept(string $url, string $verb, string $name, string $class, string $method): void {
		$matches = array_values(
			array_filter(
				$this->routes(),
				static fn (array $route): bool => ($route['url'] ?? '') === $url && ($route['verb'] ?? 'GET') === $verb
			)
		);

		$this->assertCount(1, $matches, $verb . ' ' . $url . ' must be declared exactly once.');
		$this->assertSame($name, $matches[0]['name']);
		$this->assertSame(['id' => '[^/]+'], $matches[0]['requirements']);
	}//end testTheUrlAndVerbAreKept()

	/**
	 * The route name resolves to the area's controller, and the method is public there and gone from ObjectsController.
	 *
	 * @param string $url    The URL.
	 * @param string $verb   The HTTP verb.
	 * @param string $name   The route name.
	 * @param string $class  The controller class.
	 * @param string $method The controller method.
	 *
	 * @return void
	 */
	#[DataProvider('movedRoutes')]
	public function testTheNameReachesTheAreaController(string $url, string $verb, string $name, string $class, string $method): void {
		[$controller, $action] = explode('#', $name);
		$routed = 'OCA\\OpenRegister\\Controller\\' . ucfirst($controller) . 'Controller';
		$aliases = array_map(static fn (array $entry): string => $entry['class'], ClassAliasMap::fromFile()->entries());

		$this->assertSame($class, ($aliases[$routed] ?? $routed), $name . ' does not reach ' . $class . '.');
		$this->assertSame($method, $action);
		$this->assertTrue((new ReflectionClass($class))->getMethod($method)->isPublic());
		$this->assertFalse(
			(new ReflectionClass(ObjectsController::class))->hasMethod($method === 'objectLogs' ? 'logs' : $method),
			'ObjectsController still serves ' . $name . '.'
		);
	}//end testTheNameReachesTheAreaController()
}//end class
