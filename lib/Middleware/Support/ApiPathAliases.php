<?php

/**
 * The versioned `/api/v1/` aliases of OpenRegister's API routes.
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
 * Serves every API route under `/api/v1/` as well as at its unversioned path,
 * which stays working and is deprecated.
 *
 * The scheme: `/api/<path>` is also served as `/api/v1/<path>`, with the same
 * controller, requirements and defaults. Routes that already carry a version
 * (`/api/v2/audit`, `/api/mcp/v1/...`) and standard protocol paths
 * ({@see StandardProtocolPaths}) get no alias and are not deprecated. A route
 * listed in {@see self::RENAMED} gets no alias of its own: its new name has one.
 *
 * @spec openspec/specs/openapi-generation/spec.md
 */
final class ApiPathAliases {

	/**
	 * The prefix of the versioned paths.
	 *
	 * @var string
	 */
	public const VERSION_PREFIX = '/api/v1';

	/**
	 * The date the unversioned paths were deprecated, as an RFC 9745 `Deprecation` value.
	 *
	 * @var string
	 */
	public const DEPRECATED_SINCE = '@1794441600';

	/**
	 * Paths whose name changed, as a pattern over the old path and its replacement.
	 *
	 * @var array<string, string>
	 */
	public const RENAMED = [
		'#^(/api/objects/[^/]+/[^/]+/[^/]+)/filesMultipart$#' => '$1/files-multipart',
	];

	/**
	 * The actions of a Nextcloud resource, as in its RouteParser.
	 *
	 * @var array<int, array{0: string, 1: string, 2: bool}>
	 */
	private const RESOURCE_ACTIONS = [
		['index', 'GET', true],
		['show', 'GET', false],
		['create', 'POST', true],
		['update', 'PUT', false],
		['destroy', 'DELETE', false],
	];

	/**
	 * The pattern of a path that already names a version.
	 *
	 * @var string
	 */
	private const VERSIONED = '#^/api/(?:[a-z0-9-]+/)?v[0-9]{1,3}(?:/|$)#i';

	/**
	 * Whether a path is an unversioned API path, which is deprecated in favour of its `/api/v1/` alias.
	 *
	 * @param string $path An app-relative path or a request path under the app root.
	 *
	 * @return bool True for a deprecated unversioned API path.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function isDeprecated(string $path): bool {
		$relative = StandardProtocolPaths::appRelative(path: $path);
		if (str_starts_with($relative, '/api/') === false || preg_match(self::VERSIONED, $relative) === 1) {
			return false;
		}

		return StandardProtocolPaths::matches(path: $relative) === false;
	}//end isDeprecated()

	/**
	 * The `/api/v1/` path that replaces a deprecated path, keeping whatever precedes the app root.
	 *
	 * @param string $path A request path, or an app-relative path.
	 *
	 * @return string|null The successor path, or null when the path is not deprecated.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function successorOf(string $path): ?string {
		if (self::isDeprecated(path: $path) === false) {
			return null;
		}

		$relative = StandardProtocolPaths::appRelative(path: $path);
		$prefix   = substr($path, 0, strlen($path) - strlen($relative));

		return $prefix . self::versioned(path: self::renamed(path: $relative));
	}//end successorOf()

	/**
	 * A routes.php declaration with an `/api/v1/` alias of every unversioned API route and resource.
	 *
	 * The aliases come after the original routes, so the originals keep their order and precedence.
	 *
	 * @param array<string, array<array-key, mixed>> $routes The declaration.
	 *
	 * @return array<string, array<array-key, mixed>> The declaration with the aliases added to `routes`.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public static function withVersionedAliases(array $routes): array {
		$aliases = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$url = '/' . ltrim((string)($route['url'] ?? ''), '/');
			if (self::isDeprecated(path: $url) === true && self::renamed(path: $url) === $url) {
				$aliases[] = array_merge($route, ['url' => self::versioned(path: $url), 'postfix' => ($route['postfix'] ?? '') . 'v1']);
			}
		}

		foreach (($routes['resources'] ?? []) as $resource => $config) {
			$aliases = array_merge($aliases, self::resourceAliases(resource: (string)$resource, url: '/' . ltrim((string)$config['url'], '/')));
		}

		$routes['routes'] = array_merge(($routes['routes'] ?? []), $aliases);

		return $routes;
	}//end withVersionedAliases()

	/**
	 * The `/api/v1/` routes of a resource's five actions.
	 *
	 * @param string $resource The resource name, which is also its controller.
	 * @param string $url      The resource's collection path.
	 *
	 * @return array<int, array<string, string>> The routes, or none when the resource is not deprecated.
	 */
	private static function resourceAliases(string $resource, string $url): array {
		if (self::isDeprecated(path: $url) === false) {
			return [];
		}

		$aliases = [];
		foreach (self::RESOURCE_ACTIONS as [$action, $verb, $onCollection]) {
			$path = self::versioned(path: $url);
			if ($onCollection === false) {
				$path .= '/{id}';
			}

			$aliases[] = ['name' => lcfirst($resource) . '#' . $action, 'url' => $path, 'verb' => $verb, 'postfix' => 'v1'];
		}

		return $aliases;
	}//end resourceAliases()

	/**
	 * The new name of a renamed path, or the path itself.
	 *
	 * @param string $path An app-relative path.
	 *
	 * @return string The current name of the path.
	 */
	private static function renamed(string $path): string {
		foreach (self::RENAMED as $pattern => $replacement) {
			$current = preg_replace($pattern, $replacement, $path);
			if (is_string($current) === true && $current !== $path) {
				return $current;
			}
		}

		return $path;
	}//end renamed()

	/**
	 * The `/api/v1/` form of an unversioned API path.
	 *
	 * @param string $path An app-relative path starting with `/api/`.
	 *
	 * @return string The versioned path.
	 */
	private static function versioned(string $path): string {
		return self::VERSION_PREFIX . substr($path, strlen('/api'));
	}//end versioned()
}//end class
