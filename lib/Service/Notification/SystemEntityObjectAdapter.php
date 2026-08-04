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

namespace OCA\OpenRegister\Service\Notification;

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
class SystemEntityObjectAdapter extends ObjectEntity
{
    /**
     * Build a virtual ObjectEntity from a system entity.
     *
     * The entity is expected to implement `jsonSerialize()` and expose `getUuid()`.
     * Optionally `getTitle()` or `getName()` are used to populate the display name.
     *
     * @param Entity $entity     The system entity (Source, Agent, Configuration, etc.).
     * @param string $systemSlug The canonical system schema slug (SystemSchemaRules::SLUG_*).
     *
     * @spec openspec/changes/openregister-system-notifications/tasks.md#task-3
     */
    public function __construct(Entity $entity, string $systemSlug)
    {
        parent::__construct();

        // Virtual schema reference — the bridge listener resolves this slug to
        // a synthetic Schema via SystemSchemaRules::buildSchema().
        $this->schema   = $systemSlug;
        $this->register = null;

        // UUID — used for the notification object reference and idempotency keys.
        // Entity magic getters are dispatched via __call, so method_exists() returns
        // false; call them directly and catch any BadMethodCallException.
        try {
            // @phpstan-ignore-next-line
            $uuidVal = $entity->getUuid();
            if ($uuidVal !== null) {
                $this->uuid = (string) $uuidVal;
            }
        } catch (\Throwable) {
            // GetUuid() not available — uuid stays null.
        }

        // Display name for subject interpolation (title wins over name).
        $displayName = null;
        try {
            // @phpstan-ignore-next-line
            $displayName = $entity->getTitle();
        } catch (\Throwable) {
            // GetTitle() not available — fall through.
        }

        if ($displayName === null) {
            try {
                // @phpstan-ignore-next-line
                $displayName = $entity->getName();
            } catch (\Throwable) {
                // GetName() not available — displayName stays null.
            }
        }

        if (is_string($displayName) === true && $displayName !== '') {
            $this->name = $displayName;
        }

        // Payload array for {{field}} template interpolation in subjects.
        if ($entity instanceof \JsonSerializable) {
            $serialized = $entity->jsonSerialize();
            if (is_array($serialized) === true) {
                $this->object = $serialized;
            }
        }
    }//end __construct()
}//end class
