<?php

/**
 * Every declared route must survive registration.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec exclude the route table is infrastructure, not a specced behaviour
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud names a route after its controller, its action and its `postfix`,
 * and after nothing else. `OC\AppFramework\Routing\RouteParser::processRoute()`
 * builds `strtolower($appName . '.' . $controller . '.' . $action . $postfix)`,
 * and `RouteCollection::add()` OVERWRITES an entry of the same name.
 *
 * Neither the URL nor the verb is part of that name. So two entries pointing at
 * the same controller action with no `postfix` between them are one route, and
 * the last one declared is the one that exists. Nothing warns, and `routes.php`
 * still reads as though both are there.
 *
 * 🔴 THIS APP LOST 13 OF 996 ROUTES THAT WAY, the worst count in the fleet.
 * Twelve were a PUT and a PATCH declared on one settings writer, so exactly one
 * of the two verbs answered and the other returned 405. The thirteenth was
 * `GET /api/organisations/statistics`, which
 * `src/views/settings/sections/OrganisationConfiguration.vue` calls by URL and
 * which had never been registered at all.
 *
 * 🔑 A route count cannot see this. `composer check:routes` reads the declared
 * array and never asks what registers, so it printed a pass on a file that was
 * losing thirteen. The assertion here is therefore on the registration key of
 * each ITEM, not on the file parsing or the array being the right length.
 */
class RouteNameUniquenessTest extends TestCase {

	/**
	 * Read the declared route entries.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The route file.
	 */
	private function routeFile(): array {
		$routes = include dirname(__DIR__, 3) . '/appinfo/routes.php';
		$this->assertIsArray($routes, 'appinfo/routes.php must return an array');

		return $routes;
	}//end routeFile()

	/**
	 * The key Nextcloud registers a route under, minus the app name.
	 *
	 * @param array<string, mixed> $route One entry from the route file.
	 *
	 * @return string The registration key.
	 */
	private function registrationKey(array $route): string {
		return strtolower((string)$route['name'] . (string)($route['postfix'] ?? ''));
	}//end registrationKey()

	/**
	 * No two entries may register under the same key.
	 *
	 * @return void
	 */
	public function testEveryDeclaredRouteRegistersUnderItsOwnName(): void {
		$file = $this->routeFile();

		foreach (['routes', 'ocs'] as $section) {
			$entries = ($file[$section] ?? []);
			$seen = [];

			foreach ($entries as $entry) {
				$key = $this->registrationKey($entry);

				$this->assertArrayNotHasKey(
					$key,
					$seen,
					sprintf(
						"Two '%s' entries register as '%s', so Nextcloud keeps only the last one.\n"
						. "  kept:        %s %s\n"
						. "  OVERWRITTEN: %s %s\n"
						. "Give each entry its own 'postfix'.",
						$section,
						$key,
						($entry['verb'] ?? 'GET'),
						($entry['url'] ?? '?'),
						($seen[$key]['verb'] ?? 'GET'),
						($seen[$key]['url'] ?? '?')
					)
				);

				$seen[$key] = $entry;
			}
		}
	}//end testEveryDeclaredRouteRegistersUnderItsOwnName()

	/**
	 * The thirteen that were overwritten are named, not counted.
	 *
	 * A count moves when anything else in the file moves. These are the
	 * addresses that answered 405 or 404 on a live instance, so each is
	 * asserted by verb and URL, and each must now carry a name of its own.
	 *
	 * @return void
	 */
	public function testTheThirteenLostAddressesAreRoutedAgain(): void {
		$entries = $this->routeFile()['routes'];

		$byAddress = [];
		foreach ($entries as $entry) {
			$byAddress[($entry['verb'] ?? 'GET') . ' ' . $entry['url']] = $this->registrationKey($entry);
		}

		$lost = [
			'PATCH /api/settings/search-backend',
			'PUT /api/settings/rbac',
			'PUT /api/settings/multitenancy',
			'PUT /api/settings/organisation',
			'PUT /api/settings/llm',
			'PUT /api/settings/files',
			'GET /api/settings/objects',
			'PUT /api/settings/objects/vectorize',
			'PUT /api/settings/audit-aggregation',
			'PUT /api/settings/retention',
			'GET /api/organisations/statistics',
			'PATCH /api/settings/archival',
			'PATCH /api/settings/edepot',
		];

		foreach ($lost as $address) {
			$this->assertArrayHasKey($address, $byAddress, sprintf('%s must still be declared', $address));

			$sharing = array_keys($byAddress, $byAddress[$address], true);
			$this->assertSame(
				[$address],
				$sharing,
				sprintf('%s must register under a name no other address shares', $address)
			);
		}
	}//end testTheThirteenLostAddressesAreRoutedAgain()

}//end class
