<?php

/**
 * OpenRegister LifecycleWriteBoundary
 *
 * The two ambient collaborators a lifecycle write carries, held together so
 * TransitionEngine asks for an outcome instead of orchestrating them itself.
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

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * What a lifecycle write asks of its ambient frame, in one place.
 *
 * TransitionEngine performs one write per named or graph transition, but two
 * unrelated things must happen around it: the automatic-transition pass needs
 * to know a write is in flight so a rule-driven move can be attributed and
 * drained, and the listeners need to be told which named action is being
 * applied so they judge the transition that was asked for rather than the
 * first one that happens to share its from/to pair. Both are ambient,
 * request-scoped collaborators the engine only ever asks questions of; it
 * never inspects their internals. Bundling them here means the engine
 * constructor carries one collaborator instead of two, and the boundary
 * mechanics (`finally`, null-safety) live next to each other instead of
 * being duplicated at every call site.
 *
 * Not `final` for no reason of its own — nothing here doubles a class the way
 * TransitionEngine is doubled in TransitionControllerTest — but sealed
 * because nothing needs to extend it.
 */
final class LifecycleWriteBoundary {

	/**
	 * Constructor.
	 *
	 * @param LifecycleActionContext $actions Names the transition being applied, for the listeners.
	 * @param AutoTransitionPass|null $pass The request-scoped automatic-transition pass. Nullable
	 *                          with a null default so the engine's existing unit tests keep building
	 *                          it without wiring the pass; the container resolves the real, shared
	 *                          instance by type.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleActionContext $actions,
		private readonly ?AutoTransitionPass $pass = null,
	) {
	}//end __construct()

	/**
	 * Open the automatic-transition boundary around a write, and answer with
	 * whichever entity is current once it closes.
	 *
	 * OPENS THE BOUNDARY around the whole write, not around its save: the
	 * write's own `ObjectTransitionedEvent` is dispatched after the save
	 * returns, and a listener must see the named move before any automatic
	 * move that followed from it. Leaving the OUTERMOST boundary drains, so
	 * the entity answered here is the one the last automatic move produced,
	 * when there was one — never the write's own result once a later
	 * automatic move has superseded it.
	 *
	 * The drain runs in a `finally`: that is deliberate and load-bearing. A
	 * frame left pushed after an exception would attribute later, unrelated
	 * writes to a move that never finished.
	 *
	 * @param callable(): ObjectEntity $write Performs the write; called exactly once.
	 *
	 * @return ObjectEntity The object in its final state: the write's own
	 *                      result, or an automatic move that followed from it.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function around(callable $write): ObjectEntity {
		$this->pass?->enter();
		$automatic = [];

		try {
			$result = $write();
		} finally {
			$automatic = ($this->pass?->leave() ?? []);
		}

		return $this->preferAutomatic(saved: $result, applied: $automatic);
	}//end around()

	/**
	 * Declare the named action for a uuid around a write, and release it
	 * unconditionally afterward.
	 *
	 * Names the transition for the listeners. They see only object data and
	 * would otherwise pick the first transition with a matching from/to pair,
	 * judging and acting on a twin rather than the action asked for.
	 *
	 * Release happens in a `finally` so a refused write (the save throws)
	 * never leaves the declaration in place: a leaked declaration would make
	 * a later, unrelated save of the same object be judged as this action.
	 *
	 * @param string $uuid The object's uuid.
	 * @param string $action The transition being performed.
	 * @param callable(): ObjectEntity $write Performs the write; called exactly once.
	 *
	 * @return ObjectEntity Whatever $write returned.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function declaringAction(string $uuid, string $action, callable $write): ObjectEntity {
		$this->actions->declare(uuid: $uuid, action: $action);
		try {
			return $write();
		} finally {
			$this->actions->release(uuid: $uuid);
		}
	}//end declaringAction()

	/**
	 * The automatic transition being applied right now, if any.
	 *
	 * Read from the pass's ambient frame, never from a parameter a caller
	 * could set: a caller that could pass `automatic: true` could claim a
	 * move a person asked for was made by a rule.
	 *
	 * @return string|null The transition's name, or null for a manual move
	 *                      or when no pass is wired.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function applyingAction(): ?string {
		return $this->pass?->applyingAction();
	}//end applyingAction()

	/**
	 * Choose between a write's own result and an automatic move that followed from it.
	 *
	 * Null-safe: with no pass wired, `$applied` is always empty and the
	 * write's own result is returned unchanged.
	 *
	 * @param ObjectEntity $saved The entity the write itself produced.
	 * @param array<string, ObjectEntity> $applied What the drain applied, by uuid.
	 *
	 * @return ObjectEntity The object in its final state.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function preferAutomatic(ObjectEntity $saved, array $applied): ObjectEntity {
		if ($this->pass === null) {
			return $saved;
		}

		return $this->pass->preferAutomatic(saved: $saved, applied: $applied);
	}//end preferAutomatic()
}//end class
