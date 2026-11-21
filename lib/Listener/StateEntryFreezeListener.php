<?php

/**
 * OpenRegister State Entry Freeze Listener
 *
 * Freezes an object the moment it enters a lifecycle state whose block in
 * `x-openregister-lifecycle.states` declares `freezesObject: true`, so the
 * registration data stops changing when the phase closes, not when somebody
 * remembers to click Freeze.
 *
 * THE TRANSITION HAS ALREADY HAPPENED BY THE TIME THIS RUNS, so a failure here
 * must not undo it. A freeze that could not be written is logged at error and
 * the transition stands.
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
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use DateTime;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * Freeze an object on entering a state that declares it.
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 *
 * @template-implements IEventListener<ObjectTransitionedEvent>
 */
class StateEntryFreezeListener implements IEventListener {

	/**
	 * The key on a state block that declares the freeze.
	 */
	public const FREEZES_OBJECT = 'freezesObject';

	/**
	 * The `by` recorded when no person made the move (a scheduled or automatic transition).
	 */
	public const SYSTEM_ACTOR = 'system';

	/**
	 * Constructor.
	 *
	 * @param EventSchemaLookup         $lookup     The schema, register and object tables, and the logger.
	 * @param AuditTrailMapperInterface $auditTrail Where the freeze is recorded.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly EventSchemaLookup $lookup,
		private readonly AuditTrailMapperInterface $auditTrail,
	) {
	}//end __construct()

	/**
	 * Freeze the object when the state it entered declares `freezesObject: true`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectTransitionedEvent) === false) {
			return;
		}

		$object = $event->getObject();
		if ($object->isFrozen() === true) {
			// Already frozen keeps the first freeze: who froze it, when and why.
			return;
		}

		try {
			$schema = $this->lookup->catalog->schemas()->find(id: (string)$object->getSchema());
			if (self::stateFreezes(schema: $schema, state: $event->getTo()) === false) {
				return;
			}

			$this->freeze(object: $object, schema: $schema, event: $event);
		} catch (Throwable $e) {
			// The transition already happened. A missed freeze is said out loud;
			// throwing would not undo the move and would report a failure for it.
			$this->lookup->logger->error(
				message: '[StateEntryFreezeListener] Could not freeze '
					. (string)$object->getUuid() . ' on entering "' . $event->getTo() . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}
	}//end handle()

	/**
	 * Whether the schema's block for this state declares `freezesObject: true`.
	 *
	 * A block switched off with `enabled: false` declares nothing, as for the
	 * state field rules.
	 *
	 * @param Schema $schema The object's schema.
	 * @param string $state  The state entered.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public static function stateFreezes(Schema $schema, string $state): bool {
		$lifecycle = (($schema->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($lifecycle) === false || is_array($lifecycle['states'] ?? null) === false) {
			return false;
		}

		$block = ($lifecycle['states'][$state] ?? null);
		if (is_array($block) === false || ($block['enabled'] ?? true) === false) {
			return false;
		}

		return ($block[self::FREEZES_OBJECT] ?? false) === true;
	}//end stateFreezes()

	/**
	 * Write the freeze marker naming the state, and its audit entry.
	 *
	 * @param ObjectEntity           $object The transitioned object.
	 * @param Schema                 $schema Its schema.
	 * @param ObjectTransitionedEvent $event The transition: the state entered and the actor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	private function freeze(ObjectEntity $object, Schema $schema, ObjectTransitionedEvent $event): void {
		$register = $this->lookup->catalog->registers()->find(id: (string)$object->getRegister());
		$state = $event->getTo();

		$before = clone $object;
		$object->setFrozen(
			[
				'by' => ($event->getUserId() ?? self::SYSTEM_ACTOR),
				'at' => (new DateTime())->format('c'),
				'reason' => 'Entered state "' . $state . '"',
				'state' => $state,
			]
		);

		$saved = $this->lookup->catalog->tables()->updateObjectEntity(
			entity: $object,
			register: $register,
			schema: $schema,
			oldEntity: $before
		);

		$this->auditTrail->createAuditTrail(old: $before, new: $saved, action: 'freeze');
	}//end freeze()
}//end class
