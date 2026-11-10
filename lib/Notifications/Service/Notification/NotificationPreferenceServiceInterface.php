<?php

/**
 * NotificationPreferenceServiceInterface: the public surface of NotificationPreferenceService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications\Service\Notification
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

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * Every public method of NotificationPreferenceService. Callers depend on this interface, not on the class.
 */
interface NotificationPreferenceServiceInterface {

	/**
	 * Resolve the EFFECTIVE preference for a `(schemaSlug, notificationKey)`
	 * pair as `schema-default ⊕ group-default ⊕ user-override`.
	 *
	 * Three layers, narrowest party last: the schema says what the rule does by
	 * default, the groups the user belongs to may change it for the team, and
	 * the user's own value always wins. Each layer may be pinned to a scope,
	 * and within a layer a scoped value beats that same party's global one, in
	 * the order {@see SCOPE_KINDS} declares.
	 *
	 * `source` names the layer that decided, which is the answer to "why am I
	 * not getting this": without it the question has three possible answers and
	 * the read offers none of them. `scope` names how narrowly that layer was
	 * pinned, and `layers` carries the full trace for a support screen.
	 *
	 * Unknown keys with no stored value at any layer resolve to the schema
	 * default without error, exactly as before.
	 *
	 * @param array<string, mixed> $schemaDefault The notification spec block from the schema (provides `enabled`/`channels`).
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param array<int, string> $scopes Candidate scopes for this dispatch, narrowest first (e.g. `['schema:zaak', 'register:7']`).
	 * @param array<int, string>|null $groupIds The user's groups; read from the group manager when null.
	 *
	 * @return array{enabled: bool, channels: array<int, string>|null, source: string, scope: string, layers: array<int, array<string, mixed>>}
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The merge's inputs: the default, the party, the scope.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function resolveEffective(
		array $schemaDefault, string $userId, string $schemaSlug, string $notificationKey, array $scopes = [], ?array $groupIds = null
	): array;

	/**
	 * Resolve the effective preference as `schema-default ⊕ group-default ⊕ user-override`.
	 *
	 * The value-object form of {@see resolveEffective()}: same layers, same answer.
	 *
	 * @param PreferenceResolution $resolution The rule's default, the user, the topic and the user's groups.
	 *
	 * @return array{enabled: bool, channels: array<int, string>|null, source: string, scope: string, layers: array<int, array<string, mixed>>}
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function resolve(PreferenceResolution $resolution): array;
}//end interface
