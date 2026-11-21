<?php

/**
 * TaskActionLinks: the link and method of a `task-verb` action.
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

use Closure;
use OCP\IURLGenerator;

/**
 * Resolves a `task-verb` target to the verb route or the task form.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
 */
final class TaskActionLinks {
	/**
	 * Constructor
	 *
	 * @param Closure            $isRejecting  Whether an outcome needs a comment: fn (string): bool.
	 * @param IURLGenerator|null $urlGenerator URL generator for the task routes.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly Closure $isRejecting,
		private readonly ?IURLGenerator $urlGenerator = null,
	) {
	}//end __construct()

	/**
	 * The HTTP method a resolved action is delivered with.
	 *
	 * Only a `task-verb` target is a state change. Even then, a verb whose
	 * outcome requires a mandatory comment (a rejecting outcome) resolves to
	 * the task form instead, which is a navigation and therefore GET.
	 *
	 * @param mixed $target The raw target spec.
	 *
	 * @return string `POST` or `GET`.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	public function method(mixed $target): string {
		if (is_array($target) === false || (string)($target['kind'] ?? '') !== 'task-verb') {
			return 'GET';
		}

		if ($this->needsTaskForm(target: $target) === true) {
			return 'GET';
		}

		return 'POST';
	}//end method()

	/**
	 * Whether a `task-verb` action must open the form rather than act directly.
	 *
	 * `flow-tasks` refuses a rejecting or returning outcome without a
	 * comment, so a button for such an outcome cannot complete the task by
	 * itself; it opens the surface that collects the comment.
	 *
	 * @param array<string, mixed> $target The task-verb target.
	 *
	 * @return bool True when the form must be opened.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	private function needsTaskForm(array $target): bool {
		$outcome = ($target['outcome'] ?? null);
		if (is_string($outcome) === false) {
			return false;
		}

		return ($this->isRejecting)($outcome);
	}//end needsTaskForm()

	/**
	 * Resolve a `task-verb` target to the verb route, or to the task form
	 * when the outcome needs a comment.
	 *
	 * The uuid comes from the adapter payload (`taskUuid`) or the entity
	 * uuid; the verb is validated against the closed list by the validator,
	 * and the route it lands on is authorized by TaskAuthorizationService
	 * identically to any other caller. The notification is a transport,
	 * never a bypass.
	 *
	 * @param array<string, mixed> $target  The task-verb target.
	 * @param ActionTargetContext  $context What the target resolves against.
	 *
	 * @return string|null The absolute URL, or null when unresolvable (no url generator, no uuid).
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-a-binary-decision-is-decidable-from-the-notification
	 */
	public function url(array $target, ActionTargetContext $context): string|null {
		$uuid = self::taskUuid(context: $context);
		$verb = (string)($target['verb'] ?? '');
		if ($this->urlGenerator === null || $uuid === '' || preg_match('/^[a-z]+$/', $verb) !== 1) {
			return null;
		}

		if ($this->needsTaskForm(target: $target) === true) {
			return $this->taskRoute(route: 'openregister.task.open', uuid: $uuid);
		}

		$url = $this->taskRoute(route: 'openregister.task.' . $verb, uuid: $uuid);

		return self::withOutcome(url: $url, outcome: ($target['outcome'] ?? null));
	}//end url()

	/**
	 * The task of an action: the adapter's `taskUuid`, else the triggering object.
	 *
	 * @param ActionTargetContext $context What the target resolves against.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	private static function taskUuid(ActionTargetContext $context): string {
		return (string)($context->data['taskUuid'] ?? ($context->object->getUuid() ?? ''));
	}//end taskUuid()

	/**
	 * A verb route with the declared outcome in its query.
	 *
	 * @param string|null $url     The route, null when it does not exist.
	 * @param mixed       $outcome The declared outcome.
	 *
	 * @return string|null
	 */
	private static function withOutcome(string|null $url, mixed $outcome): string|null {
		if ($url === null || is_string($outcome) === false || $outcome === '') {
			return $url;
		}

		return $url . '?outcome=' . rawurlencode($outcome);
	}//end withOutcome()

	/**
	 * The absolute URL of a task route, or null when the route does not exist.
	 *
	 * @param string $route The route name.
	 * @param string $uuid  The task uuid.
	 *
	 * @return string|null
	 */
	private function taskRoute(string $route, string $uuid): string|null {
		try {
			return $this->urlGenerator->linkToRouteAbsolute($route, ['uuid' => $uuid]);
		} catch (\Throwable) {
			return null;
		}
	}//end taskRoute()
}//end class
