<?php

/**
 * One effective-preference resolution: the rule's default, the user, the topic and the user's groups.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * What {@see NotificationPreferenceService::resolve()} merges into one effective preference.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */
final class PreferenceResolution {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>    $schemaDefault The notification spec block from the schema (provides `enabled`/`channels`).
	 * @param string                  $userId        The user UID.
	 * @param PreferenceTopic         $topic         The notification and its candidate scopes.
	 * @param array<int, string>|null $groupIds      The user's groups; read from the group manager when null.
	 */
	public function __construct(
		public readonly array $schemaDefault,
		public readonly string $userId,
		public readonly PreferenceTopic $topic,
		public readonly ?array $groupIds = null,
	) {
	}//end __construct()
}//end class
