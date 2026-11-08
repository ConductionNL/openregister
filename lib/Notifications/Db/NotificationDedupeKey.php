<?php

/**
 * Which dedupe row a notification rule's evaluation of one object owns.
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
 * The (schema_id, rule_key, object_uuid) identity of a dedupe state row.
 */
final readonly class NotificationDedupeKey {
	/**
	 * Constructor.
	 *
	 * @param int    $schemaId   Schema identifier.
	 * @param string $ruleKey    Notification annotation key.
	 * @param string $objectUuid Target ObjectEntity UUID.
	 */
	public function __construct(
		public int $schemaId,
		public string $ruleKey,
		public string $objectUuid,
	) {
	}//end __construct()
}//end class
