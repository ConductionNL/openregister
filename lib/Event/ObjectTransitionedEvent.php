<?php

/**
 * OpenRegister ObjectTransitionedEvent
 *
 * Dispatched after a successful state-machine transition on an object. Joins
 * the existing event-driven-architecture family — listeners subscribe via
 * `IEventDispatcher` like every other Object*Event in OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
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

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Lifecycle\TransitionActor;
use OCA\OpenRegister\Service\Lifecycle\TransitionMove;
use OCP\EventDispatcher\Event;

/**
 * Fired after an object's lifecycle field has been updated through a
 * declared transition (per `x-openregister-lifecycle`).
 *
 * Carries the object plus the transition metadata so listeners (notifications,
 * cascades, calculation re-materialisation, audit enrichment) can react
 * without inferring the action from the generic ObjectUpdatedEvent.
 */
class ObjectTransitionedEvent extends Event {

	/**
	 * The acting user and whether a rule made the move.
	 *
	 * @var TransitionActor
	 */
	private readonly TransitionActor $actor;

	/**
	 * Capture the post-transition state for downstream listeners.
	 *
	 * @param ObjectEntity         $object Object after the transition.
	 * @param TransitionMove       $move   The action applied and the states it moved between.
	 * @param RegisterSchemaRef    $scope  Register and schema slugs of the object.
	 * @param TransitionActor|null $actor  Who made the move; null for a system-applied,
	 *                                     non-automatic transition.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ObjectEntity $object,
		private readonly TransitionMove $move,
		private readonly RegisterSchemaRef $scope,
		?TransitionActor $actor = null,
	) {
		parent::__construct();
		$this->actor = $actor ?? new TransitionActor();
	}//end __construct()

	/**
	 * Whether a rule made this move rather than a caller asking for it.
	 *
	 * An auditor reading a trail needs to tell a user's click from a rule
	 * firing on that user's save. Both are attributed to the same user, because
	 * an automatic move acts as the identity whose write triggered it.
	 *
	 * @return bool True when the move was made by a transition's `autoWhen`.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function isAutomatic(): bool {
		return $this->actor->automatic;
	}//end isAutomatic()

	/**
	 * Read the object after the transition.
	 *
	 * @return ObjectEntity Object whose lifecycle just changed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * Read the action name from the transition table.
	 *
	 * @return string Action name (e.g. "publish").
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getAction(): string {
		return $this->move->action;
	}//end getAction()

	/**
	 * Read the lifecycle value before the transition.
	 *
	 * @return string Originating state value.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getFrom(): string {
		return $this->move->from;
	}//end getFrom()

	/**
	 * Read the lifecycle value after the transition.
	 *
	 * @return string Destination state value.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getTo(): string {
		return $this->move->to;
	}//end getTo()

	/**
	 * Read the caller uid that triggered the transition.
	 *
	 * @return string|null Caller uid, or null for system transitions.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getUserId(): ?string {
		return $this->actor->userId;
	}//end getUserId()

	/**
	 * Read the register slug the object belongs to.
	 *
	 * @return string Register slug.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getRegister(): string {
		return (string) $this->scope->register;
	}//end getRegister()

	/**
	 * Read the schema slug the object belongs to.
	 *
	 * @return string Schema slug.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-2
	 */
	public function getSchema(): string {
		return (string) $this->scope->schema;
	}//end getSchema()
}//end class
