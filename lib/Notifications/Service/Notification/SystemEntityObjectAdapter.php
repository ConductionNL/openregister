<?php

/**
 * OpenRegister SystemEntityObjectAdapter
 *
 * Wraps an OpenRegister system entity (Register, Schema, Configuration, Source,
 * Agent, Webhook) as a virtual ObjectEntity so the existing
 * AnnotationNotificationDispatcher pipeline can handle it without modification.
 *
 * The adapter populates only the fields the dispatcher reads:
 *   - schema  → the canonical system slug (e.g. 'openregister_source')
 *   - uuid    → entity's uuid
 *   - name    → entity's title/name
 *   - object  → entity's data as a plain array (for subject interpolation)
 *
 * The register and id fields are intentionally left null — the adapter is never
 * persisted, and the history mapper records a null registerId for system entities.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Db\Entity;

/**
 * Virtual ObjectEntity wrapper for system entities.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Adapter bridges two type hierarchies;
 * coupling is structural and intentional.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
 */
class SystemEntityObjectAdapter extends ObjectEntity {
	/**
	 * Build a virtual ObjectEntity from a system entity.
	 *
	 * The entity is expected to implement `jsonSerialize()` and expose `getUuid()`.
	 * Optionally `getTitle()` or `getName()` are used to populate the display name.
	 *
	 * @param Entity $entity The system entity (Source, Agent, Configuration, etc.).
	 * @param string $systemSlug The canonical system schema slug (SystemSchemaRules::SLUG_*).
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
	 */
	public function __construct(Entity $entity, string $systemSlug) {
		parent::__construct();

		// Virtual schema reference — the bridge listener resolves this slug to
		// a synthetic Schema via SystemSchemaRules::buildSchema().
		$this->schema = $systemSlug;
		$this->register = null;

		// UUID — used for the notification object reference and idempotency keys.
		$this->uuid = $this->uuidOf(entity: $entity);
		$this->name = $this->displayNameOf(entity: $entity);
		$this->object = $this->payloadOf(entity: $entity);
	}//end __construct()

	/**
	 * The entity's uuid as a string, or null.
	 *
	 * Entity magic getters are dispatched via __call, so method_exists() returns
	 * false; the getter is called directly and a missing one reads as null.
	 *
	 * @param Entity $entity The system entity.
	 *
	 * @return string|null
	 */
	private function uuidOf(Entity $entity): ?string {
		$uuid = $this->read(entity: $entity, getter: 'getUuid');
		if ($uuid === null) {
			return $this->uuid;
		}

		return (string)$uuid;
	}//end uuidOf()

	/**
	 * Display name for subject interpolation: the title wins over the name,
	 * and only a non-empty string is used.
	 *
	 * @param Entity $entity The system entity.
	 *
	 * @return string|null
	 */
	private function displayNameOf(Entity $entity): ?string {
		$displayName = ($this->read(entity: $entity, getter: 'getTitle') ?? $this->read(entity: $entity, getter: 'getName'));
		if (is_string($displayName) === true && $displayName !== '') {
			return $displayName;
		}

		return $this->name;
	}//end displayNameOf()

	/**
	 * Payload array for {{field}} template interpolation in subjects.
	 *
	 * @param Entity $entity The system entity.
	 *
	 * @return array<mixed>|null
	 */
	private function payloadOf(Entity $entity): ?array {
		if ($entity instanceof \JsonSerializable) {
			$serialized = $entity->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return $this->object;
	}//end payloadOf()

	/**
	 * Call one magic getter, or null when the entity has no such field.
	 *
	 * @param Entity $entity The system entity.
	 * @param string $getter The getter name.
	 *
	 * @return mixed
	 */
	private function read(Entity $entity, string $getter): mixed {
		try {
			return $entity->$getter();
		} catch (\Throwable) {
			return null;
		}
	}//end read()
}//end class
