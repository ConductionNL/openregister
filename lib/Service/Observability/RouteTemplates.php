<?php

/**
 * Maps route keys to the URL templates of appinfo/routes.php for the endpoint label.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

/**
 * Turns a route key (`objects.index`) into its URL template (`/api/objects/{register}/{schema}`).
 *
 * The request metrics record the route, not the path, so the `endpoint`
 * label has one value per route instead of one per object id. Keys follow
 * Nextcloud's route names without the app prefix: `<controller>.<action>`
 * plus the route's postfix for `routes`, and the lower-cased resource name
 * for `resources`. A key without a template keeps the key itself.
 *
 * @spec openspec/changes/production-observability
 */
class RouteTemplates {

	/**
	 * The actions of a resource: action and whether it acts on the collection.
	 */
	private const RESOURCE_ACTIONS = [
		['index', true],
		['show', false],
		['create', true],
		['update', false],
		['destroy', false],
	];

	/**
	 * The key → template map, loaded on first use.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $map = null;

	/**
	 * A RouteTemplates over a given route table instead of appinfo/routes.php.
	 *
	 * @param array<string, mixed> $routes The route table.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/production-observability
	 */
	public static function forRoutes(array $routes): self {
		$templates      = new self();
		$templates->map = self::mapOf(routes: $routes);

		return $templates;
	}//end forRoutes()

	/**
	 * The endpoint label of a route key.
	 *
	 * @param string $route The route key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability
	 */
	public function endpointOf(string $route): string {
		if ($this->map === null) {
			$this->map = self::mapOf(routes: self::appRoutes());
		}

		return $this->map[$route] ?? $route;
	}//end endpointOf()

	/**
	 * The key → template map of a route table.
	 *
	 * @param array<string, mixed> $routes The route table (`routes` and `resources`).
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public static function mapOf(array $routes): array {
		$map = [];
		foreach (($routes['routes'] ?? []) as $route) {
			$name = (string) ($route['name'] ?? '');
			if (substr_count($name, '#') !== 1) {
				continue;
			}

			$key = str_replace('#', '.', $name).(string) ($route['postfix'] ?? '');
			$map[$key] ??= '/'.ltrim((string) ($route['url'] ?? ''), '/');
		}

		foreach (($routes['resources'] ?? []) as $resource => $config) {
			$url = '/'.ltrim((string) ($config['url'] ?? ''), '/');
			foreach (self::RESOURCE_ACTIONS as [$action, $onCollection]) {
				$template = $url;
				if ($onCollection === false) {
					$template .= '/{id}';
				}

				$map[strtolower((string) $resource).'.'.$action] ??= $template;
			}
		}

		return $map;
	}//end mapOf()

	/**
	 * OpenRegister's route table, empty when it cannot be read.
	 *
	 * @return array<string, mixed>
	 */
	private static function appRoutes(): array {
		$file = dirname(__DIR__, 3).'/appinfo/routes.php';
		if (is_file($file) === false) {
			return [];
		}

		$routes = require $file;
		if (is_array($routes) === false) {
			return [];
		}

		return $routes;
	}//end appRoutes()
}//end class
