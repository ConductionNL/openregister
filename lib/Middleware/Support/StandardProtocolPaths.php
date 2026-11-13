<?php

/**
 * The API paths that speak a protocol defined outside OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware\Support;

/**
 * Recognises the API paths whose request and error formats a standard or a
 * peer instance defines, so OpenRegister's own API conventions leave them alone.
 *
 * Paths are relative to the app root (`/api/...`); a full request path such as
 * `/apps/openregister/api/mcp` is accepted too.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final class StandardProtocolPaths {

	/**
	 * The app root that precedes every app-relative path in a request path.
	 *
	 * @var string
	 */
	private const APP_ROOT = '/apps/openregister';

	/**
	 * One pattern per protocol, matched against the app-relative path.
	 *
	 * @var array<string, string>
	 */
	public const PATTERNS = [
		'MCP (JSON-RPC 2.0) and its discovery' => '#^/api/mcp(?:/|$)#',
		'GraphQL'                              => '#^/api/graphql(?:/|$)#',
		'OGC Web Feature Service'              => '#^/api/geo/[^/]+/[^/]+/wfs$#',
		'iCalendar feeds'                      => '#^/api/public/calendar-feeds/#',
		'Prometheus metrics'                   => '#^/api/metrics$#',
		'Health check'                         => '#^/api/health$#',
		'Federation between instances'         => '#^/api/federation/#',
	];

	/**
	 * Whether a path speaks a protocol defined outside OpenRegister.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return bool True for a standard protocol path.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function matches(string $path): bool {
		$relative = self::appRelative(path: $path);
		foreach (self::PATTERNS as $pattern) {
			if (preg_match($pattern, $relative) === 1) {
				return true;
			}
		}

		return false;
	}//end matches()

	/**
	 * The path relative to the app root, leading slash included.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return string The app-relative path.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function appRelative(string $path): string {
		$candidate = '/' . ltrim($path, '/');
		$rootAt    = strpos($candidate, self::APP_ROOT . '/');
		if ($rootAt === false) {
			return $candidate;
		}

		return substr($candidate, $rootAt + strlen(self::APP_ROOT));
	}//end appRelative()
}//end class
