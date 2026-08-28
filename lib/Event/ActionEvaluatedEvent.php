<?php

/**
 * OpenRegister ActionEvaluatedEvent
 *
 * Dispatched every time a permission decision is reached — for EVERY action,
 * canonical or declared, and for refusals as well as grants.
 *
 * The refusal half is the point. An event that fires only on success can say
 * what happened but never "who tried", which is exactly the question an audit
 * rule exists to answer. Both verdicts are carried on the same event so a
 * listener cannot accidentally subscribe to only the flattering half.
 *
 * ⚠️ This is TELEMETRY, dispatched AFTER the verdict is final. Listeners
 * observe; they cannot vote. A listener that throws is caught and logged, and
 * the verdict is unaffected — audit must never be able to change an access
 * decision, in either direction. To CONTRIBUTE a verdict for a declared action,
 * use {@see CustomScopeEvaluatingEvent} instead.
 *
 * ⚠️ It marks a DECISION, not an attempt. PermissionHandler memoises verdicts
 * per request, so repeated identical checks within one request produce one
 * event, not one per call. A listener counting attempts will under-count; a
 * listener recording decisions is exact.
 *
 * No listener is registered by default. Volume is opt-in, deliberately: actions
 * fire per object operation and a bulk import is loud.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Telemetry event fired after any action's permission decision is reached.
 */
class ActionEvaluatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param Schema            $schema  Schema the action was evaluated against.
	 * @param string            $action  The action verb — canonical or declared.
	 * @param bool              $granted The verdict. False is as interesting as true.
	 * @param string|null       $actor   The acting user's UID, or null for an anonymous principal.
	 * @param ObjectEntity|null $object  The object under evaluation, when the decision concerned one.
	 */
	public function __construct(
		private readonly Schema $schema,
		private readonly string $action,
		private readonly bool $granted,
		private readonly ?string $actor,
		private readonly ?ObjectEntity $object = null,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Schema the action was evaluated against.
	 *
	 * @return Schema The schema.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()

	/**
	 * The register the object belongs to, when the decision concerned one.
	 *
	 * ⚠️ Null for a schema-level decision, and that is not a lookup failure. A
	 * schema can belong to several registers, so without an object there is no
	 * single correct answer — reporting one would be a guess a listener could
	 * not distinguish from a fact.
	 *
	 * @return string|int|null The register id, or null when not determinable.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getRegister(): string|int|null {
		return $this->object?->getRegister();
	}//end getRegister()

	/**
	 * The action verb that was evaluated.
	 *
	 * @return string The action — one of the canonical verbs, or one the schema declared.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getAction(): string {
		return $this->action;
	}//end getAction()

	/**
	 * The object under evaluation, when there was one.
	 *
	 * @return ObjectEntity|null The object, or null for a schema-level decision.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getObject(): ?ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * The object's UUID, when the decision concerned an object.
	 *
	 * @return string|null The object UUID, or null for a schema-level decision.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getObjectId(): ?string {
		return $this->object?->getUuid();
	}//end getObjectId()

	/**
	 * The acting user.
	 *
	 * @return string|null The user's UID, or null for an anonymous principal.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function getActor(): ?string {
		return $this->actor;
	}//end getActor()

	/**
	 * Whether the action was permitted.
	 *
	 * @return bool True when granted, false when refused.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function isGranted(): bool {
		return $this->granted;
	}//end isGranted()

	/**
	 * Whether the action was refused — the half an audit listener wants.
	 *
	 * @return bool True when refused.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function isRefused(): bool {
		return ($this->granted === false);
	}//end isRefused()
}//end class
