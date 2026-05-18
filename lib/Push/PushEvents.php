<?php

/**
 * OpenRegister PushEvents — notify_push event-string constants.
 *
 * Defines the canonical event strings used by OpenRegister when pushing
 * real-time notifications to connected browser clients via the Nextcloud
 * notify_push app.
 *
 * Naming convention (mirrors OCA\Deck\NotifyPushEvents):
 *   - OR_OBJECT     → `or-object`     — base string; append `-{uuid}` to target a single object.
 *   - OR_COLLECTION → `or-collection` — base string; append `-{register-slug}-{schema-slug}` to target a collection.
 *
 * @category Push
 * @package  OCA\OpenRegister\Push
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Push;

/**
 * Constants for notify_push event strings used by OpenRegister.
 *
 * Consumers (e.g. @conduction/nextcloud-vue) subscribe to:
 *   - `or-object-{uuid}`                            — single-object updates
 *   - `or-collection-{register-slug}-{schema-slug}` — collection-level invalidation (create/delete)
 */
class PushEvents
{

    /**
     * Base event string for single-object updates.
     *
     * Append `-{uuid}` when pushing to specific listeners:
     * ```
     * $eventString = PushEvents::OR_OBJECT . '-' . $uuid;
     * ```
     *
     * Fired on every object lifecycle action (create, update, delete).
     *
     * @var string
     */
    public const OR_OBJECT = 'or-object';

    /**
     * Base event string for collection-level invalidation.
     *
     * Append `-{register-slug}-{schema-slug}` when pushing to collection listeners:
     * ```
     * $eventString = PushEvents::OR_COLLECTION . '-' . $registerSlug . '-' . $schemaSlug;
     * ```
     *
     * Fired on create and delete only (not on update, to avoid redundant
     * collection refreshes when only content changes).
     *
     * @var string
     */
    public const OR_COLLECTION = 'or-collection';

}//end class
