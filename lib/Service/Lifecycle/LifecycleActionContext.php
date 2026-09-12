<?php

/**
 * OpenRegister LifecycleActionContext
 *
 * Carries the name of the lifecycle transition a caller is performing, from
 * TransitionEngine into the listeners that gate and act on the save.
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
 * Which named transition is being performed on which object, right now.
 *
 * WHY THIS EXISTS
 * ---------------
 * `TransitionEngine::transition()` knows the action it was asked for, but it
 * reaches the gates only through `ObjectService::saveObject()`, which carries
 * object data and nothing else. The listeners then re-derived the transition
 * from the old and new lifecycle values and took the FIRST declared transition
 * with that from/to pair. When two transitions share a pair, a named call to
 * the second one was gated by the first one's `condition`, `authorization` and
 * `requires`, and ran the first one's `actions`. This context carries the name
 * across that seam so the named transition is the one that is judged.
 *
 * WHY A STACK PER OBJECT
 * ----------------------
 * A transition's actions or listeners can save another object, which may run
 * its own transition inside the first save. Keying by uuid keeps the two apart;
 * a stack per uuid keeps a nested transition on the SAME object from wiping the
 * outer declaration when it releases.
 *
 * 🔴 THIS MUST BE A SHARED INSTANCE. Nextcloud builds an auto-wired class fresh
 * at every injection point, so without the registration in Application the
 * engine would declare on one instance and the listeners would read an empty
 * one, and every named call would silently fall back to value matching.
 */
class LifecycleActionContext {

	/**
	 * Declared actions, a stack per object uuid.
	 *
	 * @var array<string, list<string>>
	 */
	private array $declared = [];

	/**
	 * Declare that the named transition is being performed on this object.
	 *
	 * Pair every call with release() in a `finally`, or the declaration leaks
	 * into later saves of the same object in this process.
	 *
	 * @param string $uuid The object's uuid.
	 * @param string $action The transition being performed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function declare(string $uuid, string $action): void {
		$this->declared[$uuid][] = $action;
	}//end declare()

	/**
	 * Release the innermost declaration for this object.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function release(string $uuid): void {
		if (isset($this->declared[$uuid]) === false) {
			return;
		}

		array_pop($this->declared[$uuid]);
		if ($this->declared[$uuid] === []) {
			unset($this->declared[$uuid]);
		}
	}//end release()

	/**
	 * The transition currently declared for this object, if any.
	 *
	 * @param string $uuid The object's uuid.
	 *
	 * @return string|null The innermost declared action, or null when none.
	 *
	 * @spec openspec/changes/lifecycle-declarative-conditions/specs/object-lifecycle/spec.md
	 */
	public function declaredFor(string $uuid): ?string {
		$stack = ($this->declared[$uuid] ?? []);
		if ($stack === []) {
			return null;
		}

		return $stack[array_key_last($stack)];
	}//end declaredFor()
}//end class
