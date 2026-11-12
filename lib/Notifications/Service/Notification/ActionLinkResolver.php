<?php

/**
 * ActionLinkResolver: turns a rule's declared actions into concrete links.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notifications\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Resolves `actions[]` of a notification rule to server-side deeplinks.
 *
 * Targets that cannot be resolved (e.g. an unreadable relation object) are
 * dropped, never leaked.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class ActionLinkResolver {
	/**
	 * Constructor
	 *
	 * @param AppRouteBase      $routes  The base of a route target.
	 * @param TaskActionLinks   $tasks   Resolves `task-verb` targets.
	 * @param ObjectDetailLinks $objects Resolves `object-detail` targets.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AppRouteBase $routes,
		private readonly TaskActionLinks $tasks,
		private readonly ObjectDetailLinks $objects,
	) {
	}//end __construct()

	/**
	 * Resolve declared `actions[]` to concrete, server-side deeplinks.
	 *
	 * Each returned action carries the i18n `label` map, the `primary` flag,
	 * and a resolved absolute `url`.
	 *
	 * @param array<string, mixed> $spec    The notification rule spec.
	 * @param ActionTargetContext  $context What the targets resolve against.
	 *
	 * @return array<int, array{label: array<string,string>, primary: bool, url: string, method: string}>
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	public function resolve(array $spec, ActionTargetContext $context): array {
		$declared = ($spec['actions'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$resolved = [];
		foreach ($declared as $action) {
			$link = $this->actionLink(action: $action, context: $context);
			if ($link !== null) {
				$resolved[] = $link;
			}
		}

		return $resolved;
	}//end resolve()

	/**
	 * One declared action as a link, or null when it has no usable target.
	 *
	 * @param mixed               $action  The declared action.
	 * @param ActionTargetContext $context What the target resolves against.
	 *
	 * @return array{label: array<string,string>, primary: bool, url: string, method: string}|null
	 */
	private function actionLink(mixed $action, ActionTargetContext $context): array|null {
		if (is_array($action) === false) {
			return null;
		}

		$url = $this->targetUrl(target: ($action['target'] ?? []), context: $context);
		if ($url === null || $url === '') {
			// Drop the action rather than emit a dead or leaking button.
			return null;
		}

		return $this->described(action: $action, url: $url);
	}//end actionLink()

	/**
	 * What a button needs: its label, whether it is primary, its link and method.
	 *
	 * @param array<string, mixed> $action The declared action.
	 * @param string               $url    The resolved link.
	 *
	 * @return array{label: array<string,string>, primary: bool, url: string, method: string}
	 */
	private function described(array $action, string $url): array {
		return [
			'label' => self::labelOf(action: $action),
			'primary' => (bool)($action['primary'] ?? false),
			'url' => $url,
			'method' => $this->tasks->method(target: ($action['target'] ?? [])),
		];
	}//end described()

	/**
	 * The label map of an action.
	 *
	 * @param array<string, mixed> $action The declared action.
	 *
	 * @return array<string, string>
	 */
	private static function labelOf(array $action): array {
		$label = ($action['label'] ?? []);
		if (is_array($label) === false) {
			return [];
		}

		return $label;
	}//end labelOf()

	/**
	 * Resolve a single action `target` to an absolute deeplink.
	 *
	 * Supported kinds:
	 *  - object-detail: deeplink to the triggering object's detail page.
	 *  - object-detail + { object: { kind: "relation", field } }: resolve the
	 *    relation field on the triggering object to a related object's
	 *    register/schema/uuid THROUGH OR RBAC ("Open client"): the related
	 *    id is never trusted from the wire.
	 *  - route: an originApp frontend route with {{prop}} interpolation (HTML-escaped).
	 *  - url: an absolute web or mail URL, passed through verbatim.
	 *  - task-verb: the task lifecycle verb route (POST), or the task form
	 *    when the outcome requires a comment.
	 *
	 * @param mixed               $target  The raw target spec.
	 * @param ActionTargetContext $context What the target resolves against.
	 *
	 * @return string|null The resolved URL, or null when unresolvable.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function targetUrl(mixed $target, ActionTargetContext $context): string|null {
		if (is_array($target) === false) {
			return null;
		}

		return match ((string)($target['kind'] ?? '')) {
			'task-verb' => $this->tasks->url(target: $target, context: $context),
			'url' => (new LinkableUrl())->orNull(href: (string)($target['href'] ?? '')),
			'route' => $this->routeUrl(target: $target, context: $context),
			'object-detail' => $this->objects->url(target: $target, context: $context),
			default => null,
		};
	}//end targetUrl()

	/**
	 * The URL of a frontend route of the rule's app, or of the app it names.
	 *
	 * @param array<string, mixed> $target  The route target.
	 * @param ActionTargetContext  $context What the target resolves against.
	 *
	 * @return string|null
	 */
	private function routeUrl(array $target, ActionTargetContext $context): string|null {
		$route = (string)($target['route'] ?? '');
		if ($route === '') {
			return null;
		}

		$app = (string)($target['app'] ?? $context->originApp);

		return $this->routes->forApp(app: $app) . ltrim(self::interpolated(route: $route, data: $context->data), '/');
	}//end routeUrl()

	/**
	 * A route with its {{prop}} tokens replaced by object data, HTML-escaped.
	 *
	 * @param string               $route The route.
	 * @param array<string, mixed> $data  The object data.
	 *
	 * @return string
	 */
	private static function interpolated(string $route, array $data): string {
		return (string)preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
			static function (array $match) use ($data): string {
				$value = ($data[$match[1]] ?? '');
				if (is_scalar($value) === false) {
					return '';
				}

				return rawurlencode(htmlspecialchars((string)$value, (ENT_QUOTES | ENT_HTML5)));
			},
			$route
		);
	}//end interpolated()
}//end class
