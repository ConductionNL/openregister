<?php

/**
 * OpenRegister DeclaredRoutes
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Middleware\Support\ApiPathAliases;

/**
 * The routes appinfo/routes.php declares, without the `/api/v1/` aliases that
 * ApiPathAliases adds to every unversioned API route.
 */
final class DeclaredRoutes {

	/**
	 * The declared routes, in declaration order, without their versioned aliases.
	 *
	 * @return array<int, array<string, mixed>> The routes.
	 */
	public static function unversioned(): array {
		$declared = require dirname(__DIR__, 3) . '/appinfo/routes.php';

		return array_values(array_filter(
			($declared['routes'] ?? []),
			static fn (array $route): bool => str_starts_with('/' . ltrim((string)($route['url'] ?? ''), '/'), ApiPathAliases::VERSION_PREFIX . '/') === false
		));
	}//end unversioned()
}//end class
