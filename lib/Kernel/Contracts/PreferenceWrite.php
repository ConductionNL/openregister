<?php

/**
 * A preference value to store for one party: a user's override or a group's default.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
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

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Who the value belongs to, where it is stored, and the value itself (null clears it).
 *
 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
 */
final class PreferenceWrite {

	/**
	 * Constructor.
	 *
	 * @param string                    $party   The user UID for an override, the group id for a group default.
	 * @param PreferenceAddress         $address The notification and scope the value is stored for.
	 * @param array<string, mixed>|null $value   The value (`enabled`, optional `channels`), or null to clear.
	 */
	public function __construct(
		public readonly string $party,
		public readonly PreferenceAddress $address,
		public readonly ?array $value,
	) {
	}//end __construct()
}//end class
