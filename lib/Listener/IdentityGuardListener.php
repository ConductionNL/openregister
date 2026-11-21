<?php

/**
 * OpenRegister IdentityGuardListener
 *
 * Once an object exists, the values of its schema's identity do not change
 * through an ordinary write: other software addresses the record by them.
 * The audited schema migration path is the one way to rewrite them.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Schemas\IdentityRewriteScope;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Refuses an update that changes an identity property, naming the property.
 *
 * The refusal stops the update event, which the write path turns into a 422.
 * A value the stored object does not carry yet may be filled in: an object
 * from before the identity was declared must be able to get its key.
 *
 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
 *
 * @template-implements IEventListener<ObjectUpdatingEvent>
 */
class IdentityGuardListener implements IEventListener {

	/**
	 * The error code of the refusal, so a caller can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'identity-property-changed';

	/**
	 * Constructor.
	 *
	 * @param UniqueConstraintEvaluatorInterface $evaluator Reads the schema's identity.
	 * @param SchemaMapperInterface              $schemas   Resolves the schema an object belongs to.
	 */
	public function __construct(
		private readonly UniqueConstraintEvaluatorInterface $evaluator,
		private readonly SchemaMapperInterface $schemas,
	) {
	}//end __construct()

	/**
	 * Refuse the update when it changes an identity value.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/modelling-composite-identity/specs/objects-crud/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false || IdentityRewriteScope::isActive() === true) {
			return;
		}

		$old = $event->getOldObject();
		$new = $event->getNewObject();
		if ($old === null || $new->isSoftDeleted() === true) {
			return;
		}

		$changed = $this->changedProperty(old: $old, new: $new);
		if ($changed === null) {
			return;
		}

		$event->setErrors(
			[
				'code' => self::ERROR_CODE,
				'message' => sprintf(
					'The identity property %s cannot change once the object exists; it is changed only by a schema migration.',
					$changed['property']
				),
				'property' => $changed['property'],
				'constraint' => $changed['constraint'],
			]
		);
		$event->stopPropagation();
	}//end handle()

	/**
	 * The first identity property whose stored value the update changes, or null.
	 *
	 * @param ObjectEntity $old The stored object.
	 * @param ObjectEntity $new The object the update writes.
	 *
	 * @return array{property:string,constraint:string}|null The changed property and its constraint.
	 */
	private function changedProperty(ObjectEntity $old, ObjectEntity $new): ?array {
		$reference = (string)$old->getSchema();
		if ($reference === '') {
			return null;
		}

		$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		$identity = $this->evaluator->identity(configuration: $schema->getConfiguration());
		if ($identity === null) {
			return null;
		}

		$before = ($old->getObject() ?? []);
		$after = ($new->getObject() ?? []);
		foreach ($identity['properties'] as $property) {
			if ($this->changes(stored: ($before[$property] ?? null), written: ($after[$property] ?? null)) === true) {
				return ['property' => $property, 'constraint' => $identity['name']];
			}
		}

		return null;
	}//end changedProperty()

	/**
	 * Whether a write changes a stored identity value.
	 *
	 * A stored value that is absent or empty may be filled in. Scalars compare
	 * as text, so `363` and `"363"` are the same key.
	 *
	 * @param mixed $stored  The stored value.
	 * @param mixed $written The written value.
	 *
	 * @return bool True when a stored value would change.
	 */
	private function changes(mixed $stored, mixed $written): bool {
		if ($stored === null || $stored === '') {
			return false;
		}

		if (is_scalar($stored) === true && is_scalar($written) === true) {
			return (string)$stored !== (string)$written;
		}

		return json_encode($stored) !== json_encode($written);
	}//end changes()
}//end class
