<?php

/**
 * NotificationPreferencesInterface: reads and writes per-user overrides and per-group defaults of notification preferences.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reads and writes per-user overrides and per-group defaults of notification preferences.
 * Implemented by Service\Notification\NotificationPreferenceService.
 */
interface NotificationPreferencesInterface {

	/**
	 * Enumerate the EFFECTIVE notifications for a user: every notification
	 * declared by the user's accessible schemas, merged with that user's
	 * overrides, each tagged with its `source`.
	 *
	 * RBAC + multitenancy on `SchemaMapper::findAll()` already scope the
	 * schemas to those the user may read, so this never leaks notifications
	 * for inaccessible schemas.
	 *
	 * @param string $userId The user UID.
	 * @param array<int, string> $scopes Candidate scopes to answer for, narrowest first; empty for the global answer.
	 *
	 * @return array<int, array<string, mixed>> One entry per (schema, notification) pair.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function getEffectiveForUser(string $userId, array $scopes = []): array;

	/**
	 * Read a group's stored default, or null when the group has none.
	 *
	 * @param string $groupId The group id.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $scope The scope, or null for the group's global default.
	 *
	 * @return array<string, mixed>|null The decoded default, or null when none/invalid.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function getGroupDefault(
		string $groupId,
		string $schemaSlug,
		string $notificationKey,
		?string $scope = null,
	): ?array;

	/**
	 * Read a user's stored override for a `(schemaSlug, notificationKey)`
	 * pair. Returns null when no override is stored (fall through to the
	 * schema default) — never throws for an unknown key.
	 *
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param string|null $scope The scope, or null for the user's global value.
	 *
	 * @return array<string, mixed>|null The decoded override, or null when none/invalid.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function getOverride(
		string $userId,
		string $schemaSlug,
		string $notificationKey,
		?string $scope = null,
	): ?array;

	/**
	 * Write or clear a group's default.
	 *
	 * Passing null clears it, so the layer below — the schema default — applies
	 * again. The caller is responsible for having established that the writer
	 * administers this group; this method stores what it is given.
	 *
	 * @param string $groupId The group id.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param array<string, mixed>|null $default The default (`enabled`, optional `channels`) or null to clear.
	 * @param string|null $scope The scope, or null for the group's global default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function setGroupDefault(
		string $groupId,
		string $schemaSlug,
		string $notificationKey,
		?array $default,
		?string $scope = null,
	): void;

	/**
	 * Write or clear a user's override for one `(schemaSlug, notificationKey)`
	 * pair. Passing null clears the override so the schema default applies
	 * again (zero-migration fall-through).
	 *
	 * @param string $userId The user UID.
	 * @param string $schemaSlug The owning schema's slug.
	 * @param string $notificationKey The notification annotation key.
	 * @param array<string, mixed>|null $override Override body (`enabled`, optional `channels`) or null to clear.
	 * @param string|null $scope The scope, or null for the user's global value.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function setOverride(
		string $userId,
		string $schemaSlug,
		string $notificationKey,
		?array $override,
		?string $scope = null,
	): void;
}//end interface
