<?php

/**
 * The scoped audit route reaches its method, and reaches it before `show`
 * swallows the word.
 *
 * 🔴 `auditTrail#show` is declared as `/api/audit-trails/{id}` with
 * `'id' => '[^/]+'`. A route declared AFTER it never receives a request:
 * `/api/audit-trails/readable` is matched as `show('readable')`, which looks up
 * an audit trail whose id is the string `readable`, does not find one, and
 * answers 404. The endpoint exists, the code is right, and the symptom is
 * indistinguishable from a typo in the url.
 *
 * 🔑 THE SECOND FAILURE IS QUIETER STILL. A scoped list with no route at all is
 * a guard with a full green suite and no call site, which this repo has shipped
 * three times in one day. So this test asserts the wiring from the caller's
 * side: the route exists, its target method exists on the controller, and the
 * controller really is the class the lister is used from.
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

use OCA\OpenRegister\Controller\AuditTrailController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Structural: the scoped audit route, its order and its target.
 *
 * @coversNothing
 */
class ReadableAuditRouteIsReachableTest extends TestCase {

	/**
	 * The declared routes, in declaration order.
	 *
	 * @return array<int, array<string, mixed>> The routes.
	 */
	private function routes(): array {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';

		return ($declared['routes'] ?? []);
	}//end routes()

	/**
	 * The index of a named route, or null.
	 *
	 * @param string $name The route name.
	 *
	 * @return int|null The index.
	 */
	private function indexOf(string $name): ?int {
		foreach ($this->routes() as $index => $route) {
			if (($route['name'] ?? '') === $name) {
				return $index;
			}
		}

		return null;
	}//end indexOf()

	/**
	 * The route is declared, at the url the client calls.
	 *
	 * @return void
	 */
	public function testTheScopedRouteIsDeclared(): void {
		$index = $this->indexOf('auditTrail#readable');

		$this->assertNotNull($index, 'The scoped audit list has no route, so nothing can reach it.');
		$this->assertSame('/api/audit-trails/readable', $this->routes()[$index]['url']);
		$this->assertSame('GET', $this->routes()[$index]['verb']);
	}//end testTheScopedRouteIsDeclared()

	/**
	 * It is declared before the catch-all `{id}` route.
	 *
	 * @return void
	 */
	public function testItIsDeclaredBeforeShowSwallowsIt(): void {
		$readable = $this->indexOf('auditTrail#readable');
		$show = $this->indexOf('auditTrail#show');

		$this->assertNotNull($readable);
		$this->assertNotNull($show);
		$this->assertLessThan(
			$show,
			$readable,
			'auditTrail#show matches [^/]+ and is declared first, so /api/audit-trails/readable answers 404.'
		);
	}//end testItIsDeclaredBeforeShowSwallowsIt()

	/**
	 * The method the route names exists, and is public.
	 *
	 * @return void
	 */
	public function testTheTargetMethodExists(): void {
		$reflection = new ReflectionClass(AuditTrailController::class);

		$this->assertTrue($reflection->hasMethod('readable'), 'The route names a method the controller does not have.');
		$this->assertTrue($reflection->getMethod('readable')->isPublic());
	}//end testTheTargetMethodExists()

	/**
	 * The method is open to a non-admin, which is the whole point of it.
	 *
	 * The rest of this controller is admin-only at the framework level. A
	 * scoped list that inherited that gate would be a second admin endpoint
	 * with extra steps.
	 *
	 * @return void
	 */
	public function testTheTargetMethodIsOpenToANonAdmin(): void {
		$doc = (new ReflectionClass(AuditTrailController::class))->getMethod('readable')->getDocComment();

		$this->assertIsString($doc);
		$this->assertStringContainsString('@NoAdminRequired', $doc, 'The scoped list is gated to admins, like the index it exists to complement.');
	}//end testTheTargetMethodIsOpenToANonAdmin()

	/**
	 * The lister is used from the controller, not only defined.
	 *
	 * @return void
	 */
	public function testTheListerIsUsedFromTheController(): void {
		$source = file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AuditTrailController.php');

		$this->assertIsString($source);
		$this->assertStringContainsString('ReadableAuditTrailLister', $source);
		$this->assertStringContainsString('readableLister->page(', $source, 'The lister is injected and never called.');
	}//end testTheListerIsUsedFromTheController()
}//end class
