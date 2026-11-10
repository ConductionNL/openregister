<?php

/**
 * The pair a notification dispatch is deduplicated on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

/**
 * A notification and the idempotency key one dispatch of it resolved to.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class DispatchIdempotencyKey {
	/**
	 * Constructor.
	 *
	 * @param string $notificationSlug The notification annotation key.
	 * @param string $idempotencyKey   The resolved idempotency key.
	 */
	public function __construct(
		public readonly string $notificationSlug,
		public readonly string $idempotencyKey,
	) {
	}//end __construct()
}//end class
