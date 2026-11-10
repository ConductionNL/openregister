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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * The names themselves live on {@see PushChannels}; this class keeps them
 * reachable as PushEvents::OR_OBJECT and PushEvents::OR_COLLECTION.
 */
class PushEvents implements PushChannels {
}//end class
