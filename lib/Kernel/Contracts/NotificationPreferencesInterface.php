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
	 * Read a group's stored default at a preference address, or null when the group has none.
	 *
	 * @param string            $groupId The group id.
	 * @param PreferenceAddress $address The schema slug, notification key and scope; a null scope is the group's global default.
	 *
	 * @return array<string, mixed>|null The decoded default, or null when none/invalid.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function groupDefaultAt(string $groupId, PreferenceAddress $address): ?array;

	/**
	 * Read a user's stored override at a preference address. Returns null when
	 * no override is stored (fall through to the schema default), never throws
	 * for an unknown key.
	 *
	 * @param string            $userId  The user UID.
	 * @param PreferenceAddress $address The schema slug, notification key and scope; a null scope is the user's global value.
	 *
	 * @return array<string, mixed>|null The decoded override, or null when none/invalid.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function overrideAt(string $userId, PreferenceAddress $address): ?array;

	/**
	 * Write or clear a group's default; the write's party is the group id.
	 *
	 * A null value clears it, so the layer below, the schema default, applies
	 * again. The caller is responsible for having established that the writer
	 * administers this group; this method stores what it is given.
	 *
	 * @param PreferenceWrite $write The group, the address and the default (`enabled`, optional `channels`; null clears it).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 */
	public function storeGroupDefault(PreferenceWrite $write): void;

	/**
	 * Write or clear a user's override; the write's party is the user UID.
	 * A null value clears the override so the schema default applies again
	 * (zero-migration fall-through).
	 *
	 * @param PreferenceWrite $write The user, the address and the override (`enabled`, optional `channels`; null clears it).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function storeOverride(PreferenceWrite $write): void;
}//end interface
