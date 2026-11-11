<?php
/**
 * The object grant API owns `/shares`, and every file-share route is reachable.
 *
 * The router answers a request with the first route whose verb and path match,
 * so a later route on the same verb and path never runs.
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
 * Structural: which handler answers the object share paths.
 *
 * @coversNothing
 */
class ObjectShareRoutesTest extends TestCase {

	/**
	 * The name of the first route declared for a verb and path.
	 *
	 * Placeholder names do not matter to the router, so `{id}` and `{fileId}`
	 * are the same path segment.
	 *
	 * @param string $verb The HTTP verb.
	 * @param string $url  The path, with placeholders.
	 *
	 * @return string|null The route name, or null when none matches.
	 */
	private function firstRouteFor(string $verb, string $url): ?string {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$wanted   = preg_replace('/\{[^}]+\}/', '{}', $url);
		foreach (($declared['routes'] ?? []) as $route) {
			$path = preg_replace('/\{[^}]+\}/', '{}', (string) ($route['url'] ?? ''));
			if (strtoupper((string) ($route['verb'] ?? 'GET')) === $verb && $path === $wanted) {
				return (string) $route['name'];
			}
		}

		return null;
	}//end firstRouteFor()

	/**
	 * GET, POST and DELETE on an object's `/shares` reach the grant API.
	 *
	 * @return void
	 */
	public function testTheGrantApiAnswersShares(): void {
		$shares = '/api/objects/{register}/{schema}/{id}/shares';
		$this->assertSame(expected: 'objectSharing#shares', actual: $this->firstRouteFor(verb: 'GET', url: $shares));
		$this->assertSame(expected: 'objectSharing#createShare', actual: $this->firstRouteFor(verb: 'POST', url: $shares));
		$this->assertSame(expected: 'objectSharing#destroyShare', actual: $this->firstRouteFor(verb: 'DELETE', url: $shares . '/{shareId}'));
	}//end testTheGrantApiAnswersShares()

	/**
	 * Every file-share route is the first route for its verb and path.
	 *
	 * @return void
	 */
	public function testEveryFileShareRouteIsReachable(): void {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';
		$checked  = 0;
		foreach (($declared['routes'] ?? []) as $route) {
			if (str_starts_with((string) ($route['name'] ?? ''), 'shareLinks#') === false) {
				continue;
			}

			$verb = strtoupper((string) ($route['verb'] ?? 'GET'));
			$this->assertSame(
				expected: $route['name'],
				actual: $this->firstRouteFor(verb: $verb, url: (string) $route['url']),
				message: $verb . ' ' . $route['url'] . ' is answered by another route'
			);
			$checked++;
		}

		$this->assertSame(expected: 4, actual: $checked, message: 'index, create, destroy and files are declared');
	}//end testEveryFileShareRouteIsReachable()
}//end class
