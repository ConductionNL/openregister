<?php

/**
 * OpenRegister LifecycleActionListener
 *
 * Subscribes to ObjectUpdatingEvent and runs the `actions[]` block a schema
 * declares on the matched lifecycle transition. Because it hooks the save path
 * — which every mutation goes through, including a plain list-form edit of the
 * lifecycle field — the declared actions run REGARDLESS of transition form.
 * This is the fix for the bypass in issue #427: list-form transitions never
 * went through `TransitionEngine`, so declared actions never ran for them
 * (observed on shillinq `LeaseContract`).
 *
 * Mirrors `Listener\ApprovalChainGateListener`'s schema-parse-off-`getConfiguration()`
 * and transition-matching shape, and `Listener\CalculationOnSaveListener`'s
 * mutate-the-payload-before-persistence approach (`$object->setObject()`).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
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

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionExecutorInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionPayload;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Runs declared lifecycle actions on the save path, for every transition form.
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class LifecycleActionListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param LifecycleEditReader              $edits    Reads the edit and the transition it amounts to.
	 * @param LifecycleActionExecutorInterface $executor Runs the transition's declared actions.
	 * @param LoggerInterface                  $logger   Logger for diagnostics.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly LifecycleEditReader $edits,
		private readonly LifecycleActionExecutorInterface $executor,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run declared actions for the attempted lifecycle transition.
	 *
	 * A prior listener (transition legality via LifecycleValidationListener, or an
	 * approval gate) may have rejected or blocked the transition. It is then not
	 * happening, so its actions must not run.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatingEvent) === true && $event->isPropagationStopped() === false) {
			$this->runDeclaredActions(event: $event);
		}
	}//end handle()

	/**
	 * Run the actions of the transition this update amounts to, if any.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return void
	 */
	private function runDeclaredActions(ObjectUpdatingEvent $event): void {
		$attempt = $this->attemptOf(event: $event);
		if ($attempt === null) {
			return;
		}

		$actions = ($attempt->spec['actions'] ?? null);
		if (is_array($actions) === true && $actions !== []) {
			$this->run(attempt: $attempt, actions: $actions);
		}
	}//end runDeclaredActions()

	/**
	 * The transition this update amounts to, or null when it moves no declared state.
	 *
	 * An edit no transition allows is LifecycleValidationListener's to reject; there
	 * is nothing for the executor to run.
	 *
	 * @param ObjectUpdatingEvent $event The update.
	 *
	 * @return LifecycleAttempt|null
	 */
	private function attemptOf(ObjectUpdatingEvent $event): ?LifecycleAttempt {
		$edit = $this->edits->editOf(event: $event);
		if ($edit === null || $this->movesADeclaredState(edit: $edit) === false) {
			return null;
		}

		return $this->edits->attemptOf(edit: $edit);
	}//end attemptOf()

	/**
	 * Whether the schema declares a field and transitions and this edit moves the field to a text value.
	 *
	 * @param LifecycleEdit $edit The edit.
	 *
	 * @return bool
	 */
	private function movesADeclaredState(LifecycleEdit $edit): bool {
		return $edit->declaration->field() !== ''
			&& $edit->declaration->hasTransitions() === true
			&& $edit->changesStateToText() === true;
	}//end movesADeclaredState()

	/**
	 * Run a transition's actions over the incoming data.
	 *
	 * Fail-loud by design: a missing handler or unparseable condition throws out of
	 * the executor and is not caught here. The exception propagates to the save path
	 * (HookStoppedException / 500), aborting the transition rather than silently
	 * dropping the declared action.
	 *
	 * @param LifecycleAttempt  $attempt The matched transition.
	 * @param array<int, mixed> $actions Its declared actions.
	 *
	 * @return void
	 */
	private function run(LifecycleAttempt $attempt, array $actions): void {
		$newData = $attempt->edit->getNewData();
		$result  = $this->executor->run(
			actions: $actions,
			payload: new TransitionPayload(transition: $attempt->action, objectData: $newData, previousData: $attempt->edit->getOldData())
		);

		if ($result !== $newData) {
			$attempt->edit->object()->setObject($result);
		}

		$this->logger->debug(
			sprintf('[LifecycleActionListener] executed %d action(s) for transition "%s".', count($actions), $attempt->action)
		);
	}//end run()
}//end class
