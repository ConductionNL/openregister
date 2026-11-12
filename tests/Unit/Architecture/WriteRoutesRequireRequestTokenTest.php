<?php

/**
 * Write routes require the request token.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude Architecture guard for ADR-054 rule 2, no feature behaviour.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A POST, PUT, PATCH or DELETE route runs Nextcloud's request-token check
 * unless its method carries `NoCSRFRequired`. The browser UI sends the token
 * through `@nextcloud/axios`; API clients using basic auth or an app password
 * pass the same check by sending `OCS-APIRequest: true`.
 *
 * The methods that still opt out are listed in
 * `Fixtures/request-token-exempt-write-routes.php`, each with its reason.
 * A new opt-out fails here until it is listed, and a listed route that no
 * longer opts out fails too, so the list stays the true set.
 */
class WriteRoutesRequireRequestTokenTest extends TestCase {

	private const WRITE_VERBS = ['POST', 'PUT', 'PATCH', 'DELETE'];

	private const RESOURCE_ACTIONS = [
		'create'  => 'POST',
		'update'  => 'PUT',
		'destroy' => 'DELETE',
	];

	/**
	 * Every write route as `name => verb url`, resources expanded.
	 *
	 * @return array<string, list<string>>
	 */
	private function writeRoutes(): array {
		$file   = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$routes = [];

		foreach (($file['routes'] ?? []) as $route) {
			$verb = strtoupper((string)($route['verb'] ?? 'GET'));
			if (in_array($verb, self::WRITE_VERBS, true) === true) {
				$routes[(string)$route['name']][] = $verb . ' ' . $route['url'];
			}
		}

		foreach (($file['resources'] ?? []) as $controller => $config) {
			foreach (self::RESOURCE_ACTIONS as $action => $verb) {
				$suffix = ($action === 'create') ? '' : '/{id}';
				$routes[lcfirst((string)$controller) . '#' . $action][] = $verb . ' ' . $config['url'] . $suffix;
			}
		}

		return $routes;
	}//end writeRoutes()

	/**
	 * Short controller name (`Settings\Cache`, `Objects`) to class name.
	 *
	 * @return array<string, string>
	 */
	private function controllerClasses(): array {
		$classes = [];
		foreach (ControllerSources::files() as $file) {
			$source = (string)file_get_contents($file);
			if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
				|| preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+(\w+)Controller\b/m', $source, $class) !== 1
			) {
				continue;
			}

			$fqcn = $ns[1] . '\\' . $class[1] . 'Controller';
			$sub  = '';
			if (preg_match('/\\\\Controller\\\\(.+)$/', $ns[1], $tail) === 1) {
				$sub = $tail[1] . '\\';
			}

			$classes[$sub . $class[1]] ??= $fqcn;
			$classes[$class[1]]        ??= $fqcn;
		}

		return $classes;
	}//end controllerClasses()

	/**
	 * Whether a route name points at a method that skips the token check.
	 *
	 * @param string                $name    The route name.
	 * @param array<string, string> $classes The controller map.
	 *
	 * @return bool
	 */
	private function skipsTokenCheck(string $name, array $classes): bool {
		[$controller, $action] = explode('#', $name, 2);
		$parts = explode('\\', $controller);
		$last  = array_pop($parts);
		$short = implode('\\', array_merge($parts, [str_replace(' ', '', ucwords(str_replace('_', ' ', $last)))]));
		$short = ucfirst($short);

		$this->assertArrayHasKey($short, $classes, "No controller class for route $name");
		$method = new ReflectionMethod($classes[$short], $action);

		return $method->getAttributes(NoCSRFRequired::class) !== []
			|| preg_match('/@NoCSRFRequired\b/', (string)$method->getDocComment()) === 1;
	}//end skipsTokenCheck()

	/**
	 * No write route skips the token check unless it is listed with a reason.
	 *
	 * @return void
	 */
	public function testWriteRoutesSkipTheTokenCheckOnlyWhenListed(): void {
		$exempt  = include __DIR__ . '/Fixtures/request-token-exempt-write-routes.php';
		$classes = $this->controllerClasses();
		$unlisted = [];
		$stale    = [];

		foreach ($this->writeRoutes() as $name => $addresses) {
			$skips = $this->skipsTokenCheck($name, $classes);
			if ($skips === true && isset($exempt[$name]) === false) {
				$unlisted[] = $name . ' (' . implode(', ', $addresses) . ')';
			}

			if ($skips === false && isset($exempt[$name]) === true) {
				$stale[] = $name;
			}
		}

		$this->assertSame(
			[],
			$unlisted,
			"These write routes skip the request-token check without a listed reason.\n"
			. "Remove NoCSRFRequired, or list the route with its reason in the fixture."
		);
		$this->assertSame([], $stale, 'These listed routes no longer skip the check; remove them from the fixture.');
	}//end testWriteRoutesSkipTheTokenCheckOnlyWhenListed()

	/**
	 * Every listed reason names one of the known exemption kinds.
	 *
	 * @return void
	 */
	public function testEveryExemptionHasAKnownReason(): void {
		$exempt = include __DIR__ . '/Fixtures/request-token-exempt-write-routes.php';
		$kinds  = ['public-token', 'cors-api', 'header-credential', 'external-api', 'raw-fetch-caller'];

		$this->assertNotSame([], $exempt);
		foreach ($exempt as $name => $reason) {
			$this->assertContains(explode(':', $reason, 2)[0], $kinds, "Unknown exemption reason for $name");
		}
	}//end testEveryExemptionHasAKnownReason()
}//end class
