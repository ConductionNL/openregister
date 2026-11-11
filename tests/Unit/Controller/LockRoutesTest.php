<?php

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The verbs a client can release a lock with.
 *
 * 🔴 A VERB THIS APP DOES NOT DECLARE ANSWERS 404 FROM THE ROUTER, AND A
 * CLIENT CANNOT TELL THAT FROM A 404 ABOUT THE OBJECT.
 * `@conduction/nextcloud-vue`'s `useObjectLock.release()` sent
 * `DELETE /api/objects/{register}/{schema}/{id}/lock` until
 * nextcloud-vue#1202, and reads a 404 as "already released; idempotent". This
 * app declared `POST /lock` and `POST /unlock` and no DELETE, so every release
 * in every app on that library 404ed at the router, took the idempotent branch
 * and freed nothing. Silently, and for months.
 *
 * So the route is declared, pointing at the SAME controller method as
 * `POST /unlock`, and this file is what stops it being dropped again. Both
 * assertions are about `routes.php` as a file rather than about a live request:
 * a unit test cannot boot the Nextcloud router, and reading the declaration is
 * exactly the fact that was missing.
 *
 * 🔑 THE TWO VERBS MUST RESOLVE TO ONE METHOD. Two implementations of "release
 * this lock" is how one of them grows a check the other does not have.
 *
 * @package Unit\Controller
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
 */
class LockRoutesTest extends TestCase {

	/**
	 * The route declarations, as the file holds them.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $routes = [];

	/**
	 * Read `appinfo/routes.php` the way Nextcloud does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->routes = ($declared['routes'] ?? []);
	}

	/**
	 * Every declared lock route, by verb.
	 *
	 * @param string $suffix The url suffix, `lock` or `unlock`.
	 *
	 * @return array<string, string> Verb to route name.
	 */
	private function lockRoutes(string $suffix): array {
		$found = [];
		foreach ($this->routes as $route) {
			$url = (string)($route['url'] ?? '');
			if ($url === '/api/objects/{register}/{schema}/{id}/' . $suffix) {
				$found[strtoupper((string)($route['verb'] ?? ''))] = (string)($route['name'] ?? '');
			}
		}

		return $found;
	}

	/**
	 * 🔴 A lock can be released by deleting it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testDeleteOnTheLockIsDeclared(): void {
		$onLock = $this->lockRoutes(suffix: 'lock');

		$this->assertArrayHasKey(
			'DELETE',
			$onLock,
			'a client releasing a lock by deleting it must reach this app, not the router\'s 404'
		);
		// The control: POST is still how a lock is TAKEN, so the DELETE did not
		// replace the acquire.
		$this->assertArrayHasKey('POST', $onLock);
		$this->assertSame('objects#lock', $onLock['POST']);
	}

	/**
	 * 🔴 Both release verbs reach one method.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testBothReleaseVerbsReachTheSameMethod(): void {
		$deleteTarget = ($this->lockRoutes(suffix: 'lock')['DELETE'] ?? '');
		$postTarget = ($this->lockRoutes(suffix: 'unlock')['POST'] ?? '');

		$this->assertSame('objects#unlock', $postTarget);
		$this->assertSame(
			$postTarget,
			$deleteTarget,
			'two implementations of "release this lock" is how one grows a check the other lacks'
		);
	}

	/**
	 * The method both routes name exists on the controller (ADR-029).
	 *
	 * A route pointing at a method that is not there is a ReflectionException
	 * 500 at request time and nothing at all before it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-is-released-through-its-own-endpoint-and-a-release-says-whether-there-was-one
	 */
	public function testTheTargetMethodsExist(): void {
		$reflection = new ReflectionClass(ObjectsController::class);

		$this->assertTrue($reflection->hasMethod('lock'));
		$this->assertTrue($reflection->hasMethod('unlock'));
	}
}//end class
