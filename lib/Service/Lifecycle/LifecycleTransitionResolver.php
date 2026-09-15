<?php

/**
 * OpenRegister LifecycleTransitionResolver
 *
 * Decides which declared transition a lifecycle-field change is.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Lifecycle;

/**
 * Resolves an old-to-new lifecycle change to one declared transition.
 *
 * One implementation for every listener that needs the answer. There were two
 * identical private loops before, in LifecycleValidationListener and
 * LifecycleActionListener, and a fix to one would have left the other judging
 * a different transition than the one whose actions it ran.
 *
 * THE RULE
 * --------
 * 1. When TransitionEngine has declared a named action for this object, and
 *    that transition really does move `old` to `new`, it is the answer.
 * 2. Otherwise the first declared transition whose `to` is `new` and whose
 *    `from` holds `old`, which is the long-standing behaviour for a direct
 *    edit of the lifecycle field, where no action was named.
 *
 * A declared action that does NOT match the edit is ignored rather than
 * trusted. The declaration only says which of several equally valid
 * transitions the caller meant; it must never make an undeclared move legal.
 */
class LifecycleTransitionResolver {

	/**
	 * Constructor.
	 *
	 * @param LifecycleActionContext $context The named action being performed, if any.
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleActionContext $context,
	) {
	}//end __construct()

	/**
	 * The (action, spec) pair this change is, or null when none allows it.
	 *
	 * @param array<string, mixed> $transitions The annotation's transition map.
	 * @param string $uuid The object's uuid, for the declared-action lookup.
	 * @param string $oldValue The lifecycle value being moved away from.
	 * @param string $newValue The lifecycle value being moved to.
	 *
	 * @return array{0: string, 1: array<string, mixed>}|null
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function resolve(array $transitions, string $uuid, string $oldValue, string $newValue): ?array {
		$declared = $this->context->declaredFor(uuid: $uuid);
		if ($declared !== null) {
			$spec = ($transitions[$declared] ?? null);
			if (is_array($spec) === true && $this->moves(spec: $spec, oldValue: $oldValue, newValue: $newValue) === true) {
				return [$declared, $spec];
			}
		}

		foreach ($transitions as $action => $spec) {
			if (is_array($spec) === true && $this->moves(spec: $spec, oldValue: $oldValue, newValue: $newValue) === true) {
				return [(string)$action, $spec];
			}
		}

		return null;
	}//end resolve()

	/**
	 * Whether a transition moves the lifecycle value from `old` to `new`.
	 *
	 * `from` may be a single state or a list; a string is read as a one-element
	 * list so both authoring shapes work.
	 *
	 * @param array<string, mixed> $spec The transition's spec.
	 * @param string $oldValue The lifecycle value being moved away from.
	 * @param string $newValue The lifecycle value being moved to.
	 *
	 * @return bool
	 */
	private function moves(array $spec, string $oldValue, string $newValue): bool {
		if (($spec['to'] ?? null) !== $newValue) {
			return false;
		}

		$from = ($spec['from'] ?? []);
		if (is_string($from) === true) {
			$from = [$from];
		}

		return is_array($from) === true && in_array($oldValue, $from, true) === true;
	}//end moves()
}//end class
