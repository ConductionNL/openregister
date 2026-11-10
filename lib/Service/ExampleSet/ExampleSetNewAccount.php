<?php

/**
 * A Nextcloud account an example set creates.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * The account id, its display name and e-mail address, and its first password. Never logged.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-accounts-and-groups-come-from-the-cast-and-are-never-taken-over-req-exs-003
 */
final class ExampleSetNewAccount {
	/**
	 * Constructor.
	 *
	 * @param string $uid The account id.
	 * @param string $displayName The display name.
	 * @param string $email The e-mail address; empty for none.
	 * @param string $password The first password.
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $displayName,
		public readonly string $email,
		public readonly string $password,
	) {
	}//end __construct()
}//end class
