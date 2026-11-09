<?php

/**
 * Where one stored notification preference lives: which notification, at which scope.
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
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

/**
 * The schema slug and notification key a preference is for, and the scope it
 * is pinned to (`<kind>:<id>`), or null for the unscoped value.
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
 */
final class PreferenceAddress {

	/**
	 * Constructor.
	 *
	 * @param string      $schemaSlug      The owning schema's slug.
	 * @param string      $notificationKey The notification annotation key.
	 * @param string|null $scope           The scope, or null for the unscoped value.
	 */
	public function __construct(
		public readonly string $schemaSlug,
		public readonly string $notificationKey,
		public readonly ?string $scope = null,
	) {
	}//end __construct()

	/**
	 * The same address with an absent scope spelled `global`, as group defaults store it.
	 *
	 * @return self The address.
	 */
	public function scopedOrGlobal(): self {
		return new self(schemaSlug: $this->schemaSlug, notificationKey: $this->notificationKey, scope: ($this->scope ?? 'global'));
	}//end scopedOrGlobal()
}//end class
