<?php

/**
 * The reference-options route points at a method that exists, and is declared
 * where the router will actually reach it.
 *
 * 🔴 TWO WAYS THIS ENDPOINT COULD HAVE SHIPPED DARK, AND NEITHER WOULD HAVE
 * FAILED A UNIT TEST.
 *
 * A route naming a method the controller does not have is a ReflectionException
 * at request time, not at boot. And `{id}` in `objects#show` matches `[^/]+`,
 * so a route declared AFTER it never receives a request: the generic one
 * swallows the longer path and answers 404 for an endpoint that exists.
 *
 * 🔑 THE SECOND IS THE NASTY ONE, because the symptom is a 404, which is
 * exactly what a wrong URL looks like. An e2e written against it would report
 * "endpoint missing" and somebody would go looking in the wrong file.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Structural: the route and its target.
 *
 * @coversNothing
 */
class ReferenceOptionsRouteIsReachableTest extends TestCase {

	/**
	 * The declared routes.
	 *
	 * @return array<int, array<string, mixed>> The routes, in declaration order.
	 */
	private function routes(): array {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';

		return ($declared['routes'] ?? []);
	}//end routes()

	/**
	 * The index of a named route, or null.
	 *
	 * @param string $name The route name.
	 * @param string $url  The url it must carry.
	 *
	 * @return int|null The index.
	 */
	private function indexOf(string $name, string $url): ?int {
		foreach ($this->routes() as $index => $route) {
			if (($route['name'] ?? '') === $name && ($route['url'] ?? '') === $url) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * The route is declared.
	 *
	 * @return void
	 */
	public function testTheRouteIsDeclared(): void {
		$this->assertNotNull(
			$this->indexOf(
				'objects#referenceOptions',
				'/api/objects/{register}/{schema}/{id}/reference-options'
			)
		);
	}//end testTheRouteIsDeclared()

	/**
	 * 🔴 IT IS DECLARED BEFORE THE GENERIC `{id}` ROUTE THAT WOULD SWALLOW IT.
	 *
	 * @return void
	 */
	public function testItIsDeclaredBeforeTheRouteThatWouldSwallowIt(): void {
		$options = $this->indexOf(
			'objects#referenceOptions',
			'/api/objects/{register}/{schema}/{id}/reference-options'
		);
		$show = $this->indexOf('objects#show', '/api/objects/{register}/{schema}/{id}');

		$this->assertIsInt($options);
		$this->assertIsInt($show);
		$this->assertLessThan(
			$show,
			$options,
			'Declared after objects#show, this route never receives a request: `{id}` matches `[^/]+` '
			. 'and the generic route answers 404 for an endpoint that exists.'
		);
	}//end testItIsDeclaredBeforeTheRouteThatWouldSwallowIt()

	/**
	 * The controller really declares the method the route names.
	 *
	 * Asserted against the SOURCE rather than with `method_exists()`, because
	 * the controller extends an OCP class and cannot be autoloaded outside a
	 * Nextcloud runtime: `method_exists()` would answer false for every method
	 * on it and this test would pass for the wrong reason.
	 *
	 * @return void
	 */
	public function testTheControllerDeclaresTheMethod(): void {
		$source = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/ObjectsController.php'
		);

		$this->assertStringContainsString(
			'public function referenceOptions(',
			$source,
			'The route names a method the controller does not have, which is a 500 at request time.'
		);
	}//end testTheControllerDeclaresTheMethod()

	/**
	 * It is reachable to an ordinary user, not only an administrator.
	 *
	 * A picker that only administrators can fill is a picker nobody uses.
	 *
	 * @return void
	 */
	public function testItIsReachableToAnOrdinaryUser(): void {
		$source = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Controller/ObjectsController.php'
		);

		$start = strpos($source, 'public function referenceOptions(');
		$this->assertIsInt($start);

		// The attributes sit immediately above the declaration.
		$preamble = substr($source, max(0, ($start - 400)), 400);

		$this->assertStringContainsString('#[NoAdminRequired]', $preamble);
	}//end testItIsReachableToAnOrdinaryUser()
}//end class
