<?php

/**
 * Who a notification subscription belongs to and what it covers.
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
 * The (user, register, schema) identity of one subscription row.
 *
 * @spec openspec/specs/notificatie-engine/spec.md
 */
final class SubscriptionKey {
	/**
	 * Constructor.
	 *
	 * @param string   $userId     User UID.
	 * @param int|null $registerId Register id, or null for schema-only.
	 * @param int|null $schemaId   Schema id, or null for register-wide.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly ?int $registerId,
		public readonly ?int $schemaId,
	) {
	}//end __construct()

	/**
	 * The scope columns of the row, register first.
	 *
	 * @return array{register_id: int|null, schema_id: int|null}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function scopeColumns(): array {
		return ['register_id' => $this->registerId, 'schema_id' => $this->schemaId];
	}//end scopeColumns()
}//end class
