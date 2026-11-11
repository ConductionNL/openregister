<?php

/**
 * WebhookInterceptionCacheInterface: what callers outside the Notifications component use of WebhookInterceptionCache.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Webhook
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

namespace OCA\OpenRegister\Notifications\Service\Webhook;

/**
 * Caches, per event type, whether any enabled interception webhook exists.
 */
interface WebhookInterceptionCacheInterface {

	/**
	 * Get the cached "has interception webhooks" flag for an event type.
	 *
	 * @param string $eventType Event type (e.g. 'object.creating').
	 *
	 * @return bool|null True/false when cached, null on cache miss.
	 */
	public function get(string $eventType): ?bool;

	/**
	 * Store the "has interception webhooks" flag for an event type.
	 *
	 * @param string $eventType   Event type (e.g. 'object.creating').
	 * @param bool   $hasWebhooks Whether any enabled interception webhook exists for the event type.
	 *
	 * @return void
	 */
	public function set(string $eventType, bool $hasWebhooks): void;

	/**
	 * Invalidate all cached flags; called on every webhook insert, update and delete.
	 *
	 * @return void
	 */
	public function invalidate(): void;
}//end interface
